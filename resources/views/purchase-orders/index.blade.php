<x-layouts.app title="Órdenes de compra">
    <section class="page-heading">
        <div><p class="eyebrow">ALMACÉN</p><h1>Órdenes de compra</h1><p>Consulta pedidos por proveedor, revisa su estatus y abre la recepción cuando lleguen.</p></div>
        <div class="title-actions"><a class="button button-secondary" href="{{ route('products.catalogs') }}">Catálogos</a><a class="button button-primary" href="{{ route('purchase-orders.create') }}">+ Nueva orden</a></div>
    </section>

    <section class="surface list-surface">
        <header class="list-controls">
            <div><p class="eyebrow">PEDIDOS</p><h2>Listado de órdenes</h2></div>
            <form method="GET" class="list-filter-bar standard-filter-bar" aria-label="Filtros de órdenes de compra">
                <label class="list-search"><span>⌕</span><input name="search" value="{{ $search }}" placeholder="Folio, proveedor o factura"></label>
                <select name="supplier" aria-label="Filtrar por proveedor"><option value="">Todos los proveedores</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected($supplierId === $supplier->id)>{{ $supplier->name }}</option>@endforeach</select>
                <select name="status" aria-label="Filtrar por estatus"><option value="">Todos los estatus</option><option value="draft" @selected($status === 'draft')>Borradores</option><option value="received" @selected($status === 'received')>Recibidas</option></select>
                <button class="button button-secondary" type="submit">Filtrar</button>
                @if($search || $status || $supplierId)<a class="filter-clear" href="{{ route('purchase-orders.index') }}">Limpiar</a>@endif
            </form>
            <span class="record-count">{{ $orders->count() }} órdenes</span>
        </header>
        <style>.order-register-scroll{overflow:auto}.order-register{width:100%;min-width:1130px;border-collapse:collapse;text-align:left}.order-register th{padding:14px 12px;background:#f6f8fb;color:#63748a;font-size:11px;white-space:nowrap}.order-register td{padding:14px 12px;border-bottom:1px solid #e5e9ef;vertical-align:middle;font-size:13px}.order-register small{display:block;margin-top:4px;color:#718096;font-size:11px}.order-register .order-amount{white-space:nowrap;font-variant-numeric:tabular-nums}.order-register .order-supplier{min-width:150px}.order-register .order-detail{white-space:nowrap;color:#ad4869}</style>
        <div class="order-register-scroll"><table class="order-register"><thead><tr><th>Folio / factura</th><th>Proveedor</th><th>Fecha</th><th>Productos</th><th>Unidades pedidas</th><th>Unidades recibidas</th><th>Importe</th><th>Abonado</th><th>Saldo</th><th>Estado</th><th></th></tr></thead><tbody>
            @forelse($orders as $order)
                @php($summary = $summaries[$order->id])
                <tr data-order-id="{{ $order->id }}"><td><a href="{{ route('purchase-orders.show', $order) }}"><strong>{{ $order->code }}</strong></a><small>{{ $order->payableInvoice?->invoice_reference ?: 'Sin factura' }}</small></td><td class="order-supplier">{{ $order->supplier->name }}</td><td>{{ $order->created_at->format('d/m/Y') }}@if($order->received_at)<small>Recibida {{ $order->received_at->format('d/m/Y H:i') }}</small>@endif</td><td>{{ $summary['products'] }}</td><td>{{ number_format($summary['ordered_units'], 0) }}</td><td>{{ number_format($summary['received_units'], 0) }}</td><td class="order-amount"><strong>${{ number_format($summary['total'], 2) }}</strong><small>{{ $order->payableInvoice ? 'Facturado' : 'Estimado' }}</small></td><td class="order-amount">{{ $summary['paid'] === null ? '—' : '$'.number_format($summary['paid'], 2) }}</td><td class="order-amount">{{ $summary['balance'] === null ? '—' : '$'.number_format($summary['balance'], 2) }}@if($order->payableInvoice)<small>Vence {{ $order->payableInvoice->due_on->format('d/m/Y') }}</small>@endif</td><td><span class="status-badge">{{ $order->status === 'received' ? 'Recibida' : 'Borrador' }}</span>@if($order->payableInvoice)<small>{{ ['paid' => 'Pagada', 'partial' => 'Abono parcial', 'pending' => 'Pago pendiente'][$order->payableInvoice->status] ?? $order->payableInvoice->status }}</small>@endif@if($order->receivedBy)<small>{{ $order->receivedBy->name }}</small>@endif</td><td><a class="order-detail" href="{{ route('purchase-orders.show', $order) }}">Ver detalle →</a></td></tr>
            @empty
                <tr><td colspan="11" class="empty-state">Aún no hay órdenes de compra. <a href="{{ route('purchase-orders.create') }}">Crear la primera orden</a></td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</x-layouts.app>
