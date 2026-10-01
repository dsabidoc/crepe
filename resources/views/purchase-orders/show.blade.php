<x-layouts.app title="Orden de compra">
    <section class="page-heading">
        <div><p class="eyebrow">ORDEN DE COMPRA</p><h1>{{ $purchaseOrder->code }}</h1><p>{{ $purchaseOrder->supplier->name }} · {{ $purchaseOrder->status === 'received' ? 'Recibida' : 'Pendiente de recepción' }}</p></div>
        <div class="title-actions"><a class="button button-secondary" href="{{ route('purchase-orders.download', $purchaseOrder) }}">↓ Descargar PDF</a><a class="button button-secondary" href="{{ route('purchase-orders.index') }}">Volver</a></div>
    </section>

    <section class="surface">
        <div class="data-table">
            <div class="table-head inventory-head"><span>PRODUCTO</span><span>PEDIDO</span><span>RECIBIDO</span><span>COSTO</span></div>
            @foreach($purchaseOrder->items as $item)
                <div class="table-row inventory-row">
                    <strong>{{ $item->variant->product->name }} · {{ $item->variant->name }}</strong>
                    <span>@if($purchaseOrder->status === 'draft')<form method="POST" action="{{ route('purchase-orders.items.update', [$purchaseOrder, $item]) }}" class="purchase-order-quantity-form">@csrf @method('PUT')<input name="ordered_quantity" type="number" step=".001" min=".001" value="{{ $item->ordered_quantity }}"><button type="submit">Guardar</button></form>@else{{ $item->ordered_quantity }}@endif</span>
                    <span>{{ $item->received_quantity }}</span>
                    <span>${{ number_format((float) $item->cost_snapshot, 2) }}</span>
                    @if($purchaseOrder->status === 'draft')<form method="POST" action="{{ route('purchase-orders.items.destroy', [$purchaseOrder, $item]) }}" onsubmit="return confirm('¿Quitar este producto de la orden?')">@csrf @method('DELETE')<button class="link-danger" type="submit">Quitar</button></form>@endif
                </div>
            @endforeach
        </div>

        @if($purchaseOrder->status === 'draft')
            <form method="POST" action="{{ route('purchase-orders.items.store', $purchaseOrder) }}" class="form-section purchase-order-add-item">@csrf
                <h2>Agregar producto</h2>
                <div class="field-grid"><label><span>Producto</span><select name="product_variant_id" required><option value="">Selecciona un producto</option>@foreach($variants as $variant)<option value="{{ $variant->id }}">{{ $variant->product->name }} · {{ $variant->name }}</option>@endforeach</select></label><label><span>Cantidad</span><input name="ordered_quantity" type="number" min=".001" step=".001" required></label></div>
                <button class="button button-secondary" type="submit">Agregar partida</button>
            </form>
            <form method="POST" action="{{ route('purchase-orders.receive', $purchaseOrder) }}" enctype="multipart/form-data" class="form-section">@csrf
                <h2>Recibir pedido</h2><p>Captura la cantidad recibida; aunque sea menor, esta orden se cierra sin pendientes y se crea la cuenta por pagar.</p>
                <div class="field-grid"><label><span>Factura</span><input type="file" name="invoice" accept="image/jpeg,image/png,application/pdf"></label><label><span>Folio factura</span><input name="invoice_reference"></label><label><span>Monto total factura</span><input type="number" name="amount" min=".01" step=".01" required></label>@foreach($purchaseOrder->items as $item)<label><span>{{ $item->variant->product->name }} · recibido</span><input type="number" name="items[{{ $item->id }}]" min="0" max="{{ $item->ordered_quantity }}" step=".001" value="{{ $item->ordered_quantity }}"></label>@endforeach</div>
                <button class="button button-primary" type="submit">Registrar recepción y CxP</button>
            </form>
        @elseif($purchaseOrder->payableInvoice)
            <div class="notice-success">Cuenta por pagar: ${{ number_format((float) $purchaseOrder->payableInvoice->amount, 2) }} · vence {{ $purchaseOrder->payableInvoice->due_on->format('d/m/Y') }}</div>
        @endif
    </section>
</x-layouts.app>
