<x-layouts.app title="Movimientos financieros">
    @php
    @endphp

    <section class="page-title">
        <div>
            <p class="eyebrow">CONTROL FINANCIERO</p>
            <h1>Movimientos</h1>
            <p>Consulta y registra ingresos, egresos, traspasos y ajustes.</p>
        </div>
        <div class="title-actions">
            <button class="button button-secondary" type="button" data-dialog-open="new-account">+ Nueva cuenta</button>
            <button class="button button-primary" type="button" data-dialog-open="new-transaction">+ Agregar movimiento</button>
        </div>
    </section>

    <form method="GET" class="list-filter-bar finance-movements-filter" aria-label="Filtros de finanzas">
        <input type="hidden" name="view" value="movements">
        <div class="finance-movements-filter-primary">
            <label class="list-search"><span>⌕</span><input name="search" value="{{ $search }}" placeholder="Buscar concepto, referencia o ticket..."></label>
            <input name="from" type="date" value="{{ $from }}" aria-label="Desde">
            <input name="to" type="date" value="{{ $to }}" aria-label="Hasta">
            <select name="type" aria-label="Tipo de movimiento"><option value="">Todos los movimientos</option>@foreach(['income' => 'Ingresos', 'expense' => 'Gastos', 'transfer' => 'Traspasos', 'adjustment' => 'Ajustes'] as $key => $label)<option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>@endforeach</select>
            <select name="account" aria-label="Cuenta"><option value="">Todas las cuentas</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected($accountId === $account->id)>{{ $account->name }}</option>@endforeach</select>
        </div>
        <div class="finance-movements-filter-actions">
            <button class="button button-secondary" type="submit">Filtrar</button>
            @if($search || $type || $accountId || $from || $to)<a class="filter-clear" href="{{ route('finance.index', ['view' => 'movements']) }}">Limpiar</a>@endif
        </div>
    </form>

    <section class="metric-grid">
        <article class="metric-card"><span>TOTAL INGRESOS</span><strong class="positive">${{ number_format($incomeTotal, 2) }}</strong><small class="neutral">Tickets + manuales</small></article>
        <article class="metric-card"><span>TOTAL GASTOS</span><strong class="negative">${{ number_format($expenses, 2) }}</strong><small class="neutral">Gastos registrados</small></article>
        <article class="metric-card"><span>TOTAL MOVIMIENTOS</span><strong>{{ number_format($movementCount) }}</strong><small class="neutral">Pagos y registros</small></article>
        <article class="metric-card"><span>INGRESOS EN EFECTIVO</span><strong class="positive">${{ number_format($cashIncome, 2) }}</strong><small class="neutral">Pagos y movimientos</small></article>
        <article class="metric-card"><span>INGRESOS BANCARIOS</span><strong class="positive">${{ number_format($bankIncome, 2) }}</strong><small class="neutral">Pagos y movimientos</small></article>
    </section>

    <section class="surface list-surface" style="margin-top:16px">
        <header><div><p class="eyebrow">HISTORIAL FINANCIERO</p><h2>Todos los movimientos</h2><p>Pagos de tickets, ingresos, gastos, traspasos y ajustes en una sola vista.</p></div><span class="record-count">{{ $movementRows->count() }} registros</span></header>
        <div class="data-table">
            <div class="table-head" style="grid-template-columns:.9fr 1.6fr 1fr .9fr .8fr"><span>FECHA</span><span>CONCEPTO</span><span>CUENTA</span><span>TIPO</span><span>MONTO</span></div>
            @forelse($movementRows as $movement)
                <div class="table-row" style="grid-template-columns:.9fr 1.6fr 1fr .9fr .8fr"><span>{{ $movement['date'] }}</span><span><strong>{{ $movement['concept'] }}</strong>@if($movement['detail'])<small>{{ $movement['detail'] }}</small>@endif</span><span>{{ $movement['account'] }}</span><span class="{{ $movement['class'] }}">{{ $movement['type'] }}</span><strong class="{{ $movement['class'] }}">{{ $movement['prefix'] }}${{ number_format($movement['amount'], 2) }}</strong></div>
            @empty
                <p class="empty-state">No hay movimientos con estos filtros.</p>
            @endforelse
        </div>
    </section>

    <dialog class="cash-cut-dialog" id="new-transaction">
        <form method="POST" action="{{ route('finance.transactions.store') }}" enctype="multipart/form-data">@csrf
            <header><div><p class="eyebrow">NUEVO MOVIMIENTO</p><h2>Registrar ingreso o egreso</h2><p>Captura un movimiento que no venga de un ticket.</p></div><button class="dialog-close" type="button" data-dialog-close>×</button></header>
            <div class="field-grid" style="padding:22px 24px"><label><span>Tipo</span><select name="type" id="movement-type" required><option value="income">Ingreso</option><option value="expense">Gasto</option><option value="transfer">Traspaso</option><option value="adjustment">Ajuste</option></select></label><label><span>Cuenta</span><select name="finance_account_id" required>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></label><label id="movement-destination"><span>Cuenta destino</span><select name="transfer_to_account_id"><option value="">Selecciona cuenta destino</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></label><label id="movement-category"><span>Categoría de gasto</span><select name="finance_expense_category_id"><option value="">Selecciona una categoría</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label><label id="movement-direction"><span>El movimiento</span><select name="direction"><option value="in">Suma al saldo</option><option value="out">Resta al saldo</option></select></label><label><span>Concepto / motivo</span><input name="concept" required maxlength="255" placeholder="Ej. compra de insumos"></label><label><span>Monto</span><input name="amount" type="number" min=".01" step=".01" required placeholder="0.00"></label><label><span>Fecha</span><input name="occurred_on" type="date" value="{{ now()->toDateString() }}" required></label><label><span>Referencia (opcional)</span><input name="reference" maxlength="120"></label><label class="full-field" style="grid-column:1/-1"><span>Notas (opcional)</span><textarea name="notes" rows="3"></textarea></label><label class="full-field movement-evidence-field"><span>Comprobante <small>Opcional · JPG, PNG o PDF · máximo 10 MB</small></span><input id="movement-evidence" name="evidence" type="file" accept="image/jpeg,image/png,application/pdf"><span class="movement-evidence-preview" id="movement-evidence-preview" hidden><img id="movement-evidence-image" alt="Vista previa del comprobante" hidden><span id="movement-evidence-name"></span><button class="button button-secondary" type="button" id="movement-evidence-remove">Eliminar</button></span></label></div>
            <footer><button class="button button-secondary" type="button" data-dialog-close>Cancelar</button><button class="button button-primary" type="submit">Guardar movimiento</button></footer>
        </form>
    </dialog>

    <dialog class="cash-cut-dialog" id="new-account">
        <form method="POST" action="{{ route('finance.accounts.store') }}">@csrf
            <header><div><p class="eyebrow">CUENTAS</p><h2>Agregar cuenta</h2><p>Registra una cuenta para ordenar tus movimientos.</p></div><button class="dialog-close" type="button" data-dialog-close>×</button></header>
            <div class="field-grid" style="padding:22px 24px"><label><span>Nombre</span><input name="name" required maxlength="120" placeholder="Ej. Banco principal"></label><label><span>Tipo</span><select name="type" required><option value="cash">Efectivo</option><option value="bank">Bancaria</option><option value="other">Otra</option></select></label><label><span>Saldo inicial</span><input name="initial_balance" type="number" min="0" step=".01" value="0" required></label></div>
            <footer><button class="button button-secondary" type="button" data-dialog-close>Cancelar</button><button class="button button-primary" type="submit">Guardar cuenta</button></footer>
        </form>
    </dialog>

    <script>
        document.querySelectorAll('[data-dialog-open]').forEach((button) => button.addEventListener('click', () => document.getElementById(button.dataset.dialogOpen)?.showModal()));
        document.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
        const movementType = document.getElementById('movement-type');
        const toggleMovementFields = () => { const type = movementType?.value; document.getElementById('movement-destination')?.toggleAttribute('hidden', type !== 'transfer'); document.getElementById('movement-category')?.toggleAttribute('hidden', type !== 'expense'); document.getElementById('movement-direction')?.toggleAttribute('hidden', !['transfer', 'adjustment'].includes(type)); };
        movementType?.addEventListener('change', toggleMovementFields); toggleMovementFields();
        const evidence = document.getElementById('movement-evidence'); const evidencePreview = document.getElementById('movement-evidence-preview'); const evidenceImage = document.getElementById('movement-evidence-image'); const evidenceName = document.getElementById('movement-evidence-name');
        evidence?.addEventListener('change', () => { const file = evidence.files[0]; evidencePreview.hidden = !file; evidenceName.textContent = file?.name || ''; evidenceImage.hidden = !file || !file.type.startsWith('image/'); if (file?.type.startsWith('image/')) evidenceImage.src = URL.createObjectURL(file); });
        document.getElementById('movement-evidence-remove')?.addEventListener('click', () => { evidence.value = ''; evidencePreview.hidden = true; evidenceImage.src = ''; });
        if (new URLSearchParams(window.location.search).get('modal') === 'transaction') document.getElementById('new-transaction')?.showModal();
    </script>
</x-layouts.app>
