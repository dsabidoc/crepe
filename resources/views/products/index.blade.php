<x-layouts.app title="Productos">
<section class="page-title flex-wrap">
    <div><p class="eyebrow">CATÁLOGO · PRODUCTOS</p><h1>Productos</h1><p>Presentaciones, precios y unidades para venta y Color Bar.</p></div>
    <div class="title-actions min-w-0 flex-wrap">
        @can('products.export')<a class="button button-secondary" href="{{ route('products.export') }}">Descargar Excel</a><a class="button button-secondary" href="{{ route('products.export', ['format' => 'csv']) }}">Descargar CSV</a>@endcan
        @can('products.import')<a class="button button-secondary" href="{{ route('products.import.create') }}">Importar Excel / CSV</a>@endcan
        @can('imports.view')<a class="button button-secondary" href="{{ route('imports.index') }}">Historial de importaciones</a>@endcan
        <a class="button button-secondary" href="{{ route('products.catalogs') }}">Catálogos</a><a class="button button-primary" href="{{ route('products.create') }}">+ Nuevo producto</a>
    </div>
</section>
<section class="surface list-surface">
    <header class="list-controls">
        <form method="GET" class="list-filter-bar">
            <label class="list-search"><span>⌕</span><input name="search" value="{{ $search }}" placeholder="Producto, marca o SKU"></label>
            <select name="category" aria-label="Filtrar por categoría"><option value="">Todas las categorías</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>@endforeach</select>
            <select name="scope" aria-label="Filtrar por uso"><option value="">Todos los usos</option><option value="retail" @selected($scope === 'retail')>Recepción</option><option value="color_bar" @selected($scope === 'color_bar')>Productos para fórmulas</option><option value="color_bar_supplies" @selected($scope === 'color_bar_supplies')>Insumos de Color Bar</option></select>
            <select name="status" aria-label="Filtrar por estatus"><option value="">Todos</option><option value="active" @selected($status === 'active')>Activos</option><option value="inactive" @selected($status === 'inactive')>Inactivos</option></select>
            <button class="button button-secondary" type="submit">Filtrar</button>
            @if($search || $categoryId || $scope || $status)<a class="filter-clear" href="{{ route('products.index') }}">Limpiar</a>@endif
        </form>
        <span class="record-count">{{ $products->total() }} productos</span>
    </header>
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
                        <small>@if($variant?->color_bar_pricing_type === 'both')Color Bar · Ambos · ${{ number_format((float) ($variant?->color_bar_sale_price ?? 0), 2) }} por aplicación · ${{ number_format((float) ($product->category?->color_bar_multiplier ?? 0), 2) }} por gr@else{{ $variant?->color_bar_pricing_type === 'multiplier' ? 'Color Bar × $'.number_format((float) ($product->category?->color_bar_multiplier ?? 0), 2).' por gr' : 'Color Bar $'.number_format((float) ($variant?->color_bar_sale_price ?? 0), 2).' por aplicación' }}@endif</small>
                    @endif
                </strong>
                <b>→</b>
            </a>
        @empty
            <p class="empty-state">No hay productos con estos filtros.</p>
        @endforelse
    </div>
    <div class="pagination-row">{{ $products->links() }}</div>
</section>
</x-layouts.app>
