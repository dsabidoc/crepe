<x-layouts.app title="Productos">
<section class="page-title flex-wrap">
    <div><p class="eyebrow">CATÁLOGO · PRODUCTOS</p><h1>Productos</h1><p>Presentaciones, precios y unidades para venta y Color Bar.</p></div>
    <div class="title-actions min-w-0 flex-wrap">
        @can('products.export')<a class="button button-secondary" href="{{ route('products.export') }}">Descargar Excel</a><a class="button button-secondary" href="{{ route('products.export', ['format' => 'csv']) }}">Descargar CSV</a>@endcan
        @can('products.import')<a class="button button-secondary" href="{{ route('products.import.create') }}">Importar Excel / CSV</a>@endcan
        @can('imports.view')<a class="button button-secondary" href="{{ route('imports.index') }}">Historial de importaciones</a>@endcan
        <a class="button button-secondary" href="{{ route('products.catalogs') }}">Catálogos</a>@can('products.catalog.manage')<a class="button button-primary" href="{{ route('products.create') }}">+ Nuevo producto</a>@endcan
    </div>
</section>
<section class="surface list-surface">
    <header class="list-controls">
        <form method="GET" class="list-filter-bar">
            <label class="list-search"><span>⌕</span><input name="search" value="{{ $search }}" placeholder="Producto, marca o SKU"></label>
            <select name="brand" aria-label="Filtrar por marca"><option value="">Todas las marcas</option>@foreach($brands as $brandName)<option value="{{ $brandName }}" @selected($brand === $brandName)>{{ $brandName }}</option>@endforeach</select><select name="category" aria-label="Filtrar por categoría"><option value="">Todas las categorías</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>@endforeach</select>
            <select name="scope" aria-label="Filtrar por uso"><option value="">Todos los usos</option><option value="retail" @selected($scope === 'retail')>Recepción</option><option value="color_bar" @selected($scope === 'color_bar')>Productos para fórmulas</option><option value="color_bar_supplies" @selected($scope === 'color_bar_supplies')>Insumos de Color Bar</option></select>
            <select name="status" aria-label="Filtrar por estatus"><option value="">Todos</option><option value="active" @selected($status === 'active')>Activos</option><option value="inactive" @selected($status === 'inactive')>Inactivos</option></select>
            <button class="button button-secondary" type="submit">Filtrar</button>
            @if($search || $brand || $categoryId || $scope || $status)<a class="filter-clear" href="{{ route('products.index') }}">Limpiar</a>@endif
        </form>
        <span class="record-count">{{ $products->total() }} productos</span>
    </header>
@if(session('crepe.mode') === 'almacen')
<div data-inline-root>@csrf<p class="inventory-save-status" data-inline-status role="status" hidden></p>
<div class="inventory-register"><table><thead><tr><th>Producto</th><th>Estado</th><th>Marca / proveedor</th><th>Categoría</th><th>Existencias</th><th>Precio</th>@can('products.costs.view')<th>Costo</th>@endcan<th>Usos</th></tr></thead><tbody>
@forelse($products as $product)
@php
    $balances = $product->variants->flatMap(fn ($variant) => $variant->balances)->filter(fn ($balance) => in_array($balance->location->code, ['ALM', 'REC', 'CB'], true));
@endphp
<tr data-product-id="{{ $product->id }}" data-product-url="{{ route('products.edit', $product) }}" style="cursor:pointer"><td><div class="product-identity">@if($image = $product->image_paths[0] ?? null)<img class="product-thumb" src="{{ asset('storage/'.$image) }}" alt="" loading="lazy">@else<span class="product-thumb product-thumb-placeholder" aria-hidden="true">▧</span>@endif<div><a href="{{ route('products.edit', $product) }}"><strong>{{ $product->name }}</strong></a><small>{{ $product->sku }}</small></div></div></td>
<td><span class="catalog-status {{ $product->status !== 'active' ? 'inactive' : '' }}">{{ $product->status === 'active' ? 'Activo' : 'Inactivo' }}</span></td><td>{{ $product->brand }}<small>{{ $product->preferredSupplier?->name ?: 'Sin proveedor' }}</small></td><td>{{ $product->category?->name ?? 'Sin categoría' }}</td>
<td class="stock-number"><div class="location-breakdown"><button type="button" class="inline-cell-trigger" popovertarget="product-stock-{{ $product->id }}">{{ number_format((float) $balances->sum('available_quantity'), 0) }} unidades ▾</button><div popover id="product-stock-{{ $product->id }}" data-location-popover aria-label="Existencias por ubicación">@foreach(['ALM' => 'Almacén', 'REC' => 'Recepción', 'CB' => 'Color Bar'] as $code => $label)<p><span>{{ $label }}</span><strong>{{ number_format((float) $balances->filter(fn ($balance) => $balance->location->code === $code)->sum('available_quantity'), 0) }}</strong></p>@endforeach<a href="{{ route('inventory.index', ['location' => 'ALM', 'search' => $product->sku ?: $product->name]) }}">Ver inventario</a></div></div></td>
<td class="stock-number">@foreach($product->variants as $variant)@if($product->variants->count() > 1)<small>{{ $variant->name }}</small>@endif<x-inventory-inline-cell :variant="$variant" field="sale_price" :value="$variant->sale_price" :editable="$product->status === 'active'" />@endforeach</td>
@can('products.costs.view')<td class="stock-number">@foreach($product->variants as $variant)@if($product->variants->count() > 1)<small>{{ $variant->name }}</small>@endif<x-inventory-inline-cell :variant="$variant" field="cost" :value="$variant->cost" :editable="$product->status === 'active'" />@endforeach</td>@endcan
<td>@if($product->is_reception_usable)<small>Venta en Recepción</small>@endif @if($product->is_color_bar_usable)<small>Fórmulas</small>@endif @if($product->isColorBarSupply())<small>Insumo Color Bar</small>@endif</td></tr>
@empty<tr><td colspan="8" class="empty-state">No hay productos con estos filtros.</td></tr>@endforelse
</tbody></table></div></div>
<script>
(() => {
    document.querySelectorAll('[data-product-url]').forEach(row => {
        row.addEventListener('click', event => {
            if (event.defaultPrevented || event.button !== 0 || window.getSelection()?.toString().trim()) return;
            if (event.target.closest('a, button, input, select, textarea, summary, [popover], [contenteditable], [role="button"]')) return;
            row.querySelector('.product-identity a').click();
        });
    });
})();
</script>
@include('inventory.inline-editor')
@else
    <div class="data-table product-data-table product-catalog-table">
        <div class="table-head"><span>PRODUCTO</span><span>MARCA</span><span>PROVEEDOR</span><span>CATEGORÍA</span><span>UNIDAD</span><span>VENTA</span><span></span></div>
        @forelse($products as $product)
            @php($variant = $product->variants->first())
            <a class="table-row" href="{{ route('products.edit', $product) }}">
                <div class="product-name-cell"><strong>{{ $product->name }}</strong><small>@if($product->is_color_bar_usable)Fórmulas · @endif @if($product->isColorBarSupply())Insumo Color Bar · @endif {{ $product->sku }} · {{ $product->status === 'active' ? 'Activo' : 'Inactivo' }}</small></div>
                <x-product-reference :product="$product" field="brand" />
                <x-product-reference :product="$product" field="supplier" />
                <span data-label="Categoría">{{ $product->category?->name ?? 'Sin categoría' }}</span>
                <span data-label="Unidad de inventario">Unidad</span>
                <strong data-label="Venta">
                    @if($product->is_reception_usable)Recepción ${{ number_format((float) ($variant?->sale_price ?? 0), 2) }}@endif
                    @if($product->is_color_bar_usable)
                        <small>@if($variant?->color_bar_pricing_type === 'both')Color Bar · Ambos · ${{ number_format((float) ($variant?->color_bar_sale_price ?? 0), 2) }} por aplicación · ${{ number_format((float) (($product->colorBarRateCategory ?? $product->category)?->color_bar_multiplier ?? 0), 2) }} por gr@else{{ $variant?->color_bar_pricing_type === 'multiplier' ? 'Color Bar × $'.number_format((float) (($product->colorBarRateCategory ?? $product->category)?->color_bar_multiplier ?? 0), 2).' por gr' : 'Color Bar $'.number_format((float) ($variant?->color_bar_sale_price ?? 0), 2).' por aplicación' }}@endif</small>
                    @endif
                </strong>
                <b>→</b>
            </a>
        @empty
            <p class="empty-state">No hay productos con estos filtros.</p>
        @endforelse
    </div>
@endif
    <div class="pagination-row">{{ $products->links() }}</div>
</section>
</x-layouts.app>
