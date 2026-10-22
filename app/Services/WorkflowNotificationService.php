<?php

namespace App\Services;

use App\Events\NotificationsChanged;
use App\Models\CashSession;
use App\Models\InventoryRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class WorkflowNotificationService
{
    public const CashPending = 'cash.cut.pending';

    public const CashVerified = 'cash.cut.verified';

    public const RequestPending = 'inventory.request.pending';

    public const RequestClosed = 'inventory.request.closed';

    public function cashCutSubmitted(CashSession $session): void
    {
        $session->loadMissing('register');
        $hasDifference = abs((float) $session->difference) >= .01;
        $data = [
            'module' => 'caja',
            'module_name' => 'Caja',
            'title' => 'Revisa el corte de '.$session->register->name,
            'detail' => ($session->business_date ?? $session->opened_at)->format('d/m/Y').($hasDifference ? ' · Diferencia: $'.number_format((float) $session->difference, 2) : ' · Sin diferencias'),
            'action' => 'Revisar corte',
            'priority' => $hasDifference ? 'warning' : 'normal',
        ];

        foreach (User::permission(['cash.authorize'])->where('is_active', true)->get() as $recipient) {
            if ($recipient->can('cash.open')) {
                $this->store($recipient, self::CashPending, $session->id, $data);
            }
        }
    }

    public function cashCutVerified(CashSession $session): void
    {
        $session->loadMissing('register.assignedEmployee.user');
        $this->resolve(self::CashPending, $session->id, 'Corte verificado · '.$session->register->name, 'Ver corte');
        $recipient = $session->register->assignedEmployee?->user;
        if ($recipient?->is_active && $recipient->can('cash.open') && $recipient->id !== $session->verified_by) {
            $session->loadMissing('register');
            $this->store($recipient, self::CashVerified, $session->id, [
                'module' => 'caja',
                'module_name' => 'Caja',
                'title' => 'Tu corte fue verificado',
                'detail' => $session->register->name.' · '.($session->business_date ?? $session->opened_at)->format('d/m/Y'),
                'action' => 'Ver corte',
                'priority' => 'normal',
            ], actionable: false);
        }
    }

    public function inventoryRequested(InventoryRequest $inventoryRequest): void
    {
        $inventoryRequest->loadMissing('sourceLocation');
        $itemCount = $inventoryRequest->items()->count();
        $recipients = User::query()->where('is_active', true)->with(['roles.permissions', 'permissions'])->get();

        foreach ($recipients as $recipient) {
            if ($recipient->can(['inventory.view', 'inventory.requests.manage'])) {
                $this->store($recipient, self::RequestPending, $inventoryRequest->id, [
                    'module' => 'inventario',
                    'module_name' => 'Almacén',
                    'title' => $inventoryRequest->sourceLocation->name.' solicita productos',
                    'detail' => $inventoryRequest->code.' · '.$itemCount.' productos',
                    'action' => 'Atender solicitud',
                    'priority' => 'normal',
                ]);
            }
        }
    }

    public function inventoryRequestClosed(InventoryRequest $inventoryRequest): void
    {
        $inventoryRequest->loadMissing(['requester', 'sourceLocation']);
        $this->resolve(self::RequestPending, $inventoryRequest->id, 'Solicitud de '.$inventoryRequest->sourceLocation->name.' atendida', 'Ver solicitud');
        $recipient = $inventoryRequest->requester;
        $location = $inventoryRequest->sourceLocation->code;
        if (! $recipient?->is_active || ! $this->canViewLocation($recipient, $location)) {
            return;
        }

        $items = $inventoryRequest->items()->get();
        $hasDelivery = $items->contains(fn ($item): bool => (float) $item->delivered_quantity > 0);
        $hasMissingItems = $items->contains(fn ($item): bool => (float) $item->delivered_quantity < (float) $item->requested_quantity);
        $title = ! $hasDelivery ? 'Solicitud cerrada sin entrega' : ($hasMissingItems ? 'Solicitud con entrega parcial' : 'Tu solicitud fue surtida');
        $this->store($recipient, self::RequestClosed, $inventoryRequest->id, [
            'module' => 'inventario',
            'module_name' => $inventoryRequest->sourceLocation->name,
            'location' => $location,
            'title' => $title,
            'detail' => $inventoryRequest->code.($hasMissingItems ? ' · Revisa los productos faltantes' : ' · Entrega completa'),
            'action' => 'Ver entrega',
            'priority' => $hasMissingItems ? 'warning' : 'normal',
        ], actionable: false);
    }

    public function visibleTo(User $user): MorphMany
    {
        $types = OperationalNotificationService::allowedTypes($user);
        if ($user->can('cash.open')) {
            if ($user->can('cash.authorize')) {
                $types[] = self::CashPending;
            }
        }
        if ($user->can('inventory.view') && $user->can('inventory.requests.manage')) {
            $types[] = self::RequestPending;
        }
        $locations = collect(['REC', 'CB'])->filter(fn (string $location): bool => $this->canViewLocation($user, $location))->all();

        return $user->notifications()->where(function (Builder $query) use ($user, $types, $locations): void {
            $query->whereIn('type', $types);
            if ($user->can('cash.open')) {
                $query->orWhere(function (Builder $receipts) use ($user): void {
                    $receipts->where('type', self::CashVerified);
                    if (! $user->can('cash.authorize')) {
                        $receipts->whereIn('subject_id', CashSession::query()->select('id')->whereHas('register', fn (Builder $registers): Builder => $registers->assignedTo($user->id)));
                    }
                });
            }
            if ($locations !== []) {
                $query->orWhere(fn (Builder $closed) => $closed->where('type', self::RequestClosed)->whereIn('data->location', $locations));
            }
        });
    }

    public function canViewLocation(User $user, string $location): bool
    {
        return $user->can('inventory.view') && match ($location) {
            'REC' => $user->can('mode.reception.access'),
            'CB' => $user->can('mode.color-bar.access'),
            default => false,
        };
    }

    /** @param array{module: string, module_name: string, title: string, detail: string, action: string, priority: string, location?: string} $data */
    private function store(User $recipient, string $type, int $subjectId, array $data, bool $actionable = true): void
    {
        $notification = $recipient->notifications()->firstOrCreate(
            ['type' => $type, 'subject_id' => $subjectId],
            ['id' => (string) Str::uuid(), 'data' => $data, 'resolved_at' => $actionable ? null : now()],
        );

        if ($notification->wasRecentlyCreated) {
            NotificationsChanged::dispatch($recipient->id, $notification->id);
        }
    }

    private function resolve(string $type, int $subjectId, string $title, string $action): void
    {
        $notifications = DatabaseNotification::query()->where('type', $type)->where('subject_id', $subjectId)->whereNull('resolved_at')->get();
        foreach ($notifications as $notification) {
            $notification->update([
                'resolved_at' => now(),
                'data' => [...$notification->data, 'title' => $title, 'action' => $action, 'priority' => 'normal'],
            ]);
            NotificationsChanged::dispatch((int) $notification->notifiable_id);
        }
    }
}
