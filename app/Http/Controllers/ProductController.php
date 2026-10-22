<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $products = Product::query()->with(['category', 'variants', 'preferredSupplier'])
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")))
            ->when($categoryId, fn ($query) => $query->where('product_category_id', $categoryId))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('status', $status))
            ->when($scope === 'color_bar', fn ($query) => $query->where('is_color_bar_usable', true))
            ->when($scope === 'color_bar_supplies', fn ($query) => $query->colorBarSupplies())
            ->when($scope === 'retail', fn ($query) => $query->where('is_reception_usable', true))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('products.index', [
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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:product_brands,name'],
        ]);

        ProductBrand::query()->create([...$data, 'is_active' => true]);

        return back()->with('success', 'Marca agregada al catálogo.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
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
        $data = $request->validate([
            'color_bar_multiplier' => ['nullable', 'numeric', 'min:0'],
        ]);

        $category->update(['color_bar_multiplier' => $data['color_bar_multiplier'] ?? null]);

        return back()->with('success', 'Multiplicador de Color Bar actualizado.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $product = Product::create(collect($data)->except(['variant_name', 'variant_sku', 'base_unit', 'content_quantity', 'cost', 'sale_price', 'color_bar_pricing_type', 'color_bar_sale_price', 'minimum_stock', 'maximum_stock', 'photos'])->all());
        $product->update(['image_paths' => $this->storePhotos($request)]);
        $product->variants()->create($this->variantData($data));

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
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request, $product);
        $productData = collect($data)->except(['variant_name', 'variant_sku', 'base_unit', 'content_quantity', 'cost', 'sale_price', 'color_bar_pricing_type', 'color_bar_sale_price', 'minimum_stock', 'maximum_stock', 'photos'])->all();
        $photos = $this->storePhotos($request);
        if ($photos !== []) {
            $productData['image_paths'] = array_merge($product->image_paths ?? [], $photos);
        }
        $product->update($productData);
        $variant = $product->variants()->first();
        $variant ? $variant->update($this->variantData($data)) : $product->variants()->create($this->variantData($data));

        return redirect()->route('products.index')->with('success', 'Producto actualizado correctamente.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->update(['status' => 'inactive']);

        return redirect()->route('products.index')->with('success', 'Producto desactivado. Los movimientos históricos se conservan.');
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $variant = $product?->variants()->first();
        $data = $request->validate([
            'product_category_id' => ['required', Rule::exists('product_categories', 'id')],
            'name' => ['required', 'string', 'max:150'], 'product_brand_id' => ['nullable', Rule::exists('product_brands', 'id')->where('is_active', true)],
            'preferred_supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'sku' => ['required', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($product)], 'description' => ['nullable', 'string', 'max:2000'],
            'is_color_bar_usable' => ['nullable', 'boolean'], 'is_color_bar_supply' => ['nullable', 'boolean'], 'is_reception_usable' => ['nullable', 'boolean'], 'status' => ['required', Rule::in(['active', 'inactive'])],
            'variant_name' => ['required', 'string', 'max:150'], 'variant_sku' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'sku')->ignore($variant)],
            'base_unit' => ['required', Rule::in(['g', 'ml', 'l', 'unidad'])], 'content_quantity' => ['required', 'numeric', 'gt:0'],
            'cost' => ['required', 'numeric', 'min:0'], 'sale_price' => ['nullable', 'numeric', 'min:0'], 'color_bar_pricing_type' => ['nullable', Rule::in(['fixed', 'multiplier', 'both'])], 'color_bar_sale_price' => ['nullable', 'numeric', 'min:0'], 'minimum_stock' => ['required', 'numeric', 'min:0'], 'maximum_stock' => ['nullable', 'numeric', 'gte:minimum_stock'],
            'photos' => ['nullable', 'array', 'max:5'], 'photos.*' => ['nullable', File::image()->max('8mb')],
        ]);
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

        $category = ProductCategory::query()->findOrFail($data['product_category_id']);
        if ($data['is_color_bar_usable'] && in_array($data['color_bar_pricing_type'], ['multiplier', 'both'], true) && $category->color_bar_multiplier === null) {
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

    private function storePhotos(Request $request): array
    {
        return collect($request->file('photos', []))->filter()->map(fn ($photo): string => $photo->store('product-images', 'public'))->all();
    }
}
