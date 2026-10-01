<x-layouts.app title="Detalle de nómina">
    <div class="payroll-page">
    <section class="page-heading payroll-page-heading">
        <div>
            <p class="eyebrow">NÓMINA {{ str_pad((string) $payrollRun->payroll_number, 2, '0', STR_PAD_LEFT) }}</p>
            <h1>{{ $payrollRun->period_starts_on->translatedFormat('d M Y') }} — {{ $payrollRun->period_ends_on->translatedFormat('d M Y') }}</h1>
            <p>{{ $payrollRun->items->count() }} personas · {{ $payrollRun->includes_product_commissions ? 'Incluye' : 'No incluye' }} comisión de productos.</p>
        </div>
        <a class="button button-secondary" href="{{ route('payroll.index') }}">Volver a nómina</a>
    </section>

    <section class="metric-grid payroll-metrics">
        <article class="metric-card"><span>TOTAL DE NÓMINA</span><strong>${{ number_format($payrollTotal, 2) }}</strong><small class="neutral">Monto neto tras descuentos</small></article>
        <article class="metric-card"><span>RETIRADO</span><strong class="negative">${{ number_format($withdrawnTotal, 2) }}</strong><small class="neutral">{{ $withdrawals->count() }} {{ $withdrawals->count() === 1 ? 'retiro registrado' : 'retiros registrados' }}</small></article>
        <article class="metric-card"><span>PENDIENTE DE RETIRAR</span><strong class="{{ $withdrawalRemaining > 0 ? 'positive' : 'neutral' }}">${{ number_format($withdrawalRemaining, 2) }}</strong><small class="neutral">Puede dividirse entre efectivo y banco</small></article>
    </section>

    <section class="surface form-section payroll-withdrawal-card">
        <header>
            <div>
                <p class="eyebrow">PAGO DE NÓMINA</p>
                <h2>{{ $withdrawalRemaining > 0 ? 'Generar retiro de nómina' : 'Retiro completo' }}</h2>
                <p>{{ $withdrawalRemaining > 0 ? 'Registra el importe real y la cuenta desde la que se pagará. Puedes hacer varios retiros.' : 'El total de esta nómina ya quedó registrado en Finanzas.' }}</p>
            </div>
        </header>
        @if($withdrawalRemaining > 0)
            <form method="POST" action="{{ route('payroll.withdrawals.store', $payrollRun) }}" class="payroll-withdrawal-form" enctype="multipart/form-data">
                @csrf
                <label><span>Cuenta de origen</span><select data-native-select="true" name="finance_account_id" required><option value="">Selecciona una cuenta</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></label>
                <label><span>Monto a retirar</span><input name="amount" type="number" step=".01" min=".01" max="{{ number_format($withdrawalRemaining, 2, '.', '') }}" value="{{ number_format($withdrawalRemaining, 2, '.', '') }}" required></label>
                <label><span>Fecha</span><input name="occurred_on" type="date" value="{{ now()->toDateString() }}" required></label>
                <label><span>Referencia <small>Opcional</small></span><input name="reference" maxlength="120" placeholder="Ej. transferencia de nómina"></label>
                <label class="payroll-evidence"><span>Comprobante <small>Opcional · PDF, JPG o PNG</small></span><input name="evidence" type="file" accept=".pdf,.jpg,.jpeg,.png"></label>
                <button class="button button-primary" type="submit">Registrar retiro</button>
            </form>
        @endif
        @if($withdrawals->isNotEmpty())
            <div class="payroll-withdrawal-history">
                <strong>Retiros registrados</strong>
                @foreach($withdrawals as $withdrawal)
                    <span>{{ $withdrawal->occurred_on->format('d/m/Y') }} · {{ $withdrawal->account?->name ?? 'Cuenta eliminada' }} · ${{ number_format((float) $withdrawal->amount, 2) }}@if($withdrawal->reference) · {{ $withdrawal->reference }}@endif @if($withdrawal->evidence_path)· <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($withdrawal->evidence_path) }}" target="_blank" rel="noopener">Ver comprobante</a>@endif</span>
                @endforeach
            </div>
        @endif
    </section>

    <section class="payroll-items-section">
        <header class="payroll-items-heading">
            <div><p class="eyebrow">PERSONAS INCLUIDAS</p><h2>Detalle de sueldos y descuentos</h2><p>Actualiza los descuentos desde un solo formulario para mantener el total neto al día.</p></div>
            <button class="button button-primary" type="button" id="add-payroll-discount">Agregar descuento</button>
        </header>
        <div class="payroll-items">
            @forelse($payrollRun->items as $item)
                <article class="surface payroll-item">
                    <header><div><h2>{{ $item->employee_name_snapshot }}</h2><p>{{ $item->employee?->position ?? 'Colaboradora' }}</p></div><a class="button button-secondary" href="{{ route('payroll.items.receipt', [$payrollRun, $item]) }}">↓ Descargar recibo</a></header>
                    <div class="payroll-amounts"><div><span>Sueldo base</span><strong>${{ number_format((float) $item->base_pay, 2) }}</strong></div><div><span>Comisiones servicio</span><strong>${{ number_format((float) $item->service_commissions, 2) }}</strong></div><div><span>Comisiones producto</span><strong>${{ number_format((float) $item->product_commissions, 2) }}</strong></div><div class="payroll-net"><span>Total neto</span><strong>${{ number_format((float) $item->total, 2) }}</strong></div></div>
                    <div class="payroll-discounts-summary">
                        <span>Infonavit <strong>${{ number_format((float) $item->infonavit_deduction, 2) }}</strong></span>
                        <span>Otros descuentos <strong>${{ number_format((float) $item->other_deductions, 2) }}</strong></span>
                        <span>Retardos <strong>${{ number_format((float) $item->tardiness_deduction, 2) }}</strong></span>
                        <button class="button button-secondary payroll-discount-edit" type="button" data-employee="{{ $item->id }}" data-type="infonavit_deduction" data-amount="{{ $item->infonavit_deduction }}">Actualizar descuentos</button>
                    </div>
                </article>
            @empty
                <p class="empty-state">No se encontraron colaboradoras con sueldo o comisión para este periodo.</p>
            @endforelse
        </div>
    </section>

    <dialog class="payroll-discount-dialog" id="payroll-discount-dialog" aria-labelledby="payroll-discount-title">
        <form method="POST" action="{{ route('payroll.discounts.update', $payrollRun) }}" id="payroll-discount-form">
            @csrf @method('PUT')
            <header><div><p class="eyebrow">DESCUENTOS</p><h2 id="payroll-discount-title">Agregar descuento</h2><p>Selecciona la colaboradora y captura el importe que se descontará.</p></div><button class="dialog-close" type="button" data-close-discount aria-label="Cerrar">×</button></header>
            <label><span>Colaboradora</span><select data-native-select="true" name="payroll_item_id" id="discount-employee" required>@foreach($payrollRun->items as $item)<option value="{{ $item->id }}">{{ $item->employee_name_snapshot }}</option>@endforeach</select></label>
            <label><span>Tipo de descuento</span><select data-native-select="true" name="discount_type" id="discount-type" required><option value="infonavit_deduction">Infonavit</option><option value="other_deductions">Otros descuentos</option><option value="tardiness_deduction">Retardos</option></select></label>
            <label><span>Monto</span><input name="amount" id="discount-amount" type="number" min="0" step=".01" value="0.00" required></label>
            <footer><button class="button button-secondary" type="button" data-close-discount>Cancelar</button><button class="button button-primary" type="submit">Guardar descuento</button></footer>
        </form>
    </dialog>

    <script>
        (() => {
            const dialog = document.getElementById('payroll-discount-dialog');
            const employee = document.getElementById('discount-employee');
            const type = document.getElementById('discount-type');
            const amount = document.getElementById('discount-amount');
            const open = (button, fresh = false) => {
                if (fresh) { employee.selectedIndex = 0; type.value = 'infonavit_deduction'; amount.value = '0.00'; }
                else { employee.value = button.dataset.employee; type.value = button.dataset.type; amount.value = button.dataset.amount || '0.00'; }
                dialog.showModal();
            };
            document.getElementById('add-payroll-discount')?.addEventListener('click', () => open(null, true));
            document.querySelectorAll('.payroll-discount-edit').forEach((button) => button.addEventListener('click', () => open(button)));
            document.querySelectorAll('[data-close-discount]').forEach((button) => button.addEventListener('click', () => dialog.close()));
            dialog?.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
        })();
    </script>
    </div>
</x-layouts.app>
