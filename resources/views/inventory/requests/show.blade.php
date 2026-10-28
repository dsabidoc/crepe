<x-layouts.app :title="$inventoryRequest->code">
    <section class="page-title"><div><p class="eyebrow">INVENTARIO · {{ $inventoryRequest->sourceLocation->name }}</p><h1>{{ $inventoryRequest->code }}</h1><p>{{ match ($inventoryRequest->status) { 'closed' => 'Solicitud cerrada', 'cancelled' => 'Solicitud cancelada', default => 'Pendiente de entrega' } }} · {{ $inventoryRequest->requester->name }}</p></div><a class="button button-secondary" href="{{ route('inventory.index', ['location' => $inventoryRequest->sourceLocation->code]) }}">Volver al inventario</a></section>
    <section class="surface notification-detail">
        @if($inventoryRequest->status === 'cancelled')<p class="notice-info"><strong>Solicitud eliminada de pendientes.</strong> No se entregaron productos ni se modificaron existencias. Se conserva este registro para consultar el motivo y quién la canceló.</p>@endif
        @if($inventoryRequest->reversal)<p class="notice-info">Entrega revertida el {{ $inventoryRequest->reversal->created_at->format('d/m/Y H:i') }}. {{ $inventoryRequest->reversal->reason }}</p>@endif
        <p class="eyebrow">{{ $inventoryRequest->status === 'closed' ? 'RESULTADO DE LA ENTREGA' : 'PRODUCTOS SOLICITADOS' }}</p>
        @if($inventoryRequest->processed_at)<p>{{ $inventoryRequest->processor?->name }} · {{ $inventoryRequest->processed_at->format('d/m/Y H:i') }}</p>@endif
        <div class="notification-delivery-list">
            @foreach($inventoryRequest->items as $item)
                @php($requested = (float) ($item->requested_units ?? $item->requested_quantity))
                @php($delivered = (float) ($item->delivered_units ?? $item->delivered_quantity))
                <article class="notification-delivery-row {{ $inventoryRequest->status === 'closed' && $delivered < $requested ? 'is-partial' : '' }}"><div><strong>{{ $item->variant->product->name }}</strong><small>{{ $item->variant->name }} · Disponibles ahora en {{ $inventoryRequest->sourceLocation->name }}: {{ (float) ($item->variant->balances->firstWhere('inventory_location_id', $inventoryRequest->source_location_id)?->available_quantity ?? 0) }} unidades</small></div><span>{{ $inventoryRequest->status === 'closed' ? 'Entregado: '.$delivered.' de ' : 'Solicitado: ' }}{{ $requested }} unidades</span></article>
            @endforeach
        </div>
        @if($inventoryRequest->notes)<p><strong>Solicitud:</strong> {{ $inventoryRequest->notes }}</p>@endif
        @if($inventoryRequest->closure_notes)<p><strong>{{ $inventoryRequest->status === 'cancelled' ? 'Motivo de cancelación:' : 'Nota de Almacén:' }}</strong> {{ $inventoryRequest->closure_notes }}</p>@endif
        @if($inventoryRequest->status === 'closed')<p class="notice-info">La solicitud está cerrada. Los productos no entregados no quedan pendientes en esta solicitud.</p>@endif
        @if(session('crepe.mode') === 'almacen' && auth()->user()->hasRole('Administrador') && $inventoryRequest->status === 'closed' && !$inventoryRequest->reversal)
        @if($inventoryRequest->code === 'SOL-000020' || $inventoryRequest->reversal_hold_reason)<p class="notice-info">Reversión bloqueada hasta confirmar la entrega física de esta solicitud.</p>@else<details><summary>Revertir entrega a Almacén</summary><p>Devuelve todas las unidades de esta entrega. Se verificará que la ubicación destino todavía las tenga disponibles y se conservará el historial.</p><form method="POST" action="{{ route('inventory.requests.reverse', $inventoryRequest) }}" onsubmit="return confirm('¿Confirmas que se devolverán físicamente a Almacén todas las unidades de esta entrega?')">@csrf<input type="hidden" name="key" value="{{ (string) str()->uuid() }}"><label><span>Motivo</span><input name="reason" required minlength="5" maxlength="255"></label><button class="button button-secondary">Confirmar reversión completa</button></form></details>@endif
        @endif
        @can('inventory.requests.manage')@if(session('crepe.mode') === 'almacen' && $inventoryRequest->status === 'pending')<a class="button button-primary" href="{{ route('inventory.requests.edit', $inventoryRequest) }}">Atender solicitud</a>@endif@endcan
        @if($inventoryRequest->status === 'pending' && app(\App\Services\InventoryRequestService::class)->canCancel($inventoryRequest, auth()->user(), session('crepe.mode')))<a class="button button-secondary" href="{{ route('inventory.requests.cancel', $inventoryRequest) }}">Eliminar solicitud</a>@endif
    </section>
</x-layouts.app>
