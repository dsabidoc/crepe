@php($title = ['administracion' => 'Administración', 'recepcion' => 'Recepción', 'color-bar' => 'Color Bar', 'almacen' => 'Almacén'][$mode])
<x-layouts.app :title="$title">
@if($mode === 'administracion')
<section class="page-title"><div><p class="eyebrow">RESUMEN DEL DÍA</p><h1>Buenos días, {{ str(auth()->user()->name)->before(' ') }}.</h1><p>Datos operativos actualizados de CREPÉ.</p></div><a class="button button-primary" href="{{ route('reports.index') }}">Ver reportes <span>→</span></a></section>
<section class="metric-grid"><article class="metric-card"><span>VENTAS HOY</span><strong>${{ number_format($salesToday, 0) }}</strong><small class="neutral">Total real de tickets del día</small></article><article class="metric-card"><span>TICKETS ABIERTOS</span><strong>{{ $tickets->count() }}</strong><small class="neutral">En operación o servicio</small></article><article class="metric-card"><span>CITAS HOY</span><strong>{{ $appointments->count() }}</strong><small class="neutral">Agenda confirmada</small></article><article class="metric-card"><span>STOCK BAJO</span><strong>{{ $lowStock->count() }}</strong><small class="neutral">Unidades bajo mínimo de referencia</small></article></section>
<section class="dashboard-grid"><article class="surface table-surface"><header><div><h2>Agenda de hoy</h2><p>{{ now()->translatedFormat('l, d \d\e F') }}</p></div><a href="{{ route('agenda.index') }}">Ver agenda →</a></header><div class="agenda-list">@forelse($appointments as $appointment)<a class="agenda-row" href="{{ $appointment->ticket ? route('tickets.show', $appointment->ticket) : route('agenda.index') }}"><time>{{ $appointment->starts_at->format('H:i') }}</time><div><strong>{{ $appointment->customer->full_name }}</strong><span>{{ $appointment->services->pluck('name_snapshot')->join(' + ') }} · {{ $appointment->employee->full_name }}</span></div><span class="pill {{ str($appointment->status)->slug('-') }}">{{ str($appointment->status)->headline() }}</span></a>@empty<p class="empty-state">No hay citas para hoy.</p>@endforelse</div></article><article class="surface stock-surface"><header><div><h2>Atención requerida</h2><p>Inventario y operación</p></div></header><div class="alert-list">@forelse($lowStock->take(3) as $balance)<a href="{{ route('inventory.index') }}"><span class="alert-mark">!</span><p><strong>{{ $balance->variant->product->name }} con stock bajo</strong><small>{{ $balance->location->name }} · {{ $balance->available_quantity }} {{ $balance->variant->base_unit }}</small></p><b>→</b></a>@empty<a href="{{ route('inventory.index') }}"><span class="alert-mark amber">✓</span><p><strong>Inventario en niveles saludables</strong><small>Ver existencias y movimientos</small></p><b>→</b></a>@endforelse</div></article></section>
@elseif($mode === 'recepcion')
<section class="page-title"><div><p class="eyebrow">OPERACIÓN DEL DÍA</p><h1>Recepción</h1><p>Encuentra una clienta, abre su ticket o crea una cita.</p></div><div class="title-actions"><button class="button button-secondary" type="button" data-dialog-open="day-availability">Disponibilidad del día</button>@if($cashSession)<button class="button button-secondary" type="button" data-cash-movement-type="expense">+ Agregar gasto</button><button class="button button-secondary" type="button" data-cash-movement-type="income">+ Agregar ingreso</button>@endif<a class="button button-secondary" href="{{ route('customers.create') }}">+ Nueva clienta</a><a class="button button-secondary" href="{{ route('tickets.product-sales.create') }}">+ Venta de producto</a><a class="button button-primary" href="{{ route('appointments.create') }}">+ Nueva cita</a></div></section>
<section class="reception-toolbar"><form class="search-box" method="GET" action="{{ route('tickets.index') }}"><span>⌕</span><input name="search" value="{{ request('search') }}" placeholder="Buscar clienta o ticket..."><button type="submit">Buscar</button></form><div class="segmented"><a class="{{ !request('status') ? 'selected' : '' }}" href="{{ route('workspace', 'recepcion') }}">Todos</a><a href="{{ route('agenda.index') }}">Agenda</a><a href="{{ route('tickets.index', ['status' => 'in_service']) }}">En servicio</a><a href="{{ route('tickets.index', ['status' => 'open']) }}">Por cobrar</a></div></section>
<section class="ticket-grid">@forelse($tickets as $ticket)@php($ticketDisplayName = $ticket->customer?->full_name ?? 'Venta de mostrador')<a class="ticket-card" href="{{ route('tickets.show', $ticket) }}"><header><div class="customer-avatar">{{ str($ticket->customer?->first_name ?? 'V')->substr(0, 1) }}</div><span class="pill {{ $ticket->status === 'in_service' ? 'en-servicio' : 'confirmada' }}">{{ $ticket->status === 'in_service' ? 'En servicio' : 'Abierto' }}</span></header><h2>{{ $ticketDisplayName }}</h2>@if($ticket->ticket_type === 'product_sale')<p>Venta de producto</p>@else<p>{{ $ticket->appointment?->starts_at?->format('h:i A') ?: 'Sin cita' }} · {{ $ticket->appointment?->services?->pluck('name_snapshot')->join(' + ') ?: 'Venta en recepción' }}</p>@endif<div class="ticket-meta"><span>{{ $ticket->appointment?->employee?->full_name ?: 'Sin estilista asignada' }}</span><strong>Saldo ${{ number_format($ticket->balance, 0) }}</strong></div><span class="card-link">Abrir ticket <span>→</span></span></a>@empty<p class="empty-state">No hay tickets abiertos. <a href="{{ route('appointments.create') }}">Crear una cita</a></p>@endforelse</section>
@if($shouldStartReceptionDay)
<dialog class="cash-cut-dialog" id="start-reception-day">
    <form method="POST" action="{{ route('cash.sessions.start-day') }}">
        @csrf
        <header><div><p class="eyebrow">INICIO DEL DÍA</p><h2>Comenzar mi día</h2><p>Confirma el fondo de cambio que recibiste para iniciar operaciones.</p></div></header>
        @if($availableCashRegister)
            <div class="cash-cut-total"><span>Tu caja de hoy</span><strong>{{ $availableCashRegister->name }}</strong><small>Los cobros que registres se enviarán automáticamente a esta caja.</small></div>
            <label class="cash-notes"><span>Fondo de cambio recibido</span><small>Confirma que recibiste ${{ number_format($defaultOpeningFloat, 2) }} pesos o ajusta el monto real.</small><input name="opening_float" type="number" min="0" step=".01" value="{{ number_format($defaultOpeningFloat, 2, '.', '') }}" required autofocus></label>
            @error('opening_float')<p class="form-error">{{ $message }}</p>@enderror
            <footer><button class="button button-primary" type="submit">Abrir caja</button></footer>
        @else
            <p class="form-error">No hay una caja disponible para iniciar tu día. Solicita apoyo a administración.</p>
        @endif
    </form>
</dialog>
@endif
<dialog class="availability-dialog" id="day-availability"><form method="dialog"><header><div><p class="eyebrow">RECEPCIÓN</p><h2>Disponibilidad del día</h2><p>Consulta los horarios libres de una estilista.</p></div><button class="dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button></header><div class="availability-filters"><label><span>Estilista</span><select id="availability-employee"><option value="">Selecciona una estilista</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }}</option>@endforeach</select></label><label><span>Fecha</span><input id="availability-date" type="date" value="{{ now()->toDateString() }}"></label><label><span>Duración</span><select id="availability-duration"><option value="30">30 min</option><option value="60" selected>1 hora</option><option value="90">1 h 30 min</option><option value="120">2 horas</option></select></label></div><div class="availability-results" id="availability-results"><p class="availability-empty">Selecciona una estilista para ver sus horarios disponibles.</p></div><footer><button class="button button-secondary" type="button" data-dialog-close>Cerrar</button></footer></form></dialog>
@if($cashSession)
<dialog class="cash-cut-dialog reception-movement-dialog" id="reception-cash-movement">
    <form method="POST" action="{{ route('reception.cash-movements.store') }}" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="type" id="reception-movement-type" value="{{ old('type', 'expense') }}">
        <header><div><p class="eyebrow" id="reception-movement-eyebrow">MOVIMIENTO DE CAJA</p><h2 id="reception-movement-title">Agregar gasto</h2><p id="reception-movement-description">El importe se descontará directamente de tu caja abierta.</p></div><button class="dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button></header>
        <div class="reception-movement-fields">
            <label id="reception-expense-category"><span>Categoría de gasto</span><select name="finance_expense_category_id"><option value="">Selecciona una categoría</option>@foreach($expenseCategories as $category)<option value="{{ $category->id }}" @selected((int) old('finance_expense_category_id') === $category->id)>{{ $category->name }}</option>@endforeach</select></label>
            <label><span>Concepto o motivo</span><input name="concept" maxlength="255" value="{{ old('concept') }}" placeholder="Ej. compra urgente de insumos" required></label>
            <div class="field-grid"><label><span>Monto</span><input name="amount" type="number" min=".01" step=".01" value="{{ old('amount') }}" placeholder="0.00" required></label><label><span>Fecha</span><input name="occurred_on" type="date" value="{{ old('occurred_on', now()->toDateString()) }}" readonly required><small>Se registra en tu día de caja actual.</small></label></div>
            <label><span>Referencia <small>Opcional</small></span><input name="reference" maxlength="120" value="{{ old('reference') }}" placeholder="Folio, proveedor o referencia"></label>
            <label><span>Notas <small>Opcional</small></span><textarea name="notes" rows="3" maxlength="2000" placeholder="Información adicional">{{ old('notes') }}</textarea></label>
            <label class="receipt-upload"><span>Foto o comprobante <small>Opcional · JPG, PNG o WEBP · máx. 10 MB</small></span><input id="reception-movement-evidence" name="evidence" type="file" accept="image/jpeg,image/png,image/webp"><span class="receipt-upload-button">Subir foto</span><img id="reception-movement-preview" alt="Vista previa del comprobante" hidden></label>
            @foreach(['type', 'finance_expense_category_id', 'concept', 'amount', 'occurred_on', 'reference', 'notes', 'evidence', 'cash_session'] as $field)@error($field)<p class="form-error">{{ $message }}</p>@enderror@endforeach
        </div>
        <footer><button class="button button-secondary" type="button" data-dialog-close>Cancelar</button><button class="button button-primary" type="submit" id="reception-movement-submit">Guardar gasto</button></footer>
    </form>
</dialog>
@endif
<script>
    (() => {
        document.getElementById('start-reception-day')?.showModal();
        const dialog = document.getElementById('day-availability');
        if (!dialog) return;
        const employee = document.getElementById('availability-employee');
        const date = document.getElementById('availability-date');
        const duration = document.getElementById('availability-duration');
        const results = document.getElementById('availability-results');
        const availabilityUrl = @json(route('appointments.availability'));
        const appointmentUrl = @json(route('appointments.create'));
        const render = async () => {
            if (!employee.value) {
                results.innerHTML = '<p class="availability-empty">Selecciona una estilista para ver sus horarios disponibles.</p>';
                return;
            }
            results.innerHTML = '<p class="availability-loading">Consultando horarios disponibles…</p>';
            const params = new URLSearchParams({ date: date.value, duration_minutes: duration.value, 'employee_ids[]': employee.value });
            try {
                const response = await fetch(`${availabilityUrl}?${params}`);
                const slots = response.ok ? (await response.json()).available : [];
                if (!slots.length) {
                    results.innerHTML = '<p class="availability-empty">No hay horarios disponibles para esta fecha y duración.</p>';
                    return;
                }
                results.innerHTML = `<p class="availability-summary">${slots.length} horarios disponibles · ${employee.options[employee.selectedIndex].text}</p><div class="availability-slots">${slots.map((slot) => `<a class="availability-slot" href="${appointmentUrl}?date=${encodeURIComponent(date.value)}&time=${encodeURIComponent(slot)}&employee_id=${encodeURIComponent(employee.value)}"><span>◷</span><strong>${slot}</strong><small>${slot} · ${duration.options[duration.selectedIndex].text}</small></a>`).join('')}</div>`;
            } catch {
                results.innerHTML = '<p class="availability-empty">No fue posible consultar la disponibilidad. Intenta de nuevo.</p>';
            }
        };
        document.querySelector('[data-dialog-open="day-availability"]')?.addEventListener('click', () => { dialog.showModal(); render(); });
        [employee, date, duration].forEach((field) => field.addEventListener('change', render));
        document.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));

        const movementDialog = document.getElementById('reception-cash-movement');
        const movementType = document.getElementById('reception-movement-type');
        const movementTitle = document.getElementById('reception-movement-title');
        const movementDescription = document.getElementById('reception-movement-description');
        const movementCategory = document.getElementById('reception-expense-category');
        const movementSubmit = document.getElementById('reception-movement-submit');
        const evidenceInput = document.getElementById('reception-movement-evidence');
        const evidencePreview = document.getElementById('reception-movement-preview');
        let evidenceUrl = null;
        const configureMovementDialog = (type) => {
            if (!movementDialog) return;
            const expense = type === 'expense';
            movementType.value = type;
            movementTitle.textContent = expense ? 'Agregar gasto' : 'Agregar ingreso';
            movementDescription.textContent = expense
                ? 'El importe se descontará directamente de tu caja abierta.'
                : 'El importe se sumará directamente a tu caja abierta.';
            movementCategory.hidden = !expense;
            movementCategory.querySelector('select').required = expense;
            movementSubmit.textContent = expense ? 'Guardar gasto' : 'Guardar ingreso';
        };
        document.querySelectorAll('[data-cash-movement-type]').forEach((button) => button.addEventListener('click', () => {
            configureMovementDialog(button.dataset.cashMovementType);
            movementDialog.showModal();
        }));
        evidenceInput?.addEventListener('change', () => {
            if (evidenceUrl) URL.revokeObjectURL(evidenceUrl);
            const [file] = evidenceInput.files;
            if (!file) {
                evidencePreview.hidden = true;
                evidencePreview.removeAttribute('src');
                return;
            }
            evidenceUrl = URL.createObjectURL(file);
            evidencePreview.src = evidenceUrl;
            evidencePreview.hidden = false;
        });
        @if(old('type') && $errors->any())
            configureMovementDialog(@json(old('type')));
            movementDialog?.showModal();
        @endif
    })();
</script>
@elseif($mode === 'almacen')
<section class="page-title"><div><p class="eyebrow">CONTROL DE INVENTARIO</p><h1>Almacén</h1><p>Administra productos, existencias y movimientos para las áreas de CREPÉ.</p></div><div class="title-actions"><a class="button button-secondary" href="{{ route('inventory.index', ['location' => 'ALM']) }}">Ver inventario</a><a class="button button-primary" href="{{ route('products.create') }}">+ Dar de alta producto</a></div></section>
<section class="storage-module-grid"><a class="surface storage-module-card" href="{{ route('products.index') }}"><span class="module-icon">＋</span><div><strong>Productos</strong><small>Da de alta y actualiza productos y presentaciones.</small></div><span>→</span></a><a class="surface storage-module-card" href="{{ route('inventory.index', ['location' => 'ALM']) }}"><span class="module-icon">▦</span><div><strong>Existencias</strong><small>Consulta el inventario disponible en el almacén.</small></div><span>→</span></a><a class="surface storage-module-card" href="{{ route('activity.index', ['location' => 'ALM']) }}"><span class="module-icon">◷</span><div><strong>Bitácoras</strong><small>Consulta movimientos, traspasos y solicitudes cerradas.</small></div><span>→</span></a><a class="surface storage-module-card" href="{{ route('inventory.requests.index') }}"><span class="module-icon">⌁</span><div><strong>Solicitudes de inventario</strong><small>Autoriza entregas para Recepción y Color Bar, totales o parciales.</small></div><span>→</span></a></section>
@else
<section class="page-title"><div><p class="eyebrow">FORMULACIÓN Y CONSUMO</p><h1>Tickets activos</h1><p>Selecciona un ticket y registra consumos reales en gramos, mililitros o unidades.</p></div><a class="button button-secondary" href="{{ route('inventory.index', ['location' => 'CB']) }}">Inventario Color Bar</a></section>
<section class="color-layout"><div class="surface color-queue"><header><div><h2>Requieren Color Bar</h2><p>{{ $tickets->count() }} tickets activos</p></div><a class="mini-search" href="{{ route('color-bar.index') }}">Registrar fórmula →</a></header>@forelse($tickets as $ticket)<a class="color-ticket" href="{{ route('color-bar.index', ['ticket' => $ticket->id]) }}"><span class="customer-avatar">{{ str($ticket->customer->first_name)->substr(0, 1) }}</span><span><strong>{{ $ticket->customer->full_name }}</strong><small>{{ $ticket->appointment?->services?->pluck('name_snapshot')->join(' + ') ?: 'Ticket sin cita' }} · {{ $ticket->appointment?->employee?->full_name ?: 'Sin asignar' }}</small></span><time>{{ $ticket->appointment?->starts_at?->format('H:i') ?: '—' }}</time><b>→</b></a>@empty<p class="empty-state">No hay tickets activos para formular.</p>@endforelse</div><aside class="formula-preview"><div class="preview-icon">✦</div><h2>Registrar consumo</h2><p>Abre un ticket de la lista para ir directo al registro de fórmula y descontar el inventario real.</p><a class="button button-primary" href="{{ route('color-bar.index') }}">Ir a Color Bar</a></aside></section>
@endif
</x-layouts.app>
