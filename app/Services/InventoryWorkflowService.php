<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryAdjustmentBatch;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryRequest;
use App\Models\InventoryRequestReversal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryWorkflowService
{
    public function __construct(private InventoryService $inventory, private InventoryLimitService $limits) {}

    /** @param list<array{id: int, before: string, after: int, minimum_stock: int, maximum_stock: ?int, limits_before: array}> $changes */
    public function adjust(array $changes, User $actor, string $reason, string $key): InventoryAdjustmentBatch
    {
        abort_unless($actor->hasRole('Administrador'), 403);

        return DB::transaction(function () use ($changes, $actor, $reason, $key): InventoryAdjustmentBatch {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$changes, $reason], JSON_THROW_ON_ERROR));
            $existing = InventoryAdjustmentBatch::query()->where('request_key', $key)->first();
            if ($existing) {
                abort_unless($existing->created_by === $actor->id && hash_equals($existing->payload_hash, $hash), 409);

                return $existing;
            }
            $balances = InventoryBalance::query()->with(['variant', 'location'])->whereIn('id', array_column($changes, 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($changes as $change) {
                $balance = $balances->get($change['id']);
                if (! $balance || (string) $balance->available_quantity !== $change['before'] || InventoryLimitService::values($balance, $balance->variant) !== $change['limits_before']) {
                    throw ValidationException::withMessages(['changes' => 'Una existencia o sus límites cambiaron durante la revisión. Actualiza y revisa nuevamente. No se aplicó ningún ajuste.']);
                }
                if ($change['after'] < 0 || ($change['maximum_stock'] !== null && $change['maximum_stock'] < $change['minimum_stock'] && ((float) $change['minimum_stock'] !== (float) $change['limits_before']['minimum_stock'] || (float) $change['maximum_stock'] !== (float) $change['limits_before']['maximum_stock']))) {
                    throw ValidationException::withMessages(['changes' => 'Revisa cantidades y límites antes de confirmar.']);
                }
            }
            $batch = InventoryAdjustmentBatch::query()->create(['request_key' => $key, 'created_by' => $actor->id, 'payload_hash' => $hash, 'reason' => $reason, 'changes' => $changes]);
            foreach ($changes as $change) {
                $balance = $balances[$change['id']];
                $newLimits = ['minimum_stock' => $change['minimum_stock'], 'maximum_stock' => $change['maximum_stock']];
                $oldLimits = $change['limits_before'];
                if ((float) $newLimits['minimum_stock'] !== (float) $oldLimits['minimum_stock'] || $newLimits['maximum_stock'] !== null && (float) $newLimits['maximum_stock'] !== (float) $oldLimits['maximum_stock'] || ($newLimits['maximum_stock'] === null) !== ($oldLimits['maximum_stock'] === null)) {
                    $this->limits->update($balance->variant, $balance->location, $newLimits);
                }
                $delta = $change['after'] - (float) $balance->available_quantity;
                if ($delta !== 0.0) {
                    $this->inventory->move($balance->product_variant_id, $balance->inventory_location_id, $delta, 'adjustment', $actor->id, InventoryAdjustmentBatch::class, $batch->id, $reason);
                }
            }
            AuditLog::query()->create(['user_id' => $actor->id, 'action' => 'inventory.batch.adjusted', 'subject_type' => InventoryAdjustmentBatch::class, 'subject_id' => $batch->id, 'before' => ['changes' => $changes], 'after' => ['batch' => $batch->id], 'reason' => $reason]);

            return $batch;
        });
    }

    public function reverse(InventoryRequest $request, User $actor, string $reason, string $key): InventoryRequestReversal
    {
        abort_unless($actor->hasRole('Administrador'), 403);

        return DB::transaction(function () use ($request, $actor, $reason, $key): InventoryRequestReversal {
            $locked = InventoryRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->code === 'SOL-000020' || $locked->reversal_hold_reason) {
                throw ValidationException::withMessages(['reversal' => 'Esta solicitud está protegida hasta confirmar su entrega física.']);
            }
            $existing = InventoryRequestReversal::query()->where('inventory_request_id', $locked->id)->first();
            if ($existing) {
                return $existing;
            }
            if ($locked->status !== 'closed') {
                throw ValidationException::withMessages(['reversal' => 'Sólo se puede revertir una entrega cerrada.']);
            }
            $movements = InventoryMovement::query()->where('reference_type', InventoryRequest::class)->where('reference_id', $locked->id)->whereIn('type', ['transfer_in', 'transfer_out'])->orderBy('product_variant_id')->orderByDesc('quantity')->get();
            if ($movements->isEmpty() || $movements->groupBy('product_variant_id')->contains(fn ($items): bool => $items->count() !== 2 || (float) $items->sum('quantity') !== 0.0)) {
                throw ValidationException::withMessages(['reversal' => 'La entrega requiere revisión de sus movimientos antes de poder revertirse.']);
            }
            $warehouse = InventoryLocation::query()->where('code', 'ALM')->firstOrFail();
            foreach ($movements->groupBy('product_variant_id') as $variantMovements) {
                $out = $variantMovements->firstWhere('type', 'transfer_out');
                $in = $variantMovements->firstWhere('type', 'transfer_in');
                if (! $out || ! $in || $out->inventory_location_id !== $warehouse->id || $in->inventory_location_id !== $locked->source_location_id || $in->quantity <= 0) {
                    throw ValidationException::withMessages(['reversal' => 'Los movimientos no coinciden con la entrega. Solicita una revisión.']);
                }
            }
            $balances = InventoryBalance::query()->whereIn('product_variant_id', $movements->pluck('product_variant_id'))->whereIn('inventory_location_id', [$warehouse->id, $locked->source_location_id])->orderBy('product_variant_id')->orderBy('inventory_location_id')->lockForUpdate()->get();
            foreach ($movements->where('type', 'transfer_in') as $movement) {
                $balance = $balances->first(fn ($balance): bool => $balance->product_variant_id === $movement->product_variant_id && $balance->inventory_location_id === $movement->inventory_location_id);
                if (! $balance || (float) $balance->available_quantity < (float) $movement->quantity) {
                    throw ValidationException::withMessages(['reversal' => 'La ubicación destino ya no tiene todas las unidades entregadas. No se revirtió ningún producto.']);
                }
            }
            $reversal = InventoryRequestReversal::query()->create(['inventory_request_id' => $locked->id, 'request_key' => $key, 'created_by' => $actor->id, 'reason' => $reason, 'movements' => []]);
            $ids = [];
            foreach ($movements as $movement) {
                $inverse = $this->inventory->move($movement->product_variant_id, $movement->inventory_location_id, -(float) $movement->quantity, $movement->type === 'transfer_in' ? 'request_reversal_out' : 'request_reversal_in', $actor->id, InventoryRequestReversal::class, $reversal->id, 'Reversión '.$locked->code.': '.$reason);
                $inverse->update(['reverses_movement_id' => $movement->id]);
                $ids[] = $inverse->id;
            }
            $reversal->update(['movements' => $ids]);
            AuditLog::query()->create(['user_id' => $actor->id, 'action' => 'inventory.request.reversed', 'subject_type' => InventoryRequest::class, 'subject_id' => $locked->id, 'before' => ['movement_ids' => $movements->pluck('id')->all()], 'after' => ['reversal_id' => $reversal->id, 'movement_ids' => $ids], 'reason' => $reason]);

            return $reversal;
        });
    }
}
