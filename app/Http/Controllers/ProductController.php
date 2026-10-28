<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Services\ProductSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search'));
        $categoryId = $request->integer('category');
        $status = $request->has('status') ? $request->string('status')->toString() : 'active';
        $scope = $request->string('scope')->toString();
        $brand = $request->string('brand')->toString();
        $products = Product::query()->with(['category', 'colorBarRateCategory', 'variants.balances.location', 'variants.product', 'preferredSupplier'])
            ->when($search, fn ($query) => ProductSearch::products($query, $search))
            ->when($brand !== '', fn ($query) => $query->where('brand', $brand))
            ->when($categoryId, fn ($query) => $query->where('product_category_id', $categoryId))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('status', $status))
            ->when($scope === 'color_bar', fn ($query) => $query->where('is_color_bar_usable', true))
            ->when($scope === 'color_bar_supplies', fn ($query) => $query->colorBarSupplies())
            ->when($scope === 'retail', fn ($query) => $query->where('is_reception_usable', true))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('products.index', [
            'brand' => $brand,
            'brands' => Product::query()->whereNotNull('brand')->distinct()->orderBy('brand')->pluck('brand'),
            'categories' => ProductCategory::query()->orderBy('name')->get(),
            'categoryId' => $categoryId,
            'products' => $products,
            'scope' => $scope,
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        abort_unless(auth()->user()->can('products.catalog.manage'), 403);

        return view('products.form', ['product' => new Product, 'categories' => ProductCategory::query()->orderBy('name')->get(), 'brands' => ProductBrand::query()->where('is_active', true)->orderBy('name')->get(), 'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get()]);
    }

    public function catalogs(): View
    {
        return view('products.catalogs', [
            'brands' => ProductBrand::query()->withCount('products')->orderBy('name')->get(),
            'categories' => ProductCategory::query()->withCount('products')->orderBy('name')->get(),
            'suppliers' => Supplier::query()->withCount('purchaseOrders')->orderBy('name')->get(),
        ]);
    }

    public function storeBrand(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('products.catalog.manage'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:product_brands,name'],
        ]);

        ProductBrand::query()->create([...$data, 'is_active' => true]);

        return back()->with('success', 'Marca agregada al catálogo.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('products.catalog.manage'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:product_categories,name'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_bar_multiplier' => ['nullable', 'numeric', 'min:0'],
        ]);

        ProductCategory::query()->create([
            'name' => $data['name'],
            'color' => $data['color'] ?? '#B95070',
            'color_bar_multiplier' => $data['color_bar_multiplier'] ?? null,
        ]);

        return back()->with('success', 'Categoría agregada al catálogo.');
    }

    public function updateCategory(Request $request, ProductCategory $category): RedirectResponse
    {
        abort_unless($request->user()->can('products.catalog.manage'), 403);
        $data = $request->validate([
            'color_bar_multiplier' => ['nullable', 'numeric', 'min:0'],
        ]);

        $category->update(['color_bar_multiplier' => $data['color_bar_multiplier'] ?? null]);

        return back()->with('success', 'Multiplicador de Color Bar actualizado.');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('products.catalog.manage'), 403);
        $data = $this->validated($request);
        $product = Product::create(collect($data)->except(['variant_name', 'variant_sku', 'base_unit', 'content_quantity', 'cost', 'sale_price', 'color_bar_pricing_type', 'color_bar_sale_price', 'minimum_stock', 'maximum_stock', 'photos'])->all());
        $product->update(['image_paths' => $this->storePhotos($request)]);
        $variant = $product->variants()->create($this->variantData($data));
        $this->ensureBalances($variant);

        return redirect()->route('products.index')->with('success', 'Producto creado correctamente.');
    }

    public function show(Product $product): View
    {
        return $this->edit($product);
    }

    public function edit(Product $product): View
    {
        $product->load('variants.balances');

        return view('products.form', [
            'product' => $product,
            'categories' => ProductCategory::query()->orderBy('name')->get(),
            'brands' => ProductBrand::query()->where('is_active', true)->orderBy('name')->get(),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(),
            'inventorySummary' => $this->inventorySummary($product),
            'revision' => $this->revision($product),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->can('products.catalog.manage'), 403);
        DB::transaction(function () use ($request, $product): void {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $product->variants()->orderBy('id')->lockForUpdate()->get();
            if ($request->filled('revision') && ! hash_equals($this->revision($product), $request->string('revision')->toString())) {
                throw ValidationException::withMessages(['product' => 'El producto cambió mientras editabas. Actualiza la página antes de guardar para conservar esos cambios.']);
            }
            $before = $product->toArray();
            $data = $this->validated($request, $product);
            $productData = collect($data)->except(['variant_name', 'variant_sku', 'base_unit', 'content_quantity', 'cost', 'sale_price', 'color_bar_pricing_type', 'color_bar_sale_price', 'minimum_stock', 'maximum_stock', 'photos'])->all();
            $photos = $this->storePhotos($request);
            if ($photos !== []) {
                $productData['image_paths'] = array_merge($product->image_paths ?? [], $photos);
            }
            $product->update($productData);
            $variant = $product->variants()->first();
            $variant ? $variant->update($this->variantData($data)) : $product->variants()->create($this->variantData($data));

            $this->ensureBalances($product->variants()->firstOrFail());
            AuditLog::query()->create(['user_id' => $request->user()->id, 'action' => 'product.updated', 'subject_type' => Product::class, 'subject_id' => $product->id, 'before' => $before, 'after' => $product->fresh('variants')->toArray(), 'reason' => 'Edición del producto']);
        });

        return redirect()->route('products.index')->with('success', 'Producto actualizado correctamente.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        abort_unless(auth()->user()->can('products.catalog.manage'), 403);
        $product->update(['status' => 'inactive']);

        return redirect()->route('products.index')->with('success', 'Producto desactivado. Los movimientos históricos se conservan.');
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $variant = $product?->variants()->first();
        $request->merge(['base_unit' => $variant?->base_unit ?? 'unidad', 'content_quantity' => $variant?->content_quantity ?? 1]);
        $data = $request->validate([
            'product_category_id' => ['required', Rule::exists('product_categories', 'id')],
            'product_family' => ['nullable', 'string', 'max:100'],
            'color_bar_rate_category_id' => ['nullable', Rule::exists('product_categories', 'id')],
            'name' => ['required', 'string', 'max:150'], 'product_brand_id' => ['nullable', Rule::exists('product_brands', 'id')->where('is_active', true)],
            'preferred_supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'sku' => ['required', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($product)], 'description' => ['nullable', 'string', 'max:2000'],
            'is_color_bar_usable' => ['nullable', 'boolean'], 'is_color_bar_supply' => ['nullable', 'boolean'], 'is_reception_usable' => ['nullable', 'boolean'], 'status' => ['required', Rule::in(['active', 'inactive'])],
            'variant_name' => ['required', 'string', 'max:150'], 'variant_sku' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'sku')->ignore($variant)],
            'base_unit' => ['required', Rule::in(['g', 'ml', 'l', 'unidad'])], 'content_quantity' => ['required', 'numeric', 'gt:0'],
            'cost' => ['required', 'numeric', 'min:0'], 'sale_price' => ['nullable', 'numeric', 'min:0'], 'color_bar_pricing_type' => ['nullable', Rule::in(['fixed', 'multiplier', 'both'])], 'color_bar_sale_price' => ['nullable', 'numeric', 'min:0'], 'minimum_stock' => ['required', 'numeric', 'min:0'], 'maximum_stock' => ['nullable', 'numeric', 'min:0'],
            'photos' => ['nullable', 'array', 'max:5'], 'photos.*' => ['nullable', File::image()->max('8mb')],
        ]);
        foreach (['minimum_stock', 'maximum_stock'] as $field) {
            if (isset($data[$field]) && (float) $data[$field] !== floor((float) $data[$field])) {
                throw ValidationException::withMessages([$field => 'El stock mínimo y máximo deben ser cantidades enteras de unidades.']);
            }
        }
        $limitsChanged = $variant === null || (float) $data['minimum_stock'] !== (float) $variant->minimum_stock || (isset($data['maximum_stock']) && (float) $data['maximum_stock'] !== (float) $variant->maximum_stock);
        if ($limitsChanged && isset($data['maximum_stock']) && (float) $data['maximum_stock'] < (float) $data['minimum_stock']) {
            throw ValidationException::withMessages(['maximum_stock' => 'El máximo debe ser mayor o igual al mínimo.']);
        }
        $data['color_bar_rate_category_id'] = $request->has('color_bar_rate_category_id') ? $data['color_bar_rate_category_id'] : ($product !== null ? $product->color_bar_rate_category_id : $data['product_category_id']);
        $data['is_color_bar_usable'] = $request->boolean('is_color_bar_usable');
        $data['is_color_bar_supply'] = $request->has('is_color_bar_supply')
            ? $request->boolean('is_color_bar_supply')
            : ($product?->isColorBarSupply() ?? $data['is_color_bar_usable']);
        $data['is_reception_usable'] = $request->boolean('is_reception_usable');
        $data['sale_price'] = $data['sale_price'] ?? $variant?->sale_price ?? 0;
        $data['color_bar_pricing_type'] = $data['color_bar_pricing_type'] ?? $variant?->color_bar_pricing_type ?? 'fixed';
        $data['color_bar_sale_price'] = $data['color_bar_sale_price'] ?? $variant?->color_bar_sale_price ?? 0;

        if (! $data['is_color_bar_usable'] && ! $data['is_reception_usable'] && ! $data['is_color_bar_supply']) {
            throw ValidationException::withMessages(['availability' => 'Selecciona al menos un uso: Recepción, productos para fórmulas o insumos de Color Bar.']);
        }

        $category = ProductCategory::query()->find($data['color_bar_rate_category_id'] ?? $data['product_category_id']);
        if ($data['is_color_bar_usable'] && in_array($data['color_bar_pricing_type'], ['multiplier', 'both'], true) && $category?->color_bar_multiplier === null) {
            throw ValidationException::withMessages(['product_category_id' => 'Para usar medida (gr), selecciona una categoría con tarifa por gramo.']);
        }
        if ($variant !== null && (float) $data['cost'] === (float) number_format((float) $variant->cost, 2, '.', '')) {
            $data['cost'] = $variant->cost;
        }
        $data['product_brand_id'] = $data['product_brand_id'] ?? null;
        $data['brand'] = $data['product_brand_id'] ? ProductBrand::query()->findOrFail($data['product_brand_id'])->name : null;
        $data['maximum_stock'] = $data['maximum_stock'] ?? ($product !== null ? $variant?->maximum_stock : max((float) $data['minimum_stock'], (float) $data['minimum_stock'] * 3));

        return $data;
    }

    private function revision(Product $product): string
    {
        return hash_hmac('sha256', json_encode([$product->getAttributes(), $product->variants()->orderBy('id')->get()->map->getAttributes()->all()], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function variantData(array $data): array
    {
        return ['name' => $data['variant_name'], 'sku' => $data['variant_sku'] ?? null, 'base_unit' => $data['base_unit'], 'content_quantity' => $data['content_quantity'], 'cost' => $data['cost'], 'sale_price' => $data['sale_price'], 'color_bar_pricing_type' => $data['color_bar_pricing_type'], 'color_bar_sale_price' => $data['color_bar_sale_price'], 'minimum_stock' => $data['minimum_stock'], 'maximum_stock' => $data['maximum_stock']];
    }

    private function inventorySummary(Product $product): array
    {
        $locationLabels = ['ALM' => 'Almacén', 'REC' => 'Recepción', 'CB' => 'Color Bar'];
        $locationIds = InventoryLocation::query()
            ->whereIn('code', array_keys($locationLabels))
            ->pluck('id', 'code');

        return collect($locationLabels)
            ->map(fn (string $name, string $code): array => [
                'name' => $name,
                'available_quantity' => $product->variants->sum(fn ($variant): float => (float) $variant->balances
                    ->where('inventory_location_id', $locationIds->get($code))
                    ->sum('available_quantity')),
            ])
            ->values()
            ->all();
    }

    private function ensureBalances(ProductVariant $variant): void
    {
        foreach (InventoryLocation::query()->whereIn('code', ['ALM', 'REC', 'CB'])->get() as $location) {
            InventoryBalance::query()->firstOrCreate(['product_variant_id' => $variant->id, 'inventory_location_id' => $location->id], ['available_quantity' => 0, 'minimum_stock' => $variant->minimum_stock, 'maximum_stock' => $variant->maximum_stock, 'limits_configured' => true]);
            if ($location->code === 'ALM') {
                InventoryBalance::query()->where('product_variant_id', $variant->id)->where('inventory_location_id', $location->id)->update(['minimum_stock' => $variant->minimum_stock, 'maximum_stock' => $variant->maximum_stock, 'limits_configured' => true]);
            }
        }
    }

    private function storePhotos(Request $request): array
    {
        return collect($request->file('photos', []))->filter()->map(fn ($photo): string => $photo->store('product-images', 'public'))->all();
    }
}
