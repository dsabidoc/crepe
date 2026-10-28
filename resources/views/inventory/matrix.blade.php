<x-layouts.app title="Inventario de Almacén">
@php
    $canAdjust = auth()->user()->hasRole('Administrador');
    $canViewCosts = auth()->user()->can('products.costs.view');
    $locations = $locations->keyBy('code');
    $categoryId = $categoryId ?? 0;
    $lowStock = $lowStock ?? false;
@endphp
<style>
.warehouse-stock-scroll{overflow:auto}.warehouse-stock-table{width:100%;min-width:1180px;border-collapse:collapse;text-align:left}.warehouse-stock-table th{padding:14px 12px;background:#f6f8fb;color:#63748a;font-size:11px;white-space:nowrap}.warehouse-stock-table td{padding:14px 12px;border-bottom:1px solid #e5e9ef;vertical-align:middle;font-size:13px}.warehouse-stock-table .warehouse-product{min-width:220px;max-width:300px}.warehouse-stock-table small{display:block;margin-top:4px;color:#718096;font-size:11px}.warehouse-stock-table .stock-low{color:#ad4869}.warehouse-stock-table .stock-number{font-variant-numeric:tabular-nums;white-space:nowrap}.warehouse-stock-table .stock-total{font-weight:800;background:#fcf7f9}.warehouse-stock-editor,.warehouse-adjustment-actions{display:none}.is-editing .warehouse-stock-editor{display:block}.is-editing .warehouse-stock-value{display:none}.is-editing .warehouse-adjustment-actions{display:flex;gap:16px;align-items:end;flex-wrap:wrap;padding:20px}.warehouse-stock-table input[type=number]{width:85px;min-height:38px;padding:8px}.warehouse-adjustment-actions label{flex:1;min-width:240px}.warehouse-adjustment-actions small{flex-basis:100%;color:#718096}.warehouse-stock-table a{color:inherit}.warehouse-stock-table a:hover{color:#ad4869}.warehouse-stock-filters{padding:18px 20px}.warehouse-stock-filters .list-filter-bar{margin:0}.warehouse-stock-filters>span{display:block;margin-top:12px;color:#718096;font-size:12px}
</style>
<section class="page-title flex-wrap"><div><p class="eyebrow">ALMACÉN · INVENTARIO</p><h1>Existencias por ubicación</h1><p>Recepción, Color Bar, Almacén y total en una sola tabla. Mínimos y máximos de Almacén para reabasto.</p></div><div class="title-actions min-w-0 flex-wrap">
@can('inventory.export')<a class="button button-secondary" href="{{ route('inventory.export', ['location_code' => 'ALM']) }}">Descargar Excel</a><a class="button button-secondary" href="{{ route('inventory.export', ['format' => 'csv', 'location_code' => 'ALM']) }}">Descargar CSV</a>@endcan
@can('inventory.import')<a class="button button-primary" href="{{ route('inventory.import.create', ['location_code' => 'ALM']) }}">Importar Excel / CSV</a>@endcan
@can('imports.view')<a class="button button-secondary" href="{{ route('imports.index') }}">Historial de importaciones</a>@endcan
<a class="button button-secondary" href="{{ route('inventory.requests.index') }}">Solicitudes</a><a class="button button-secondary" href="{{ route('activity.index', ['location' => 'ALM']) }}">Bitácoras</a>
@if($canAdjust)<button class="button button-secondary" type="button" data-toggle-warehouse-edit aria-expanded="false" aria-controls="warehouse-stock-form">Ajustar existencias y límites</button>@endif
</div></section>
@if($errors->any())<div class="notice-info" role="alert">{{ $errors->first() }}</div>@endif
<section class="surface list-surface"><header class="warehouse-stock-filters"><form method="GET" action="{{ route('inventory.index') }}" class="list-filter-bar" aria-label="Filtros de inventario de Almacén"><input type="hidden" name="location" value="ALM"><label class="list-search"><span>⌕</span><input name="search" value="{{ $search }}" placeholder="Producto, marca o SKU en cualquier orden"></label><select name="brand" aria-label="Filtrar por marca"><option value="">Todas las marcas</option>@foreach($brands as $name)<option value="{{ $name }}" @selected($brand === $name)>{{ $name }}</option>@endforeach</select><select name="category" aria-label="Filtrar por categoría"><option value="">Todas las categorías</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>@endforeach</select><label class="check-filter"><input type="checkbox" name="low_stock" value="1" @checked($lowStock)><span class="check-filter-control" aria-hidden="true"></span><span>Bajo mínimo de Almacén</span></label><button class="button button-secondary">Filtrar</button>@if($search || $brand || $categoryId || $lowStock)<a href="{{ route('inventory.index', ['location' => 'ALM']) }}">Limpiar</a>@endif</form><span>{{ $variants->total() }} productos · Existencias en unidades completas</span></header>
<form method="POST" action="{{ route('inventory.adjustments.review') }}" id="warehouse-stock-form">@csrf
<div class="warehouse-stock-scroll"><table class="warehouse-stock-table"><thead><tr><th>Producto / presentación</th><th>Marca / proveedor</th><th>Recepción</th><th>Color Bar</th><th>Almacén</th><th>Total</th>@if($canViewCosts)<th>Costo por unidad</th>@endif<th>Precio Recepción</th><th>Mín. Almacén</th><th>Máx. Almacén</th></tr></thead><tbody>
@forelse($variants as $variant)
@php
    $warehouseBalance = $variant->balances->firstWhere('inventory_location_id', $locations->get('ALM')?->id);
    $warehouseLimits = \App\Services\InventoryLimitService::values($warehouseBalance, $variant);
    $total = $variant->balances->sum('available_quantity');
@endphp
<tr data-stock-row data-variant-id="{{ $variant->id }}"><td class="warehouse-product"><a href="{{ route('products.edit', $variant->product) }}"><strong>{{ $variant->product->name }}</strong></a><small>{{ $variant->name }} · {{ $variant->sku }}</small></td><td>{{ $variant->product->brand }}<small>{{ $variant->product->preferredSupplier?->name ?: 'Sin proveedor' }}</small></td>
@foreach(['REC', 'CB', 'ALM'] as $code)
@php
    $balance = $variant->balances->firstWhere('inventory_location_id', $locations->get($code)?->id);
    $limits = \App\Services\InventoryLimitService::values($balance, $variant);
    $prefix = 'items['.$balance?->id.']';
@endphp
<td class="stock-number {{ $code === 'ALM' && (float) ($balance?->available_quantity ?? 0) <= (float) $warehouseLimits['minimum_stock'] ? 'stock-low' : '' }}" data-stock-location="{{ $code }}"><strong class="{{ $canAdjust && $balance ? 'warehouse-stock-value' : '' }}">{{ number_format((float) ($balance?->available_quantity ?? 0), 0) }}</strong>
@if($canAdjust && $balance)
<input type="hidden" name="{{ $prefix }}[id]" value="{{ $balance->id }}"><input type="hidden" name="{{ $prefix }}[before]" value="{{ $balance->available_quantity }}"><input type="hidden" name="{{ $prefix }}[limits_control]" value="{{ hash_hmac('sha256', json_encode([$balance->id, $limits], JSON_THROW_ON_ERROR), config('app.key')) }}">
<input class="warehouse-stock-editor" aria-label="Existencia {{ $variant->product->name }} {{ $locations->get($code)?->name }}" name="{{ $prefix }}[quantity]" type="number" min="0" step="1" required value="{{ (float) $balance->available_quantity }}" data-stock-quantity>
@if($code !== 'ALM')<input type="hidden" name="{{ $prefix }}[minimum_stock]" value="{{ (float) $limits['minimum_stock'] }}"><input type="hidden" name="{{ $prefix }}[maximum_stock]" value="{{ $limits['maximum_stock'] === null ? '' : (float) $limits['maximum_stock'] }}">@endif
@endif
</td>@endforeach
<td class="stock-number stock-total" data-stock-total>{{ number_format((float) $total, 0) }}</td>@if($canViewCosts)<td class="stock-number">${{ number_format((float) $variant->cost, 2) }}</td>@endif<td class="stock-number">${{ number_format((float) $variant->sale_price, 2) }}</td>
<td class="stock-number"><span class="{{ $canAdjust && $warehouseBalance ? 'warehouse-stock-value' : '' }}">{{ number_format((float) $warehouseLimits['minimum_stock'], 0) }}</span>@if($canAdjust && $warehouseBalance)<input class="warehouse-stock-editor" aria-label="Mínimo de Almacén {{ $variant->product->name }}" name="items[{{ $warehouseBalance->id }}][minimum_stock]" type="number" min="0" step="1" required value="{{ (float) $warehouseLimits['minimum_stock'] }}">@endif</td>
<td class="stock-number"><span class="{{ $canAdjust && $warehouseBalance ? 'warehouse-stock-value' : '' }}">{{ $warehouseLimits['maximum_stock'] === null ? 'Sin máximo' : number_format((float) $warehouseLimits['maximum_stock'], 0) }}</span>@if($canAdjust && $warehouseBalance)<input class="warehouse-stock-editor" aria-label="Máximo de Almacén {{ $variant->product->name }}" name="items[{{ $warehouseBalance->id }}][maximum_stock]" type="number" min="0" step="1" value="{{ $warehouseLimits['maximum_stock'] === null ? '' : (float) $warehouseLimits['maximum_stock'] }}">@endif</td></tr>
@empty<tr><td colspan="{{ $canViewCosts ? 10 : 9 }}" class="empty-state">No hay productos que coincidan con los filtros.</td></tr>@endforelse
</tbody></table></div>
@if($canAdjust)<div class="warehouse-adjustment-actions"><label><span>Motivo del ajuste</span><input name="reason" required minlength="5" maxlength="255" placeholder="Ej. Conteo físico de Almacén"></label><button class="button button-primary">Revisar cambios de esta página</button><small>Captura existencias finales. Se mostrarán las diferencias antes de confirmar. Los límites visibles corresponden a Almacén; los de Recepción y Color Bar se conservan.</small></div>@endif
</form><div class="pagination-row">{{ $variants->links() }}</div></section>
@if($canAdjust)<script>
(() => {
    const form = document.getElementById('warehouse-stock-form');
    const toggle = document.querySelector('[data-toggle-warehouse-edit]');
    const updateTotals = () => form.querySelectorAll('[data-stock-row]').forEach(row => {
        const total = [...row.querySelectorAll('[data-stock-location]')].reduce((sum, cell) => sum + Number(cell.querySelector('[data-stock-quantity]')?.value ?? cell.querySelector('strong').textContent.replaceAll(',', '')), 0);
        row.querySelector('[data-stock-total]').textContent = total.toLocaleString('es-MX', {maximumFractionDigits: 0});
    });
    toggle.addEventListener('click', () => {
        const editing = form.classList.toggle('is-editing');
        toggle.setAttribute('aria-expanded', String(editing));
        toggle.textContent = editing ? 'Cancelar edición' : 'Ajustar existencias y límites';
        if (!editing) { form.reset(); updateTotals(); }
    });
    form.addEventListener('input', event => { if (event.target.matches('[data-stock-quantity]')) updateTotals(); });
})();
</script>@endif
</x-layouts.app>
