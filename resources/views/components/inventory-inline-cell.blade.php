@props(['variant', 'field', 'value', 'location' => null, 'editable' => true])
@php
    $money = in_array($field, ['cost', 'sale_price'], true);
    $allowed = $editable && \App\Services\WarehouseEditService::canEdit(auth()->user(), $variant, $field);
    $labels = ['quantity' => 'Existencia', 'cost' => 'Costo', 'sale_price' => 'Precio', 'minimum_stock' => 'Mínimo de Almacén', 'maximum_stock' => 'Máximo de Almacén'];
    $label = $labels[$field].' '.$variant->product->name.($field === 'quantity' ? ' '.['ALM' => 'Almacén', 'REC' => 'Recepción', 'CB' => 'Color Bar'][$location] : '');
    $display = $value === null ? 'Sin máximo' : ($money ? '$' : '').number_format((float) $value, $money ? 2 : 0);
    $inputValue = $value === null ? '' : number_format((float) $value, $money ? 2 : 0, '.', '');
@endphp
<span class="inline-cell">
@if($allowed)
<button type="button" class="inline-cell-trigger" data-edit-cell aria-label="Editar {{ $label }}"><strong>{{ $display }}</strong></button>
<input hidden class="inline-cell-input" type="number" min="0" max="{{ $money ? '99999999.99' : '999999999' }}" step="{{ $money ? '.01' : '1' }}" @required($field !== 'maximum_stock') value="{{ $inputValue }}" aria-label="{{ $label }}" data-inline-value data-variant="{{ $variant->id }}" data-field="{{ $field }}" data-location="{{ $location }}" data-before="{{ $value }}" data-original="{{ $inputValue }}" data-label="{{ $label }}">
@else<strong>{{ $display }}</strong>@endif
</span>
