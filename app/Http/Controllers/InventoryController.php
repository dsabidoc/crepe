<?php

namespace App\Http\Controllers;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(Request $request): View
    {
        $location = $request->string('location')->toString();
        $search = trim((string) $request->string('search'));
        $categoryId = $request->integer('category');
        $lowStock = $request->boolean('low_stock');
        $locationModel = $location !== '' ? InventoryLocation::query()->where('code', $location)->first() : null;
        $balances = InventoryBalance::query()->with(['variant.product', 'location'])
            ->whereHas('variant.product', fn ($products) => $products->where('status', 'active'))
            ->when($location, fn ($query) => $query->whereHas('location', fn ($locations) => $locations->where('code', $location)))
            ->when($categoryId, fn ($query) => $query->whereHas('variant.product', fn ($products) => $products->where('product_category_id', $categoryId)))
            ->when($search, fn ($query) => $query->whereHas('variant', fn ($variants) => $variants
                ->where(fn ($variantSearch) => $variantSearch
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%"))
                ->orWhereHas('product', fn ($products) => $products->where(fn ($productSearch) => $productSearch
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")))))
            ->when($lowStock, fn ($query) => $query->whereHas('variant', fn ($variants) => $variants->whereColumn('inventory_balances.available_quantity', '<=', 'product_variants.minimum_stock')))
            ->orderBy('inventory_location_id')->orderBy('product_variant_id')->paginate(30)->withQueryString();

        return view('inventory.index', [
            'balances' => $balances,
            'categories' => ProductCategory::query()->orderBy('name')->get(),
            'categoryId' => $categoryId,
            'location' => $location,
            'locationModel' => $locationModel,
            'lowStock' => $lowStock,
            'search' => $search,
        ]);
    }
}
