<x-layouts.app title="Procesar solicitud">
    <style>
        .request-process-actions { display:flex; flex-wrap:wrap; gap:10px; }
        .app-body .request-process-grid { grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
        .app-body .request-process-grid > .request-process-card { display:flex; flex-direction:column; align-items:stretch; gap:14px; min-width:0; padding:20px; }
        .app-body .request-process-card strong { font-size:13px; line-height:1.5; overflow-wrap:anywhere; }
        .app-body .request-process-card small { line-height:1.6; }
        .app-body .request-process-card .request-product-references { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
        .app-body .request-product-references .product-reference-cell { white-space:normal; overflow-wrap:anywhere; }
        .app-body .request-product-references .product-reference-cell::before { content:attr(data-label); display:block; margin-bottom:4px; color:var(--muted); font-size:10px; }
        .app-body .request-process-card .request-delivery { display:flex; align-items:center; flex-wrap:wrap; gap:10px; margin-top:auto; padding-top:12px; border-top:1px solid var(--line); color:#8e3f5c; font-size:11px; font-weight:700; }
        .app-body .request-delivery input { width:80px; margin-left:auto; }
        @media (max-width:600px) { .app-body .request-process-grid { grid-template-columns:minmax(0,1fr); } }
    </style>
    <section class="page-title">
        <div>
            <p class="eyebrow">ALMACÉN · {{ $inventoryRequest->code }}</p>
            <h1>Entregar y cerrar solicitud</h1>
            <p>{{ $inventoryRequest->sourceLocation->name }} · solicitó {{ $inventoryRequest->requester->name }} el {{ $inventoryRequest->created_at->format('d/m/Y H:i') }}.</p>
        </div>
        <div class="request-process-actions">
            <a class="button button-secondary" href="{{ route('inventory.requests.download', $inventoryRequest) }}">Exportar PDF</a>
            <a class="button button-secondary" href="{{ route('inventory.requests.index') }}">Cancelar</a>
        </div>
    </section>
    <form class="surface inventory-request-form" method="POST" action="{{ route('inventory.requests.close', $inventoryRequest) }}">
        @csrf
@if($inventoryRequest->created_at->lt(now()->subDays(2)))<div class="notice-info"><strong>Solicitud antigua: {{ $inventoryRequest->code }}</strong> · creada el {{ $inventoryRequest->created_at->format('d/m/Y H:i') }}. Verifica que esta sea la entrega que estás preparando.<label style="display:flex;gap:10px;margin-top:10px"><input style="width:18px" type="checkbox" name="confirm_old_request" value="1" required>Confirmo que corresponde a la entrega física actual.</label></div>@endif
        <div class="request-process-notice">Captura cuántas unidades entregarás. Si Almacén no tiene todo, entrega sólo las unidades disponibles y cierra la solicitud con una nota.</div>
        <div class="request-process-list request-process-grid">
            @foreach($inventoryRequest->items as $item)
                @php
                    $stock = (float) $item->variant->balances->first()?->available_quantity;
                    $requestedUnits = (float) ($item->requested_units ?? $item->requested_quantity);
                    $availableUnits = (int) floor($stock);
                    $max = min($requestedUnits, $availableUnits);
                @endphp
                <label class="request-process-card">
                    <div>
                        <strong>{{ $item->variant->product->name }}</strong>
                        <small>{{ $item->variant->name }} · Solicitado: {{ $requestedUnits }} unidades · Disponible: {{ $availableUnits }} unidades</small>
                    </div>
                    <div class="request-product-references">
                        <x-product-reference :product="$item->variant->product" field="brand" />
                        <x-product-reference :product="$item->variant->product" field="supplier" />
                    </div>
                    <div class="request-delivery">
                        <span>Entregar</span>
                        <input aria-label="Unidades de {{ $item->variant->product->name }}" type="number" name="items[{{ $item->id }}]" min="0" max="{{ $max }}" step="1" value="{{ old('items.'.$item->id, $max) }}">
                        <span>unidades</span>
                    </div>
                </label>
            @endforeach
        </div>
        <label class="inventory-request-notes">
            <span>Comentario del cierre</span>
            <textarea name="closure_notes" rows="3" placeholder="Ej. Se entregó lo disponible; el resto no queda pendiente.">{{ old('closure_notes') }}</textarea>
        </label>
        <footer>
            <a href="{{ route('inventory.requests.index') }}">Cancelar</a>
            <button class="button button-primary" type="submit">Entregar y cerrar solicitud</button>
        </footer>
    </form>
</x-layouts.app>
