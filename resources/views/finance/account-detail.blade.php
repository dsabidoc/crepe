<x-layouts.app :title="$financeAccount->name">
    <section class="page-title">
        <div><p class="eyebrow">CUENTA FINANCIERA</p><h1>{{ $financeAccount->name }}</h1><p>{{ $financeAccount->type === 'bank' ? 'Cuenta bancaria' : ($financeAccount->type === 'cash' ? 'Caja de efectivo' : 'Cuenta operativa') }} · Historial exclusivo de esta cuenta.</p></div>
        <a class="button button-secondary" href="{{ route('finance.index', ['view' => 'accounts']) }}">Volver a cuentas</a>
    </section>

    <section class="metric-grid">
        <article class="metric-card"><span>SALDO ACTUAL</span><strong class="{{ $financeAccount->current_balance >= 0 ? 'positive' : 'negative' }}">${{ number_format((float) $financeAccount->current_balance, 2) }}</strong><small class="neutral">Saldo inicial ${{ number_format((float) $financeAccount->initial_balance, 2) }}</small></article>
        <article class="metric-card"><span>INGRESOS HOY</span><strong class="positive">${{ number_format($todayIncome, 2) }}</strong><small class="neutral">Tickets e ingresos manuales</small></article>
        <article class="metric-card"><span>GASTOS HOY</span><strong class="negative">${{ number_format($todayExpenses, 2) }}</strong><small class="neutral">Movimientos de salida</small></article>
        <article class="metric-card"><span>MOVIMIENTOS</span><strong>{{ $movementRows->count() }}</strong><small class="neutral">Historial registrado</small></article>
    </section>

    <section class="surface list-surface" style="margin-top:16px">
        <header><div><p class="eyebrow">HISTORIAL DE CUENTA</p><h2>Movimientos exclusivos</h2><p>Entradas, salidas, traspasos y pagos de tickets de {{ $financeAccount->name }}.</p></div><span class="record-count">{{ $movementRows->count() }} registros</span></header>
        <div class="data-table">
            <div class="table-head account-movement-head"><span>FECHA</span><span>CONCEPTO</span><span>TIPO</span><span>MONTO</span></div>
            @forelse($movementRows as $movement)
                <div class="table-row account-movement-row"><span>{{ $movement['date'] }}</span><span><strong>{{ $movement['concept'] }}</strong>@if($movement['detail'])<small>{{ $movement['detail'] }}</small>@endif</span><span class="{{ $movement['positive'] ? 'positive' : 'negative' }}">{{ $movement['type'] }}</span><strong class="{{ $movement['positive'] ? 'positive' : 'negative' }}">{{ $movement['positive'] ? '+' : '−' }}${{ number_format($movement['amount'], 2) }}</strong></div>
            @empty
                <p class="empty-state">Esta cuenta aún no tiene movimientos.</p>
            @endforelse
        </div>
    </section>
</x-layouts.app>
