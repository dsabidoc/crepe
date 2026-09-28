<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    /**
     * Display inventory movements and audit events in one paginated module.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $isAdministrator = $user->can('settings.manage');
        $isWarehouse = $user->can('inventory.requests.manage') && session('crepe.mode') === 'almacen';
        $modeLocation = match (session('crepe.mode')) {
            'recepcion' => 'REC',
            'color-bar' => 'CB',
            'almacen' => 'ALM',
            default => null,
        };
        $requestedLocation = $request->string('location')->upper()->toString();
        $location = $requestedLocation !== '' ? $requestedLocation : ($isAdministrator ? null : $modeLocation);

        abort_unless($isAdministrator || $location !== null, 403);
        abort_unless($location === null || in_array($location, ['ALM', 'REC', 'CB'], true), 422, 'Selecciona una ubicación válida.');
        if (! $isAdministrator) {
            abort_unless($location === $modeLocation, 403);
        }

        $locationOptions = $isAdministrator
            ? ['ALM' => 'Almacén', 'REC' => 'Recepción', 'CB' => 'Color Bar']
            : [$location => InventoryLocation::query()->where('code', $location)->value('name') ?: $location];
        $movementTypes = InventoryMovement::query()->select('type')->distinct()->orderBy('type')->pluck('type');
        $filters = [
            'movement_type' => $request->string('movement_type')->toString(),
            'from' => $request->date('from')?->toDateString(),
            'to' => $request->date('to')?->toDateString(),
            'search' => trim($request->string('search')->toString()),
        ];

        $movements = InventoryMovement::query()
            ->with(['variant.product', 'location', 'creator'])
            ->when($location, fn ($query) => $query->whereHas('location', fn ($locations) => $locations->where('code', $location)))
            ->when($filters['movement_type'], fn ($query, $type) => $query->where('type', $type))
            ->when($filters['from'], fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'], fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->when($filters['search'], function ($query, $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('reason', 'like', "%{$search}%")
                        ->orWhereHas('variant.product', fn ($products) => $products->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('variant', fn ($variants) => $variants->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
                });
            })
            ->latest('created_at')
            ->paginate(30, ['*'], 'movement_page')
            ->withQueryString();

        $audits = $this->auditQuery($isAdministrator || $isWarehouse, $filters)
            ->latest('created_at')
            ->paginate(30, ['*'], 'audit_page')
            ->withQueryString();

        return view('activity.index', compact('audits', 'filters', 'isAdministrator', 'location', 'locationOptions', 'movementTypes', 'movements'));
    }

    /**
     * Keep sensitive request-audit details limited to administrators and warehouse staff.
     */
    private function auditQuery(bool $canSeeAudit, array $filters): Builder
    {
        $query = AuditLog::query()->with(['user', 'subject'])->where('action', 'like', 'inventory.%');
        if (! $canSeeAudit) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->when($filters['from'], fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'], fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->when($filters['search'], fn ($query, $search) => $query->where(function ($nested) use ($search): void {
                $nested->where('action', 'like', "%{$search}%")->orWhere('reason', 'like', "%{$search}%");
            }));
    }
}
