<x-layouts.app title="Inventario por ubicación">
<style>.stock-matrix{overflow:auto}.stock-matrix table{width:100%;border-collapse:collapse;min-width:920px}.stock-matrix th,.stock-matrix td{padding:12px;border-bottom:1px solid #e5e9ef;text-align:left;vertical-align:top}.stock-matrix th{font-size:12px;background:#f6f8fb}.stock-matrix td small{display:block;color:#718096;margin-top:5px}.stock-matrix input[type=number]{width:90px;padding:9px}.stock-matrix .stock-limits{display:flex;gap:6px;margin-top:8px}.stock-matrix .stock-limits input{width:88px}.stock-matrix .stock-limits label{display:grid;gap:4px}.stock-matrix .stock-limits label{font-size:11px;color:#718096}.stock-matrix .stock-low{color:#ad4869}.stock-matrix-actions{display:flex;gap:16px;align-items:end;padding:20px;flex-wrap:wrap}.stock-matrix-actions label{flex:1;min-width:240px}</style>
<section class="page-title"><div><p class="eyebrow">INVENTARIO · TRES UBICACIONES</p><h1>Existencias por ubicación</h1><p>Envases y productos completos. Cada ubicación conserva sus propios mínimos y máximos.</p></div><a class="button button-secondary" href="{{ route('inventory.index', ['location' => 'ALM']) }}">Inventario individual</a></section>
<form method="GET" class="list-filter-bar"><input name="search" value="{{ $search }}" placeholder="Producto, marca o SKU en cualquier orden"><select name="brand"><option value="">Todas las marcas</option>@foreach($brands as $name)<option @selected($brand === $name)>{{ $name }}</option>@endforeach</select><button class="button button-secondary">Filtrar</button><a href="{{ route('inventory.matrix') }}">Limpiar</a></form>
@if($errors->any())<div class="notice-info">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('inventory.adjustments.review') }}">@csrf
<div class="surface stock-matrix"><table><thead><tr><th>Producto / presentación</th><th>Marca / proveedor</th>@foreach($locations as $location)<th>{{ $location->name }}</th>@endforeach</tr></thead><tbody>
@foreach($variants as $variant)<tr><td><a href="{{ route('products.edit', $variant->product) }}"><strong>{{ $variant->product->name }}</strong></a><small>{{ $variant->name }} · {{ $variant->sku }}</small></td><td>{{ $variant->product->brand }}<small>{{ $variant->product->preferredSupplier?->name }}</small></td>
@foreach($locations as $location)
@php
$balance = $variant->balances->firstWhere('inventory_location_id', $location->id);
$limits = \App\Services\InventoryLimitService::values($balance, $variant);
$prefix = 'items['.$balance?->id.']';
@endphp
<td class="{{ (float) ($balance?->available_quantity ?? 0) <= (float) $limits['minimum_stock'] ? 'stock-low' : '' }}">
@if(auth()->user()->hasRole('Administrador') && $balance)
<input type="hidden" name="{{ $prefix }}[id]" value="{{ $balance->id }}"><input type="hidden" name="{{ $prefix }}[before]" value="{{ $balance->available_quantity }}"><input type="hidden" name="{{ $prefix }}[limits_control]" value="{{ hash_hmac('sha256', json_encode([$balance->id, $limits], JSON_THROW_ON_ERROR), config('app.key')) }}">
<input aria-label="Existencia {{ $variant->product->name }} {{ $location->name }}" name="{{ $prefix }}[quantity]" type="number" min="0" step="1" required value="{{ (float) $balance->available_quantity }}"><small>unidades</small>
<div class="stock-limits"><label>Mínimo<input name="{{ $prefix }}[minimum_stock]" type="number" min="0" step="1" required value="{{ (float) $limits['minimum_stock'] }}"></label><label>Máximo<input name="{{ $prefix }}[maximum_stock]" type="number" min="0" step="1" value="{{ $limits['maximum_stock'] === null ? '' : (float) $limits['maximum_stock'] }}"></label></div>
@else<strong>{{ (float) ($balance?->available_quantity ?? 0) }}</strong><small>unidades · mín. {{ (float) $limits['minimum_stock'] }} / máx. {{ $limits['maximum_stock'] === null ? 'Sin límite' : (float) $limits['maximum_stock'] }}</small>@endif
</td>@endforeach</tr>@endforeach
</tbody></table></div>
@if(auth()->user()->hasRole('Administrador'))<div class="stock-matrix-actions"><label><span>Motivo del ajuste</span><input name="reason" required minlength="5" maxlength="255" placeholder="Ej. Conteo físico de cierre"></label><button class="button button-primary">Revisar cambios de esta página</button><small>Se mostrarán las diferencias antes de confirmar. Cambia de página después de guardar.</small></div>@endif
</form>{{ $variants->links() }}
</x-layouts.app>
