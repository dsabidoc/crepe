<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryReplenishmentService
{
    /**
     * @param  array{minimum_stock: string, maximum_stock: ?string}  $limits
     * @return array{order_id: int, added: bool}|null
     */
    public function addVariant(ProductVariant $variant, float $available, array $limits, string $notes = 'Reabasto automático por mínimo de inventario.'): ?array
    {
        $supplierId = $variant->product->preferred_supplier_id;
        if ($supplierId === null || $available > (float) $limits['minimum_stock']) {
            return null;
        }

        return DB::transaction(function () use ($variant, $available, $limits, $notes, $supplierId): ?array {
            $supplier = Supplier::query()->whereKey($supplierId)->lockForUpdate()->first();
            if (! $supplier?->is_active) {
                return null;
            }
            $existing = PurchaseOrder::query()->where('supplier_id', $supplierId)->where('status', 'draft')
                ->whereHas('items', fn ($items) => $items->where('product_variant_id', $variant->id))->orderBy('id')->lockForUpdate()->first();
            if ($existing) {
                return ['order_id' => $existing->id, 'added' => false];
            }
            $order = PurchaseOrder::query()->where('supplier_id', $supplierId)->where('status', 'draft')->orderBy('id')->lockForUpdate()->first();
            if (! $order) {
                $order = PurchaseOrder::query()->create(['code' => 'OC-TEMP-'.str()->uuid(), 'supplier_id' => $supplierId, 'status' => 'draft', 'notes' => $notes]);
                $order->update(['code' => 'OC-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)]);
            }
            $target = max((float) $limits['maximum_stock'], (float) $limits['minimum_stock']);
            $order->items()->create(['product_variant_id' => $variant->id, 'ordered_quantity' => max((int) ceil($target - $available), 1), 'cost_snapshot' => $variant->cost]);

            return ['order_id' => $order->id, 'added' => true];
        }, 3);
    }

    /**
     * @param  array{search: string, brand: string, category: int, low_stock: bool}  $filters
     * @return array{orders: list<array{id: int, code: string, supplier: string}>, added: int, existing: int, skipped: list<array{name: string, sku: ?string}>}
     */
    public function send(array $filters, User $actor, string $key): array
    {
        abort_unless($actor->can('inventory.view') && $actor->can('inventory.requests.manage') && $actor->can('mode.almacen.access'), 403);
        if (! $filters['low_stock']) {
            throw ValidationException::withMessages(['replenishment' => 'Activa Bajo mínimo de Almacén antes de enviar los productos a compra.']);
        }

        return DB::transaction(function () use ($filters, $actor, $key): array {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
            $existing = AuditLog::query()->where('user_id', $actor->id)->where('action', 'inventory.replenishment.sent')->where('correlation_id', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->after['filters_hash'], $hash), 409, 'Esta confirmación ya se utilizó con otros filtros.');

                return $existing->after['result'];
            }
            $location = InventoryLocation::query()->where('code', 'ALM')->firstOrFail();
            $variants = InventoryFilter::apply(ProductVariant::query(), $filters, $location->id)
                ->with(['product.preferredSupplier', 'balances' => fn ($balances) => $balances->where('inventory_location_id', $location->id)])
                ->orderBy('id')->limit(10001)->get();
            if ($variants->isEmpty() || $variants->count() > 10000) {
                throw ValidationException::withMessages(['replenishment' => $variants->isEmpty() ? 'No hay productos bajo mínimo con estos filtros.' : 'Puedes enviar hasta 10,000 productos en una operación. Reduce los filtros.']);
            }
            $result = ['orders' => [], 'added' => 0, 'existing' => 0, 'skipped' => []];
            $orderIds = [];
            foreach ($variants->sortBy(fn (ProductVariant $variant): string => str_pad((string) ($variant->product->preferred_supplier_id ?? 0), 20, '0', STR_PAD_LEFT).':'.str_pad((string) $variant->id, 20, '0', STR_PAD_LEFT)) as $variant) {
                $balance = $variant->balances->first();
                $outcome = $this->addVariant($variant, (float) $balance->available_quantity, InventoryLimitService::values($balance, $variant), 'Reabasto solicitado desde los filtros del inventario de Almacén.');
                if ($outcome === null) {
                    $result['skipped'][] = ['name' => $variant->product->name, 'sku' => $variant->sku];

                    continue;
                }
                $orderIds[] = $outcome['order_id'];
                $result[$outcome['added'] ? 'added' : 'existing']++;
            }
            $result['orders'] = PurchaseOrder::query()->with('supplier')->whereIn('id', array_unique($orderIds))->orderBy('id')->get()->map(fn (PurchaseOrder $order): array => ['id' => $order->id, 'code' => $order->code, 'supplier' => $order->supplier->name])->all();
            AuditLog::query()->create(['user_id' => $actor->id, 'action' => 'inventory.replenishment.sent', 'subject_type' => InventoryLocation::class, 'subject_id' => $location->id, 'after' => ['filters' => $filters, 'filters_hash' => $hash, 'result' => $result], 'reason' => 'Envío de mínimos filtrados a borradores de compra.', 'correlation_id' => $key]);

            return $result;
        }, 3);
    }
}
