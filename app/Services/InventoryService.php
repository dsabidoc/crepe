<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
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

            $this->addToReplenishmentOrder($variantId, $locationId, $after);

            return InventoryMovement::create(['uuid' => (string) Str::uuid(), 'product_variant_id' => $variantId, 'inventory_location_id' => $locationId, 'type' => $type, 'quantity' => $quantity, 'before_quantity' => $before, 'after_quantity' => $after, 'reference_type' => $referenceType, 'reference_id' => $referenceId, 'reason' => $reason, 'created_by' => $actorId]);
        });
    }

    private function addToReplenishmentOrder(int $variantId, int $locationId, float $availableQuantity): void
    {
        $location = InventoryLocation::query()->find($locationId);
        if ($location?->code !== 'ALM') {
            return;
        }

        $variant = ProductVariant::query()->with('product')->find($variantId);
        $supplierId = $variant?->product?->preferred_supplier_id;
        if ($variant === null || $supplierId === null || $availableQuantity > (float) $variant->minimum_stock) {
            return;
        }

        $alreadyRequested = PurchaseOrder::query()->where('supplier_id', $supplierId)->where('status', 'draft')->whereHas('items', fn ($query) => $query->where('product_variant_id', $variantId))->exists();
        if ($alreadyRequested) {
            return;
        }

        $order = PurchaseOrder::query()->firstOrCreate(
            ['supplier_id' => $supplierId, 'status' => 'draft'],
            ['code' => 'OC-TEMP-'.Str::uuid(), 'notes' => 'Reabasto automático por mínimo de inventario.'],
        );
        if (str_starts_with($order->code, 'OC-TEMP-')) {
            $order->update(['code' => 'OC-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)]);
        }
        $targetQuantity = max((float) $variant->maximum_stock, (float) $variant->minimum_stock);
        $order->items()->create(['product_variant_id' => $variant->id, 'ordered_quantity' => max($targetQuantity - $availableQuantity, 0.001), 'cost_snapshot' => $variant->cost]);
    }
}
