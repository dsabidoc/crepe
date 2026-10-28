<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

class InventoryLimitService
{
    /** @return array{minimum_stock: string, maximum_stock: ?string} */
    public static function values(?InventoryBalance $balance, ProductVariant $variant): array
    {
        return [
            'minimum_stock' => number_format((float) ($balance?->limits_configured ? $balance->minimum_stock : $variant->minimum_stock), 3, '.', ''),
            'maximum_stock' => ($balance?->limits_configured ? $balance->maximum_stock : $variant->maximum_stock) === null ? null : number_format((float) ($balance?->limits_configured ? $balance->maximum_stock : $variant->maximum_stock), 3, '.', ''),
        ];
    }

    /** @param array{minimum_stock: string|int|float, maximum_stock: string|int|float|null} $limits */
    public function update(ProductVariant $variant, InventoryLocation $location, array $limits): InventoryBalance
    {
        foreach (['minimum_stock', 'maximum_stock'] as $field) {
            if ($limits[$field] !== null && (! is_numeric($limits[$field]) || (float) $limits[$field] < 0 || (float) $limits[$field] > 999999999 || (float) $limits[$field] !== floor((float) $limits[$field]))) {
                throw ValidationException::withMessages(['limits' => 'Los mínimos y máximos deben ser unidades enteras desde cero.']);
            }
        }
        if ($limits['maximum_stock'] !== null && (float) $limits['maximum_stock'] < (float) $limits['minimum_stock']) {
            throw ValidationException::withMessages(['limits' => 'El máximo debe ser mayor o igual al mínimo de '.$location->name.'.']);
        }
        $balance = InventoryBalance::query()->firstOrCreate(['product_variant_id' => $variant->id, 'inventory_location_id' => $location->id], ['available_quantity' => 0]);
        $balance->forceFill([...$limits, 'limits_configured' => true])->save();
        if ($location->code === 'ALM') {
            $variant->update($limits);
        }

        return $balance;
    }
}
