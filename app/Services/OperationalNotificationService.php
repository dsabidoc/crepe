<?php

namespace App\Services;

use App\Events\NotificationsChanged;
use App\Models\AppointmentReview;
use App\Models\AttendanceIncident;
use App\Models\ColorFormula;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\PayableInvoice;
use App\Models\SalonService;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationalNotificationService
{
    public const StockLow = 'inventory.stock.low';

    public const PayablePending = 'payable.pending';

    public const AppointmentPending = 'appointment.review.pending';

    public const AttendancePending = 'attendance.incidents.pending';

    public const ColorBarPending = 'color.ticket.pending';

    public const FormulaUpdated = 'color.formula.updated';

    /** @return array<string, array<int, string>> */
    public static function permissions(): array
    {
        return [
            self::StockLow => ['inventory.view', 'inventory.requests.manage'],
            self::PayablePending => ['finance.view', 'finance.manage'],
            self::AppointmentPending => ['appointments.view', 'appointments.create', 'tickets.charge', 'mode.reception.access'],
            self::AttendancePending => ['attendance.manage'],
            self::ColorBarPending => ['mode.color-bar.access'],
            self::FormulaUpdated => ['mode.reception.access', 'tickets.view'],
        ];
    }

    /** @return array<int, string> */
    public static function allowedTypes(User $user): array
    {
        return array_keys(array_filter(self::permissions(), fn (array $permissions): bool => $user->can($permissions)));
    }

    public function stockChanged(int $locationId): void
    {
        $location = InventoryLocation::query()->find($locationId);
        if ($location?->code !== 'ALM') {
            return;
        }
        $balances = InventoryBalance::query()->where('inventory_location_id', $locationId)
            ->whereHas('variant.product', fn ($query) => $query->where('status', 'active'))
            ->whereHas('variant', fn ($query) => $query->whereColumn('inventory_balances.available_quantity', '<=', 'product_variants.minimum_stock'));
        $count = (clone $balances)->count();
        $emptyCount = (clone $balances)->where('available_quantity', '<=', 0)->count();
        $this->sync(self::StockLow, $location->id, $count > 0, [
            'module' => 'inventario', 'module_name' => 'Almacén',
            'title' => ($count === 1 ? '1 producto necesita reposición' : $count.' productos necesitan reposición'),
            'detail' => $emptyCount.' agotados · '.$location->name,
            'action' => 'Revisar inventario', 'priority' => 'warning',
            'attention' => $emptyCount > 0 ? 'empty' : 'low',
        ]);
    }

    public function invoiceChanged(PayableInvoice $invoice): void
    {
        $pending = $invoice->status !== 'paid' && (float) $invoice->amount - (float) $invoice->paid_amount >= .01;
        $due = $invoice->due_on;
        $attention = $due?->isPast() && ! $due->isToday() ? 'overdue' : ($due && $due->lessThanOrEqualTo(today()->addDay()) ? 'due' : 'new');
        $this->sync(self::PayablePending, $invoice->id, $pending, [
            'module' => 'finanzas', 'module_name' => 'Cuentas por pagar',
            'title' => match ($attention) {
                'overdue' => 'Factura vencida por revisar', 'due' => 'Factura próxima a vencer', default => 'Nueva cuenta por pagar'
            },
            'detail' => ($invoice->invoice_reference ?: 'Cuenta #'.$invoice->id).' · Saldo $'.number_format(max(0, (float) $invoice->amount - (float) $invoice->paid_amount), 2).($due ? ' · Vence '.$due->format('d/m/Y') : ''),
            'action' => 'Revisar cuenta', 'priority' => $attention === 'new' ? 'normal' : 'warning', 'attention' => $attention,
        ]);
    }

    public function appointmentReviewsChanged(string $date): void
    {
        $all = AppointmentReview::query()->whereDate('starts_at', $date);
        $subject = (int) str_replace('-', '', $date);
        $count = (clone $all)->where('status', 'pending')->count();
        $active = $date >= today()->toDateString() && $date <= today()->addDays(30)->toDateString();
        $active = $active || DatabaseNotification::query()->where('type', self::AppointmentPending)->where('subject_id', $subject)->whereNull('resolved_at')->exists();
        $this->sync(self::AppointmentPending, $subject, $active && $count > 0, [
            'module' => 'agenda', 'module_name' => 'Agenda', 'title' => ($count === 1 ? '1 reserva necesita revisión' : $count.' reservas necesitan revisión'),
            'detail' => 'Fecha: '.$date, 'action' => 'Revisar reservas', 'priority' => $date === today()->toDateString() ? 'warning' : 'normal', 'date' => $date,
        ]);
    }

    public function attendanceChanged(string $date): void
    {
        $all = AttendanceIncident::query()->whereDate('work_date', $date);
        $subject = (int) str_replace('-', '', $date);
        $count = (clone $all)->where('status', 'pending')->count();
        $this->sync(self::AttendancePending, $subject, $count > 0, [
            'module' => 'checks', 'module_name' => 'Asistencia', 'title' => ($count === 1 ? '1 incidencia por resolver' : $count.' incidencias por resolver'),
            'detail' => 'Fecha: '.$date, 'action' => 'Revisar incidencias', 'priority' => 'normal', 'date' => $date,
        ]);
    }

    public function colorBarTicketChanged(Ticket $ticket): void
    {
        $previouslyNotified = DatabaseNotification::query()->where('type', self::ColorBarPending)->where('subject_id', $ticket->id)->whereNull('resolved_at')->exists();
        $active = in_array($ticket->status, ['open', 'in_service'], true)
            && ($previouslyNotified || $ticket->opened_at?->between(today()->subDays(7), today()->endOfDay()));
        $missing = 0;
        if ($active) {
            $items = $ticket->items()->where('type', 'service')->where('status', 'active')->with('appointmentService')->get();
            $serviceIds = $items->map(fn ($item) => $item->metadata['salon_service_id'] ?? $item->appointmentService?->salon_service_id)->filter();
            $required = SalonService::query()->whereIn('id', $serviceIds)->where('requires_color_bar', true)->pluck('id');
            $formulas = $ticket->colorFormulas()->where('status', 'confirmed')->get(['ticket_item_id']);
            if (! $formulas->contains(fn ($formula): bool => $formula->ticket_item_id === null)) {
                $missing = $items->filter(fn ($item): bool => $required->contains($item->metadata['salon_service_id'] ?? $item->appointmentService?->salon_service_id) && ! $formulas->contains('ticket_item_id', $item->id))->count();
            }
        }
        $this->sync(self::ColorBarPending, $ticket->id, $active && $missing > 0, [
            'module' => 'color-bar', 'module_name' => 'Color Bar', 'title' => 'Ticket '.$ticket->code.' requiere Color Bar',
            'detail' => $missing.' servicios sin fórmula', 'action' => 'Abrir ticket', 'priority' => 'normal',
        ]);
    }

    public function formulaRecorded(ColorFormula $formula): void
    {
        $ticket = $formula->ticket()->firstOrFail();
        $this->colorBarTicketChanged($ticket);
        $this->sync(self::FormulaUpdated, $formula->id, true, [
            'module' => 'color-bar', 'module_name' => 'Color Bar', 'title' => 'Color Bar actualizó el ticket '.$ticket->code,
            'detail' => $formula->service_name_snapshot ?: 'Consumo registrado', 'action' => 'Revisar ticket', 'priority' => 'normal', 'ticket_id' => $ticket->id,
        ], actionable: false);
    }

    public function refresh(): void
    {
        InventoryLocation::query()->where('code', 'ALM')->each(fn ($location) => $this->stockChanged($location->id));
        PayableInvoice::query()->where('status', '!=', 'paid')->chunkById(100, function ($invoices): void {
            foreach ($invoices as $invoice) {
                $this->invoiceChanged($invoice);
            }
        });
        $dates = AppointmentReview::query()->where('starts_at', '>=', today())->where('starts_at', '<', today()->addDays(31))->pluck('starts_at')->map(fn ($date): string => $date->toDateString())->unique();
        foreach ($dates as $date) {
            $this->appointmentReviewsChanged($date);
        }
        foreach (DatabaseNotification::query()->where('type', self::AppointmentPending)->whereNull('resolved_at')->get(['data'])->pluck('data.date')->unique() as $date) {
            $this->appointmentReviewsChanged($date);
        }
        foreach (AttendanceIncident::query()->where('status', 'pending')->pluck('work_date')->map(fn ($date): string => $date->toDateString())->unique() as $date) {
            $this->attendanceChanged($date);
        }
        Ticket::query()->whereIn('status', ['open', 'in_service'])->where(fn ($query) => $query->whereBetween('opened_at', [today()->subDays(7), today()->endOfDay()])->orWhereIn('id', DatabaseNotification::query()->select('subject_id')->where('type', self::ColorBarPending)->whereNull('resolved_at')))->chunkById(100, function ($tickets): void {
            foreach ($tickets as $ticket) {
                $this->colorBarTicketChanged($ticket);
            }
        });
        $this->reconcileResolvedSubjects();
    }

    private function reconcileResolvedSubjects(): void
    {
        DatabaseNotification::query()->whereNull('resolved_at')->whereIn('type', array_keys(self::permissions()))->select(['type', 'subject_id', 'data'])->distinct()->get()->each(function ($notification): void {
            $active = match ($notification->type) {
                self::PayablePending => PayableInvoice::query()->whereKey($notification->subject_id)->where('status', '!=', 'paid')->exists(),
                self::AppointmentPending => AppointmentReview::query()->whereDate('starts_at', $notification->data['date'])->where('status', 'pending')->exists(),
                self::AttendancePending => AttendanceIncident::query()->whereDate('work_date', $notification->data['date'])->where('status', 'pending')->exists(),
                self::ColorBarPending => Ticket::query()->whereKey($notification->subject_id)->whereIn('status', ['open', 'in_service'])->exists(),
                default => true,
            };
            if (in_array($notification->type, [self::AppointmentPending, self::AttendancePending], true)
                && (int) $notification->subject_id !== (int) str_replace('-', '', $notification->data['date'])) {
                $active = false;
            }
            if (! $active) {
                $this->resolve($notification->type, $notification->subject_id);
            }
        });
    }

    public function maintainHistory(): void
    {
        $cutoff = now()->subHours(config('notifications.reminder_hours'));
        DatabaseNotification::query()->with('notifiable')->whereNull('resolved_at')->whereNotNull('read_at')->where('read_at', '<=', $cutoff)->where(fn ($query) => $query->whereNull('reminded_at')->orWhere('reminded_at', '<=', $cutoff))->chunkById(100, function ($notifications): void {
            foreach ($notifications as $notification) {
                $recipient = $notification->notifiable;
                if ($recipient instanceof User && $recipient->is_active) {
                    $notification->update(['read_at' => null, 'reminded_at' => now()]);
                    NotificationsChanged::dispatch($recipient->id, $notification->id);
                }
            }
        }, column: 'id');
        DatabaseNotification::query()->whereNotNull('resolved_at')->whereNotNull('read_at')->where('resolved_at', '<', now()->subDays(config('notifications.retention_days')))->delete();
    }

    /** @param array<string, mixed> $data */
    private function sync(string $type, int $subjectId, bool $active, array $data, bool $actionable = true): void
    {
        if (! $active) {
            $this->resolve($type, $subjectId);

            return;
        }
        $required = self::permissions()[$type];
        $recipients = User::query()->where('is_active', true)->with(['roles.permissions', 'permissions'])->orderBy('id')->get();
        foreach ($recipients as $recipient) {
            if (! $recipient->can($required)) {
                continue;
            }
            DB::transaction(function () use ($recipient, $type, $subjectId, $data, $actionable): void {
                User::query()->whereKey($recipient->id)->lockForUpdate()->firstOrFail();
                $query = $recipient->notifications()->where('type', $type)->where('subject_id', $subjectId);
                $latest = (clone $query)->reorder()->orderByDesc('occurrence')->first();
                if ($latest && ($latest->resolved_at === null || ! $actionable)) {
                    $escalated = $actionable && isset($data['attention']) && $data['attention'] !== ($latest->data['attention'] ?? null);
                    $latest->fill(['data' => $data]);
                    if ($escalated) {
                        $latest->read_at = null;
                    }
                    if ($latest->isDirty()) {
                        $latest->save();
                        NotificationsChanged::dispatch($recipient->id, $escalated ? $latest->id : null);
                    }

                    return;
                }
                $notification = $recipient->notifications()->create([
                    'id' => (string) Str::uuid(), 'type' => $type, 'subject_id' => $subjectId,
                    'occurrence' => ($latest?->occurrence ?? 0) + 1, 'data' => $data,
                    'resolved_at' => $actionable ? null : now(),
                ]);
                NotificationsChanged::dispatch($recipient->id, $notification->id);
            }, attempts: 3);
        }
    }

    private function resolve(string $type, int $subjectId): void
    {
        DatabaseNotification::query()->where('type', $type)->where('subject_id', $subjectId)->whereNull('resolved_at')->get()->each(function (DatabaseNotification $notification): void {
            $notification->update(['resolved_at' => now(), 'data' => [...$notification->data, 'title' => 'Atendido · '.$notification->data['title'], 'action' => 'Ver detalle', 'priority' => 'normal']]);
            NotificationsChanged::dispatch((int) $notification->notifiable_id);
        });
    }
}
