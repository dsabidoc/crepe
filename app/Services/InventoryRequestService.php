<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryRequest;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryRequestService
{
    public function __construct(private WorkflowNotificationService $notifications) {}

    public function canCancel(InventoryRequest $inventoryRequest, User $actor, ?string $mode): bool
    {
        if (! $actor->can('inventory.view')) {
            return false;
        }
        if ($mode === 'almacen') {
            return $actor->can('mode.almacen.access') && $actor->can('inventory.requests.manage');
        }
        $location = match ($mode) {
            'recepcion' => 'REC',
            'color-bar' => 'CB',
            default => null,
        };

        return $location !== null
            && $actor->can($mode === 'recepcion' ? 'mode.reception.access' : 'mode.color-bar.access')
            && $inventoryRequest->requested_by === $actor->id
            && $inventoryRequest->sourceLocation->code === $location;
    }

    public function cancel(InventoryRequest $inventoryRequest, User $actor, ?string $mode, string $reason): void
    {
        DB::transaction(function () use ($inventoryRequest, $actor, $mode, $reason): void {
            $lockedRequest = InventoryRequest::query()->with('sourceLocation')->lockForUpdate()->findOrFail($inventoryRequest->id);
            abort_unless($this->canCancel($lockedRequest, $actor, $mode), 403);
            if ($lockedRequest->status === 'cancelled') {
                return;
            }
            abort_unless($lockedRequest->status === 'pending', 422, 'Sólo se pueden eliminar solicitudes pendientes. Una entrega registrada requiere una reversión.');
            $hasMovements = InventoryMovement::query()->where('reference_type', InventoryRequest::class)->where('reference_id', $lockedRequest->id)->exists();
            $hasDelivery = $lockedRequest->items()->where(fn ($items) => $items->where('delivered_quantity', '>', 0)->orWhere('delivered_units', '>', 0))->exists();
            abort_if($hasMovements || $hasDelivery, 422, 'Esta solicitud ya tiene movimientos o unidades entregadas. Revisa la entrega antes de modificarla.');
            $lockedRequest->update(['status' => 'cancelled', 'processed_by' => $actor->id, 'processed_at' => now(), 'closure_notes' => $reason]);
            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'inventory.request.cancelled',
                'subject_type' => InventoryRequest::class,
                'subject_id' => $lockedRequest->id,
                'before' => ['status' => 'pending'],
                'after' => ['code' => $lockedRequest->code, 'source' => $lockedRequest->sourceLocation->code, 'status' => 'cancelled'],
                'reason' => $reason,
            ]);
            $this->notifications->inventoryRequestCancelled($lockedRequest);
        }, 3);
    }

    /**
     * @param  Collection<int, ProductVariant>  $variants
     * @param  array<int, array{product_variant_id: int, requested_units: ?int, requested_quantity: ?float}>  $items
     */
    public function create(InventoryLocation $source, User $actor, Collection $variants, array $items, ?string $notes): InventoryRequest
    {
        return DB::transaction(function () use ($items, $notes, $actor, $source, $variants): InventoryRequest {
            $inventoryRequest = InventoryRequest::create([
                'code' => 'TEMP-'.str()->uuid(),
                'source_location_id' => $source->id,
                'status' => 'pending',
                'requested_by' => $actor->id,
                'notes' => $notes,
            ]);
            $inventoryRequest->update(['code' => 'SOL-'.str_pad((string) $inventoryRequest->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($items as $item) {
                $variant = $variants[(int) $item['product_variant_id']];
                $inventoryRequest->items()->create([
                    'product_variant_id' => $variant->id,
                    'requested_quantity' => $item['requested_units'] ?? $item['requested_quantity'],
                    'requested_units' => $item['requested_units'] ?? (int) $item['requested_quantity'],
                    'unit' => 'unidad',
                ]);
            }

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'inventory.request.created',
                'subject_type' => InventoryRequest::class,
                'subject_id' => $inventoryRequest->id,
                'after' => ['code' => $inventoryRequest->code, 'source' => $source->code, 'status' => 'pending'],
                'reason' => 'Solicitud de inventario',
            ]);

            $this->notifications->inventoryRequested($inventoryRequest);

            return $inventoryRequest;
        });
    }
}
