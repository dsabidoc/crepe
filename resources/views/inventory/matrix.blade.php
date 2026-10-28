<x-layouts.app title="Inventario de Almacén">
@php
    $canAdjust = auth()->user()->hasRole('Administrador');
    $canViewCosts = auth()->user()->can('products.costs.view');
    $locations = $locations->keyBy('code');
    $categoryId = $categoryId ?? 0;
    $lowStock = $lowStock ?? false;
@endphp
<style>.warehouse-stock-filters{padding:16px 18px}.warehouse-stock-filters .list-filter-bar{margin:0}.warehouse-stock-filters>span{display:block;margin-top:10px;color:#718096;font-size:12px}</style>
<section class="page-title flex-wrap"><div><h1>Inventario Almacén</h1><p>Recepción, Color Bar, Almacén y total en una sola tabla. Mínimos y máximos de Almacén para reabasto.</p></div><div class="title-actions min-w-0 flex-wrap">
@can('inventory.export')<a class="button button-secondary" href="{{ route('inventory.export', ['location_code' => 'ALM']) }}">Descargar Excel</a><a class="button button-secondary" href="{{ route('inventory.export', ['format' => 'csv', 'location_code' => 'ALM']) }}">Descargar CSV</a>@endcan
@can('inventory.import')<a class="button button-primary" href="{{ route('inventory.import.create', ['location_code' => 'ALM']) }}">Importar Excel / CSV</a>@endcan
@can('imports.view')<a class="button button-secondary" href="{{ route('imports.index') }}">Historial de importaciones</a>@endcan
<a class="button button-secondary" href="{{ route('inventory.requests.index') }}">Solicitudes</a><a class="button button-secondary" href="{{ route('activity.index', ['location' => 'ALM']) }}">Bitácoras</a>
</div></section>
@if($errors->any())<div class="notice-info" role="alert">{{ $errors->first() }}</div>@endif
<section class="surface list-surface"><header class="warehouse-stock-filters"><form method="GET" action="{{ route('inventory.index') }}" class="list-filter-bar" aria-label="Filtros de inventario de Almacén"><input type="hidden" name="location" value="ALM"><label class="list-search"><span>⌕</span><input name="search" value="{{ $search }}" placeholder="Producto, marca o SKU en cualquier orden"></label><select name="brand" aria-label="Filtrar por marca"><option value="">Todas las marcas</option>@foreach($brands as $name)<option value="{{ $name }}" @selected($brand === $name)>{{ $name }}</option>@endforeach</select><select name="category" aria-label="Filtrar por categoría"><option value="">Todas las categorías</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>@endforeach</select><label class="check-filter"><input type="checkbox" name="low_stock" value="1" @checked($lowStock)><span class="check-filter-control" aria-hidden="true"></span><span>Bajo mínimo de Almacén</span></label><button class="button button-secondary">Filtrar</button>@if($search || $brand || $categoryId || $lowStock)<a href="{{ route('inventory.index', ['location' => 'ALM']) }}">Limpiar</a>@endif</form><span>{{ $variants->total() }} productos · Existencias en unidades completas</span></header>
<div data-inline-root>@csrf
<p data-inline-status class="inventory-save-status" role="status" hidden></p>
<div class="inventory-register"><table class="warehouse-stock-table"><thead><tr><th>Producto / presentación</th><th>Marca / proveedor</th><th>Almacén</th><th>Recepción</th><th>Color Bar</th><th>Total</th>@if($canViewCosts)<th>Costo por unidad</th>@endif<th>Precio</th><th>Mínimo</th><th>Máximo</th></tr></thead><tbody>
@forelse($variants as $variant)
@php
    $warehouseBalance = $variant->balances->firstWhere('inventory_location_id', $locations->get('ALM')?->id);
    $warehouseLimits = \App\Services\InventoryLimitService::values($warehouseBalance, $variant);
@endphp
<tr data-stock-row data-variant-id="{{ $variant->id }}"><td><div class="product-identity">@if($image = $variant->product->image_paths[0] ?? null)<img class="product-thumb" src="{{ asset('storage/'.$image) }}" alt="" loading="lazy">@else<span class="product-thumb product-thumb-placeholder" aria-hidden="true">▧</span>@endif<div><a href="{{ route('products.edit', $variant->product) }}"><strong>{{ $variant->product->name }}</strong></a><small>{{ $variant->name }} · {{ $variant->sku }}</small></div></div></td><td>{{ $variant->product->brand }}<small>{{ $variant->product->preferredSupplier?->name ?: 'Sin proveedor' }}</small></td>
@foreach(['ALM', 'REC', 'CB'] as $code)
@php($balance = $variant->balances->firstWhere('inventory_location_id', $locations->get($code)?->id))
<td class="stock-number {{ $code === 'ALM' && (float) ($balance?->available_quantity ?? 0) <= (float) $warehouseLimits['minimum_stock'] ? 'stock-low' : '' }}" data-stock-location="{{ $code }}" data-quantity="{{ $balance?->available_quantity ?? 0 }}"><x-inventory-inline-cell :variant="$variant" field="quantity" :value="$balance?->available_quantity ?? 0" :location="$code" :editable="$balance !== null" /></td>
@endforeach
<td class="stock-number stock-total" data-stock-total>{{ number_format((float) $variant->balances->sum('available_quantity'), 0) }}</td>
@if($canViewCosts)<td class="stock-number"><x-inventory-inline-cell :variant="$variant" field="cost" :value="$variant->cost" /></td>@endif
<td class="stock-number"><x-inventory-inline-cell :variant="$variant" field="sale_price" :value="$variant->sale_price" /></td>
<td class="stock-number"><x-inventory-inline-cell :variant="$variant" field="minimum_stock" :value="$warehouseLimits['minimum_stock']" location="ALM" :editable="$warehouseBalance !== null" /></td>
<td class="stock-number"><x-inventory-inline-cell :variant="$variant" field="maximum_stock" :value="$warehouseLimits['maximum_stock']" location="ALM" :editable="$warehouseBalance !== null" /></td></tr>
@empty<tr><td colspan="{{ $canViewCosts ? 10 : 9 }}" class="empty-state">No hay productos que coincidan con los filtros.</td></tr>@endforelse
</tbody></table></div></div><div class="pagination-row">{{ $variants->links() }}</div></section>
@include('inventory.inline-editor')
</x-layouts.app>
