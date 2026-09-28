<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search'));
        $categoryId = $request->integer('category');
        $status = $request->string('status')->toString();
        $scope = $request->string('scope')->toString();
        $products = Product::query()->with(['category', 'variants'])
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")))
            ->when($categoryId, fn ($query) => $query->where('product_category_id', $categoryId))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('status', $status))
            ->when(in_array($scope, ['color_bar', 'retail'], true), fn ($query) => $query->where('is_color_bar_usable', $scope === 'color_bar'))
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
        return view('products.form', ['product' => new Product, 'categories' => ProductCategory::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $product = Product::create(collect($data)->except(['variant_name', 'variant_sku', 'base_unit', 'content_quantity', 'cost', 'sale_price', 'minimum_stock', 'maximum_stock'])->all());
        $product->variants()->create($this->variantData($data));

        return redirect()->route('products.index')->with('success', 'Producto creado correctamente.');
    }

    public function show(Product $product): View
    {
        return $this->edit($product);
    }

    public function edit(Product $product): View
    {
        return view('products.form', ['product' => $product->load('variants'), 'categories' => ProductCategory::query()->orderBy('name')->get()]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request, $product);
        $product->update(collect($data)->except(['variant_name', 'variant_sku', 'base_unit', 'content_quantity', 'cost', 'sale_price', 'minimum_stock', 'maximum_stock'])->all());
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
            'name' => ['required', 'string', 'max:150'], 'brand' => ['nullable', 'string', 'max:100'],
            'sku' => ['required', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($product)], 'description' => ['nullable', 'string', 'max:2000'],
            'is_color_bar_usable' => ['nullable', 'boolean'], 'status' => ['required', Rule::in(['active', 'inactive'])],
            'variant_name' => ['required', 'string', 'max:150'], 'variant_sku' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'sku')->ignore($variant)],
            'base_unit' => ['required', Rule::in(['g', 'ml', 'l', 'unidad'])], 'content_quantity' => ['required', 'numeric', 'gt:0'],
            'cost' => ['required', 'numeric', 'min:0'], 'sale_price' => ['required', 'numeric', 'min:0'], 'minimum_stock' => ['required', 'numeric', 'min:0'], 'maximum_stock' => ['nullable', 'numeric', 'gte:minimum_stock'],
        ]);
        $data['is_color_bar_usable'] = $request->boolean('is_color_bar_usable');
        $data['maximum_stock'] = $data['maximum_stock'] ?? max((float) $data['minimum_stock'], (float) $data['minimum_stock'] * 3);

        return $data;
    }

    private function variantData(array $data): array
    {
        return ['name' => $data['variant_name'], 'sku' => $data['variant_sku'] ?: null, 'base_unit' => $data['base_unit'], 'content_quantity' => $data['content_quantity'], 'cost' => $data['cost'], 'sale_price' => $data['sale_price'], 'minimum_stock' => $data['minimum_stock'], 'maximum_stock' => $data['maximum_stock']];
    }
}
