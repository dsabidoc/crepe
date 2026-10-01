<x-layouts.app title="Detalle de nómina">
    <section class="page-heading">
        <div><p class="eyebrow">NÓMINA {{ str_pad((string) $payrollRun->payroll_number, 2, '0', STR_PAD_LEFT) }}</p><h1>{{ $payrollRun->period_starts_on->translatedFormat('d M Y') }} — {{ $payrollRun->period_ends_on->translatedFormat('d M Y') }}</h1><p>{{ $payrollRun->items->count() }} personas · {{ $payrollRun->includes_product_commissions ? 'Incluye' : 'No incluye' }} comisión de productos.</p></div>
        <a class="button button-secondary" href="{{ route('payroll.index') }}">Volver a nómina</a>
    </section>

    <section class="metric-grid">
        <article class="metric-card"><span>TOTAL DE NÓMINA</span><strong>${{ number_format($payrollTotal, 2) }}</strong><small class="neutral">Monto neto tras descuentos</small></article>
        <article class="metric-card"><span>RETIRADO</span><strong class="negative">${{ number_format($withdrawnTotal, 2) }}</strong><small class="neutral">{{ $withdrawals->count() }} {{ $withdrawals->count() === 1 ? 'retiro registrado' : 'retiros registrados' }}</small></article>
        <article class="metric-card"><span>PENDIENTE DE RETIRAR</span><strong class="{{ $withdrawalRemaining > 0 ? 'positive' : 'neutral' }}">${{ number_format($withdrawalRemaining, 2) }}</strong><small class="neutral">Puede dividirse entre efectivo y banco</small></article>
    </section>

    <section class="surface form-section payroll-withdrawal-card">
        <header><div><p class="eyebrow">PAGO DE NÓMINA</p><h2>{{ $withdrawalRemaining > 0 ? 'Generar retiro de nómina' : 'Retiro completo' }}</h2><p>{{ $withdrawalRemaining > 0 ? 'Registra el importe real y la cuenta desde la que se pagará. Puedes hacer varios retiros.' : 'El total de esta nómina ya quedó registrado en Finanzas.' }}</p></div></header>
        @if($withdrawalRemaining > 0)
            <form method="POST" action="{{ route('payroll.withdrawals.store', $payrollRun) }}" class="payroll-deductions">@csrf
                <label><span>Cuenta de origen</span><select name="finance_account_id" required>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></label>
                <label><span>Monto a retirar</span><input name="amount" type="number" step=".01" min=".01" max="{{ number_format($withdrawalRemaining, 2, '.', '') }}" value="{{ number_format($withdrawalRemaining, 2, '.', '') }}" required></label>
                <label><span>Fecha</span><input name="occurred_on" type="date" value="{{ now()->toDateString() }}" required></label>
                <label><span>Referencia <small>Opcional</small></span><input name="reference" maxlength="120" placeholder="Ej. transferencia de nómina"></label>
                <button class="button button-primary" type="submit">Registrar retiro</button>
            </form>
        @endif
        @if($withdrawals->isNotEmpty())
            <div class="payroll-withdrawal-history"><strong>Retiros registrados</strong>@foreach($withdrawals as $withdrawal)<span>{{ $withdrawal->occurred_on->format('d/m/Y') }} · {{ $withdrawal->account?->name ?? 'Cuenta eliminada' }} · ${{ number_format((float) $withdrawal->amount, 2) }}@if($withdrawal->reference) · {{ $withdrawal->reference }}@endif</span>@endforeach</div>
        @endif
    </section>

    <section class="payroll-items">
        @forelse($payrollRun->items as $item)
            <article class="surface payroll-item">
                <header><div><h2>{{ $item->employee_name_snapshot }}</h2><p>{{ $item->employee?->position ?? 'Colaboradora' }}</p></div><a class="button button-secondary" href="{{ route('payroll.items.receipt', [$payrollRun, $item]) }}">↓ Descargar recibo</a></header>
                <div class="payroll-amounts"><div><span>Sueldo base</span><strong>${{ number_format((float) $item->base_pay, 2) }}</strong></div><div><span>Comisiones servicio</span><strong>${{ number_format((float) $item->service_commissions, 2) }}</strong></div><div><span>Comisiones producto</span><strong>${{ number_format((float) $item->product_commissions, 2) }}</strong></div><div class="payroll-net"><span>Total neto</span><strong>${{ number_format((float) $item->total, 2) }}</strong></div></div>
                <form method="POST" action="{{ route('payroll.items.update', [$payrollRun, $item]) }}" class="payroll-deductions">@csrf @method('PUT')<label><span>Infonavit</span><input name="infonavit_deduction" type="number" min="0" step=".01" value="{{ $item->infonavit_deduction }}"></label><label><span>Otros descuentos</span><input name="other_deductions" type="number" min="0" step=".01" value="{{ $item->other_deductions }}"></label><label><span>Retardos</span><input name="tardiness_deduction" type="number" min="0" step=".01" value="{{ $item->tardiness_deduction }}"></label><button class="button button-secondary" type="submit">Actualizar descuentos</button></form>
            </article>
        @empty
            <p class="empty-state">No se encontraron colaboradoras con sueldo o comisión para este periodo.</p>
        @endforelse
    </section>
</x-layouts.app>
