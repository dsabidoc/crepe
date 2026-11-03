<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class InventoryFilter
{
    /** @return array{search: string, brand: string, category: int, supplier: int, low_stock: bool} */
    public static function read(Request $request): array
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'brand' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'integer', 'min:0'],
            'supplier' => ['nullable', 'integer', 'min:0'],
            'low_stock' => ['nullable', 'boolean'],
        ]);

        return ['search' => trim($data['search'] ?? ''), 'brand' => $data['brand'] ?? '', 'category' => (int) ($data['category'] ?? 0), 'supplier' => (int) ($data['supplier'] ?? 0), 'low_stock' => (bool) ($data['low_stock'] ?? false)];
    }

    /** @param array{search?: string, brand?: string, category?: int, supplier?: int, low_stock?: bool} $filters */
    public static function apply(Builder $variants, array $filters, ?int $locationId = null): Builder
    {
        return $variants->whereHas('product', fn ($products) => $products->where('status', 'active')
            ->when(($filters['brand'] ?? '') !== '', fn ($products) => $products->where('brand', $filters['brand']))
            ->when($filters['supplier'] ?? 0, fn ($products, $supplier) => $products->where('preferred_supplier_id', $supplier))
            ->when($filters['category'] ?? 0, fn ($products, $category) => $products->where('product_category_id', $category)))
            ->when(($filters['search'] ?? '') !== '', fn ($variants) => ProductSearch::variants($variants, $filters['search']))
            ->when(($filters['low_stock'] ?? false) && $locationId !== null, fn ($variants) => $variants->whereHas('balances', fn ($balances) => $balances->where('inventory_location_id', $locationId)
                ->whereRaw('inventory_balances.available_quantity <= CASE WHEN inventory_balances.limits_configured = 1 THEN inventory_balances.minimum_stock ELSE product_variants.minimum_stock END')));
    }
}
