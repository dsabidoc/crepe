@props(['product' => null, 'field' => null])

@php
    $brand = $product?->brand ?: 'Sin marca';
    $supplier = $product?->preferredSupplier?->name ?: 'Sin proveedor';
@endphp

@if($field)
    <span {{ $attributes->class(['product-reference-cell']) }} data-label="{{ $field === 'brand' ? 'Marca' : 'Proveedor' }}" title="{{ $field === 'brand' ? $brand : $supplier }}">{{ $field === 'brand' ? $brand : $supplier }}</span>
@else
    <span {{ $attributes->class(['product-reference']) }}>
        <span><span class="product-reference-label">Marca</span><span title="{{ $brand }}">{{ $brand }}</span></span>
        <span><span class="product-reference-label">Proveedor</span><span title="{{ $supplier }}">{{ $supplier }}</span></span>
    </span>
@endif
