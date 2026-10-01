<x-layouts.app title="Cuentas por pagar">
    <section class="page-heading"><div><p class="eyebrow">CONTROL FINANCIERO</p><h1>Cuentas por pagar</h1><p>Facturas recibidas de proveedores, sus fechas de pago y abonos.</p></div></section>
    <form method="GET" class="list-filter-bar" aria-label="Filtros de cuentas por pagar"><label class="list-search"><span>⌕</span><input name="search" value="{{ $search }}" placeholder="Proveedor, orden o factura"></label><select name="status" aria-label="Filtrar por estatus"><option value="">Todos los estatus</option><option value="pending" @selected($status === 'pending')>Pendientes</option><option value="partial" @selected($status === 'partial')>Con abono</option><option value="paid" @selected($status === 'paid')>Pagadas</option></select><button class="button button-secondary" type="submit">Filtrar</button>@if($search || $status)<a class="filter-clear" href="{{ route('payables.index') }}">Limpiar</a>@endif</form>

    <section class="payroll-items">
        @forelse($invoices as $invoice)
            <article class="surface payroll-item">
                <header>
                    <div>
                        <h2>{{ $invoice->purchaseOrder->supplier->name }}</h2>
                        <p><a href="{{ route('purchase-orders.show', $invoice->purchaseOrder) }}">{{ $invoice->purchaseOrder->code }}</a> · recibida {{ $invoice->purchaseOrder->received_at?->format('d/m/Y') ?? '—' }} · vence {{ $invoice->due_on->format('d/m/Y') }}</p>
                    </div>
                    <div class="payable-item-actions"><span class="status-badge">{{ $invoice->status === 'paid' ? 'Pagada' : ($invoice->status === 'partial' ? 'Abono parcial' : 'Pendiente') }}</span><a class="button button-secondary" href="{{ route('payables.show', $invoice) }}">Ver detalle</a></div>
                </header>
                <div class="payable-invoice-meta">
                    <span>Factura: <strong>{{ $invoice->invoice_reference ?: 'Sin folio' }}</strong></span>
                    @if($invoice->invoice_path)
                        <a href="{{ asset('storage/'.$invoice->invoice_path) }}" target="_blank" rel="noopener">Ver comprobante ↗</a>
                    @endif
                </div>
                <div class="payroll-amounts"><div><span>Factura</span><strong>${{ number_format((float) $invoice->amount, 2) }}</strong></div><div><span>Abonado</span><strong>${{ number_format((float) $invoice->paid_amount, 2) }}</strong></div><div class="payroll-net"><span>Saldo</span><strong>${{ number_format((float) $invoice->amount - (float) $invoice->paid_amount, 2) }}</strong></div></div>

                @if($invoice->payments->isNotEmpty())
                    <div class="payable-payment-history">
                        <strong>Abonos registrados</strong>
                        @foreach($invoice->payments as $payment)
                            <span>{{ $payment->paid_on->format('d/m/Y') }} · {{ $payment->account?->name ?? 'Cuenta eliminada' }} · ${{ number_format((float) $payment->amount, 2) }}@if($payment->reference) · {{ $payment->reference }}@endif</span>
                        @endforeach
                    </div>
                @endif

                @if($invoice->status !== 'paid')
                    <form method="POST" action="{{ route('payables.payments.store', $invoice) }}" class="payroll-deductions payable-payment-form">@csrf
                        <label><span>Cuenta de origen</span><select name="finance_account_id" required>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></label>
                        <label><span>Abono</span><input name="amount" type="number" min=".01" max="{{ (float) $invoice->amount - (float) $invoice->paid_amount }}" step=".01" required></label>
                        <label><span>Fecha</span><input name="paid_on" type="date" value="{{ now()->toDateString() }}" required></label>
                        <label><span>Referencia <small>Opcional</small></span><input name="reference" maxlength="120" placeholder="Transferencia, folio, etc."></label>
                        <label><span>Notas <small>Opcional</small></span><input name="notes" maxlength="1000" placeholder="Observación del pago"></label>
                        <button class="button button-primary" type="submit">Registrar abono</button>
                    </form>
                @endif
            </article>
        @empty
            <p class="empty-state">No hay facturas pendientes de proveedor.</p>
        @endforelse
    </section>
</x-layouts.app>
