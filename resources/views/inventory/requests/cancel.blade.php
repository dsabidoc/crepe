<x-layouts.app title="Eliminar solicitud">
    <section class="page-title">
        <div><p class="eyebrow">{{ $inventoryRequest->code }} · {{ $inventoryRequest->sourceLocation->name }}</p><h1>Eliminar solicitud pendiente</h1><p>Solicitó {{ $inventoryRequest->requester->name }} el {{ $inventoryRequest->created_at->format('d/m/Y H:i') }}.</p></div>
        <a class="button button-secondary" href="{{ route('inventory.requests.index') }}">Volver a solicitudes</a>
    </section>
    <form class="surface inventory-request-form" method="POST" action="{{ route('inventory.requests.destroy', $inventoryRequest) }}">
        @csrf
        @method('DELETE')
        <p class="notice-info">La solicitud <strong>{{ $inventoryRequest->code }}</strong> saldrá de pendientes y quedará cancelada en el historial. Esta acción no entrega productos ni modifica las existencias de Almacén, Recepción o Color Bar.</p>
        <p>{{ $inventoryRequest->items->count() }} productos incluidos en la solicitud.</p>
        <label><span>Motivo de eliminación</span><textarea name="reason" rows="3" required minlength="5" maxlength="255" placeholder="Ej. Solicitud duplicada o creada por error">{{ old('reason') }}</textarea></label>
        @error('reason')<p class="form-error" role="alert">{{ $message }}</p>@enderror
        <footer><a class="button button-secondary" href="{{ route('inventory.requests.index') }}">Conservar solicitud</a><button class="button button-primary" type="submit">Confirmar eliminación</button></footer>
    </form>
</x-layouts.app>
