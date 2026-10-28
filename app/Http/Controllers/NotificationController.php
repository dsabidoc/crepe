<?php

namespace App\Http\Controllers;

use App\Events\NotificationsChanged;
use App\Models\CashSession;
use App\Models\ColorFormula;
use App\Models\InventoryLocation;
use App\Models\InventoryRequest;
use App\Models\PayableInvoice;
use App\Models\Ticket;
use App\Services\OperationalNotificationService;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function index(Request $request, WorkflowNotificationService $notifications): JsonResponse
    {
        abort_unless($request->user()->is_active, 403);
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(['unread', 'pending', 'history'])],
            'module' => ['nullable', Rule::in(['all', 'caja', 'inventario', 'color-bar', 'finanzas', 'agenda', 'checks'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $tab = $data['tab'] ?? 'unread';
        $module = $data['module'] ?? 'all';
        $base = $notifications->visibleTo($request->user());
        $unreadCount = (clone $base)->whereNull('read_at')->count();
        $pendingCount = (clone $base)->whereNull('resolved_at')->count();
        $rows = $base
            ->when($module !== 'all', fn ($query) => $query->where('data->module', $module))
            ->when($tab === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($tab === 'pending', fn ($query) => $query->whereNull('resolved_at'))
            ->when($tab === 'history', fn ($query) => $query->where(fn ($recent) => $recent->where('created_at', '>=', now()->subDays(config('notifications.history_days')))->orWhereNull('resolved_at')->orWhereNull('read_at')))
            ->reorder()->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(12)->withQueryString();

        return response()->json([
            'unread_count' => $unreadCount,
            'pending_count' => $pendingCount,
            'items' => $rows->getCollection()->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'module' => $notification->data['module'],
                'module_name' => $notification->data['module_name'],
                'title' => $notification->data['title'],
                'detail' => $notification->data['detail'],
                'action' => $notification->data['action'],
                'priority' => $notification->data['priority'],
                'read' => $notification->read(),
                'pending' => $notification->resolved_at === null,
                'created_at' => $notification->created_at->toIso8601String(),
                'time' => $notification->created_at->locale('es')->diffForHumans(),
                'open_url' => route('notifications.open', $notification->id),
            ]),
            'next_page_url' => $rows->nextPageUrl(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function open(Request $request, string $notification, WorkflowNotificationService $notifications): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()->is_active, 403);
        $destination = DB::transaction(function () use ($request, $notification, $notifications): string {
            $record = $notifications->visibleTo($request->user())->lockForUpdate()->findOrFail($notification);
            $destination = $this->destination($request, $record);
            if ($record->unread()) {
                $record->markAsRead();
                NotificationsChanged::dispatch($request->user()->id);
            }

            return $destination;
        });

        return $request->expectsJson() ? response()->json(['url' => $destination]) : redirect()->to($destination);
    }

    private function destination(Request $request, DatabaseNotification $notification): string
    {
        if (isset(OperationalNotificationService::permissions()[$notification->type])) {
            return $this->operationalDestination($request, $notification);
        }
        if (in_array($notification->type, [WorkflowNotificationService::CashPending, WorkflowNotificationService::CashVerified], true)) {
            $session = CashSession::query()->findOrFail($notification->subject_id);
            abort_unless($request->user()->can('cash.open') && ($request->user()->can('cash.authorize') || $session->register()->assignedTo($request->user()->id)->exists()), 403);
            if ($request->user()->can('mode.administration.access')) {
                $request->session()->put('crepe.mode', 'administracion');
            } elseif ($request->user()->can('mode.reception.access')) {
                $request->session()->put('crepe.mode', 'recepcion');
            }

            return route('cash.index', ['session' => $session->id]).'#cut-detail-'.$session->id;
        }

        $inventoryRequest = InventoryRequest::query()->with('sourceLocation')->findOrFail($notification->subject_id);
        $canProcess = $request->user()->can('inventory.requests.manage');
        abort_unless($request->user()->can('inventory.view') && ($canProcess || $inventoryRequest->requested_by === $request->user()->id), 403);
        $activeMode = $request->session()->get('crepe.mode');
        $destinationMode = $inventoryRequest->sourceLocation->code === 'CB' ? 'color-bar' : 'recepcion';
        if ($activeMode === 'almacen' && $canProcess && $request->user()->can('mode.almacen.access')) {
            return route($inventoryRequest->status === 'pending' ? 'inventory.requests.edit' : 'inventory.requests.show', $inventoryRequest);
        }
        if ($activeMode === $destinationMode && app(WorkflowNotificationService::class)->canViewLocation($request->user(), $inventoryRequest->sourceLocation->code)) {
            return route('inventory.requests.show', $inventoryRequest);
        }
        $request->session()->flash('mode_notice', 'Selecciona el módulo correspondiente para consultar esta solicitud. Almacén atiende las entregas.');

        return route('modes.select');
    }

    private function operationalDestination(Request $request, DatabaseNotification $notification): string
    {
        if ($notification->type === OperationalNotificationService::StockLow && $request->session()->get('crepe.mode') !== 'almacen') {
            $request->session()->flash('mode_notice', 'Selecciona Almacén para revisar las existencias y el reabasto a proveedores.');

            return route('modes.select');
        }
        $mode = match ($notification->type) {
            OperationalNotificationService::StockLow => ['almacen', 'mode.almacen.access'],
            OperationalNotificationService::PayablePending => ['finanzas', 'finance.view'],
            OperationalNotificationService::ColorBarPending => ['color-bar', 'mode.color-bar.access'],
            OperationalNotificationService::AttendancePending => ['checks', 'mode.checks.access'],
            default => ['recepcion', 'mode.reception.access'],
        };
        if ($request->user()->can($mode[1])) {
            $request->session()->put('crepe.mode', $mode[0]);
        }

        return match ($notification->type) {
            OperationalNotificationService::StockLow => route('inventory.index', ['location' => InventoryLocation::query()->findOrFail($notification->subject_id)->code, 'low_stock' => 1]),
            OperationalNotificationService::PayablePending => route('payables.show', PayableInvoice::query()->findOrFail($notification->subject_id)),
            OperationalNotificationService::AppointmentPending => route('appointment-reviews.index', ['date' => $notification->data['date']]),
            OperationalNotificationService::AttendancePending => route('attendance.incidents.index', ['from' => $notification->data['date'], 'to' => $notification->data['date']]),
            OperationalNotificationService::ColorBarPending => route('color-bar.index', ['ticket' => Ticket::query()->findOrFail($notification->subject_id)->id]),
            OperationalNotificationService::FormulaUpdated => route('tickets.show', ColorFormula::query()->findOrFail($notification->subject_id)->ticket_id),
        };
    }
}
