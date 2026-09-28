<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function move(int $variantId, int $locationId, float $quantity, string $type, int $actorId, ?string $referenceType = null, ?int $referenceId = null, ?string $reason = null): InventoryMovement
    {
        return DB::transaction(function () use ($variantId, $locationId, $quantity, $type, $actorId, $referenceType, $referenceId, $reason): InventoryMovement {
            $balance = InventoryBalance::query()->where('product_variant_id', $variantId)->where('inventory_location_id', $locationId)->lockForUpdate()->first();
            if (! $balance) {
                $balance = InventoryBalance::create(['product_variant_id' => $variantId, 'inventory_location_id' => $locationId, 'available_quantity' => 0]);
                $balance = InventoryBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
            }
            $before = (float) $balance->available_quantity;
            $after = $before + $quantity;
            if ($after < 0) {
                throw ValidationException::withMessages(['quantity' => 'No hay inventario suficiente para registrar este consumo.']);
            }
            $balance->update(['available_quantity' => $after]);

            return InventoryMovement::create(['uuid' => (string) Str::uuid(), 'product_variant_id' => $variantId, 'inventory_location_id' => $locationId, 'type' => $type, 'quantity' => $quantity, 'before_quantity' => $before, 'after_quantity' => $after, 'reference_type' => $referenceType, 'reference_id' => $referenceId, 'reason' => $reason, 'created_by' => $actorId]);
        });
    }
}
