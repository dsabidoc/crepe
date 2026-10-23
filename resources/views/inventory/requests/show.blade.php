<x-layouts.app :title="$inventoryRequest->code">
    <section class="page-title"><div><p class="eyebrow">INVENTARIO · {{ $inventoryRequest->sourceLocation->name }}</p><h1>{{ $inventoryRequest->code }}</h1><p>{{ $inventoryRequest->status === 'closed' ? 'Solicitud cerrada' : 'Pendiente de entrega' }} · {{ $inventoryRequest->requester->name }}</p></div><a class="button button-secondary" href="{{ route('inventory.index', ['location' => $inventoryRequest->sourceLocation->code]) }}">Volver al inventario</a></section>
    <section class="surface notification-detail">
        <p class="eyebrow">{{ $inventoryRequest->status === 'closed' ? 'RESULTADO DE LA ENTREGA' : 'PRODUCTOS SOLICITADOS' }}</p>
        @if($inventoryRequest->processed_at)<p>{{ $inventoryRequest->processor?->name }} · {{ $inventoryRequest->processed_at->format('d/m/Y H:i') }}</p>@endif
        <div class="notification-delivery-list">
            @foreach($inventoryRequest->items as $item)
                @php($requested = (float) ($item->requested_units ?? $item->requested_quantity))
                @php($delivered = (float) ($item->delivered_units ?? $item->delivered_quantity))
                <article class="notification-delivery-row {{ $inventoryRequest->status === 'closed' && $delivered < $requested ? 'is-partial' : '' }}"><div><strong>{{ $item->variant->product->name }}</strong><small>{{ $item->variant->name }}</small></div><span>{{ $inventoryRequest->status === 'closed' ? 'Entregado: '.$delivered.' de ' : 'Solicitado: ' }}{{ $requested }} unidades</span></article>
            @endforeach
        </div>
        @if($inventoryRequest->notes)<p><strong>Solicitud:</strong> {{ $inventoryRequest->notes }}</p>@endif
        @if($inventoryRequest->closure_notes)<p><strong>Nota de Almacén:</strong> {{ $inventoryRequest->closure_notes }}</p>@endif
        @if($inventoryRequest->status === 'closed')<p class="notice-info">La solicitud está cerrada. Los productos no entregados no quedan pendientes en esta solicitud.</p>@endif
        @can('inventory.requests.manage')@if($inventoryRequest->status === 'pending')<a class="button button-primary" href="{{ route('inventory.requests.edit', $inventoryRequest) }}">Atender solicitud</a>@endif@endcan
    </section>
</x-layouts.app>
