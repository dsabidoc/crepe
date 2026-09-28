<x-layouts.app title="Cortes de caja">
    @php
        $statusLabels = ['open' => 'Abierta', 'pending_review' => 'Pendiente de revisión', 'verified' => 'Confirmado'];
        $statusClasses = ['open' => 'confirmada', 'pending_review' => 'programada', 'verified' => 'en-servicio'];
    @endphp

    <section class="page-title">
        <div>
            <p class="eyebrow">CAJA Y CORTES</p>
            <h1>Cortes de caja</h1>
            <p>Conciliación diaria por caja, con verificación de administración.</p>
        </div>
    </section>

    <form method="GET" class="list-filter-bar cash-filter-bar" aria-label="Filtros del historial de caja">
        <select name="register" aria-label="Filtrar por caja"><option value="">Todas las cajas</option>@foreach($registers as $register)<option value="{{ $register->id }}" @selected($registerId === $register->id)>{{ $register->name }}</option>@endforeach</select>
        <select name="status" aria-label="Filtrar por estado"><option value="">Todos los estados</option><option value="open" @selected($status === 'open')>Abiertas</option><option value="pending_review" @selected($status === 'pending_review')>Pendientes</option><option value="verified" @selected($status === 'verified')>Confirmadas</option></select>
        <label class="date-filter"><span>Desde</span><input name="from" type="date" value="{{ $from }}"></label><label class="date-filter"><span>Hasta</span><input name="to" type="date" value="{{ $to }}"></label>
        <button class="button button-secondary" type="submit">Filtrar</button>
        @if($registerId || $status || $from || $to)<a class="filter-clear" href="{{ route('cash.index') }}">Limpiar</a>@endif
    </form>

    @if ($canAuthorize && $pendingCuts->isNotEmpty())
        <section class="surface cash-pending-panel">
            <header>
                <div>
                    <p class="eyebrow">ADMINISTRACIÓN</p>
                    <h2>Cortes pendientes de verificar</h2>
                    <p>Revisa los importes declarados y confirma el corte cuando estén correctos.</p>
                </div>
                <span class="cash-pending-count">{{ $pendingCuts->count() }}</span>
            </header>
            <div class="cash-pending-list">
                @foreach ($pendingCuts as $session)
                    <article>
                        <div>
                            <strong>{{ $session->register->name }}</strong>
                            <small>{{ $session->business_date?->translatedFormat('d \d\e F \d\e Y') ?? 'Corte histórico' }} · {{ $session->payments->pluck('ticket_id')->unique()->count() }} tickets</small>
                        </div>
                        <div class="cash-difference {{ abs((float) $session->difference) < .01 ? 'matches' : 'does-not-match' }}">
                            <span>Diferencia</span>
                            <strong>{{ (float) $session->difference >= 0 ? '+' : '−' }}${{ number_format(abs((float) $session->difference), 2) }}</strong>
                        </div>
                        <button class="button button-primary" type="button" data-dialog-open="review-cut-{{ $session->id }}">Revisar corte</button>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <section class="metric-grid cash-metrics">
        <article class="metric-card"><span>CAJAS ABIERTAS HOY</span><strong>{{ $todayOpenSessionsCount }}</strong><small class="positive">De {{ $registers->count() }} cajas activas</small></article>
        <article class="metric-card"><span>PENDIENTES DE REVISIÓN</span><strong>{{ $pendingCuts->count() }}</strong><small class="{{ $pendingCuts->isNotEmpty() ? 'neutral' : 'positive' }}">{{ $pendingCuts->isNotEmpty() ? 'Requieren administración' : 'Sin cortes por validar' }}</small></article>
        <article class="metric-card"><span>FONDO POR CAJA</span><strong>${{ number_format($defaultOpeningFloat, 2) }}</strong><small class="neutral">Cambio inicial diario</small></article>
    </section>

    <section class="cash-register-grid">
        @foreach ($registers as $register)
            @php
                $session = $todaySessions->get($register->id);
                $totals = $session?->payments->where('status', 'registered')->groupBy('method') ?? collect();
                $expectedCash = (float) ($totals->get('cash')?->sum('amount') ?? 0);
                $expectedCard = (float) ($totals->get('card')?->sum('amount') ?? 0);
                $expectedTransfer = (float) ($totals->get('transfer')?->sum('amount') ?? 0);
                $expectedOther = (float) ($totals->get('other')?->sum('amount') ?? 0);
            @endphp
            <article class="surface cash-register-card">
                <header>
                    <div><p class="eyebrow">{{ $register->code }}</p><h2>{{ $register->name }}</h2><p>{{ $session ? ($session->status === 'open' ? 'Abierta desde '.$session->opened_at->format('H:i') : 'Corte generado a las '.$session->closed_at?->format('H:i')) : 'Sin movimientos hoy' }}</p></div>
                    <span class="pill {{ $statusClasses[$session?->status] ?? 'programada' }}">{{ $session ? strtoupper($statusLabels[$session->status]) : 'SIN ABRIR' }}</span>
                </header>
                <dl>
                    <div><dt>Fondo de cambio</dt><dd>${{ number_format((float) ($session?->opening_float ?? 2500), 2) }}</dd></div>
                    <div><dt>Tickets atendidos</dt><dd>{{ $session?->payments->pluck('ticket_id')->unique()->count() ?? 0 }}</dd></div>
                    <div><dt>Cobros registrados</dt><dd>${{ number_format($expectedCash + $expectedCard + $expectedTransfer + $expectedOther, 2) }}</dd></div>
                </dl>
                @if ($session?->status === 'open')
                    <button class="button button-primary" type="button" data-dialog-open="generate-cut-{{ $session->id }}">✓ Verificar corte</button>
                @elseif ($session)
                    <span class="button button-secondary cash-cut-complete">{{ $session->status === 'verified' ? '✓ Corte confirmado' : 'Corte enviado a revisión' }}</span>
                @elseif (! $isAdmin && in_array($register->id, $occupiedRegisterIds, true))
                    <span class="button button-secondary cash-cut-complete">Caja ocupada por otra recepción</span>
                @else
                    <button class="button button-secondary" type="button" data-dialog-open="open-cash-{{ $register->id }}">Abrir caja</button>
                @endif
            </article>

            @if ($session?->status === 'open')
                <dialog class="cash-cut-dialog" id="generate-cut-{{ $session->id }}">
                    <form method="POST" action="{{ route('cash.cuts.store', $session) }}">
                        @csrf
                        <header><div><p class="eyebrow">GENERAR CORTE</p><h2>Verificar corte · {{ $register->name }}</h2><p>{{ $session->business_date?->translatedFormat('l d \d\e F \d\e Y') }}</p></div><button class="dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button></header>
                        <div class="cash-cut-total"><span>Tickets atendidos</span><strong>{{ $session->payments->pluck('ticket_id')->unique()->count() }}</strong><small>Los valores esperados se calculan desde pagos registrados y no se pueden editar.</small></div>
                        <div class="cash-reconciliation-grid">
                            <label><span>Tarjetas</span><small>Esperado: ${{ number_format($expectedCard, 2) }}</small><input name="actual_card" type="number" min="0" step=".01" value="{{ number_format($expectedCard, 2, '.', '') }}" required></label>
                            <label><span>Transferencias</span><small>Esperado: ${{ number_format($expectedTransfer, 2) }}</small><input name="actual_transfer" type="number" min="0" step=".01" value="{{ number_format($expectedTransfer, 2, '.', '') }}" required></label>
                            <label><span>Efectivo de tickets</span><small>Esperado: ${{ number_format($expectedCash, 2) }}</small><input name="actual_cash" type="number" min="0" step=".01" value="{{ number_format($expectedCash, 2, '.', '') }}" required></label>
                            <label><span>Cambio inicial</span><small>Esperado: ${{ number_format((float) $session->opening_float, 2) }}</small><input name="actual_change" type="number" min="0" step=".01" value="{{ number_format((float) $session->opening_float, 2, '.', '') }}" required></label>
                            <label><span>Otros métodos</span><small>Esperado: ${{ number_format($expectedOther, 2) }}</small><input name="actual_other" type="number" min="0" step=".01" value="{{ number_format($expectedOther, 2, '.', '') }}" required></label>
                            <div class="cash-expected-total"><span>Efectivo total esperado</span><strong>${{ number_format($expectedCash + (float) $session->opening_float, 2) }}</strong></div>
                        </div>
                        <label class="cash-notes"><span>Comentario del corte</span><textarea name="cashier_notes" rows="3" placeholder="Explica cualquier diferencia, incidencia o entrega."></textarea></label>
                        <footer><button class="button button-secondary" type="button" data-dialog-close>Cancelar</button><button class="button button-primary" type="submit">Guardar corte para revisión</button></footer>
                    </form>
                </dialog>
            @endif
            @if (! $session)
                <dialog class="cash-cut-dialog" id="open-cash-{{ $register->id }}">
                    <form method="POST" action="{{ route('cash.sessions.store') }}">
                        @csrf
                        <input type="hidden" name="cash_register_id" value="{{ $register->id }}">
                        <header><div><p class="eyebrow">INICIO DE CAJA</p><h2>{{ $register->name }}</h2><p>Inicias la caja con el fondo configurado.</p></div><button class="dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button></header>
                        <label class="cash-notes"><span>Monto de inicio</span><small>Puedes editarlo si hoy comienzas con más o menos efectivo.</small><input name="opening_float" type="number" min="0" step=".01" value="{{ number_format($defaultOpeningFloat, 2, '.', '') }}" required></label>
                        <footer><button class="button button-secondary" type="button" data-dialog-close>Cancelar</button><button class="button button-primary" type="submit">Iniciar caja</button></footer>
                    </form>
                </dialog>
            @endif
        @endforeach
    </section>

    <section class="surface cash-history">
        <header><div><p class="eyebrow">HISTORIAL</p><h2>Cortes anteriores</h2><p>Incluye todos los tickets y pagos atendidos en cada caja.</p></div></header>
        <div class="cash-session-list">
            @forelse ($sessions as $session)
                <details class="cash-session-row">
                    <summary>
                        <div><strong>{{ $session->register->name }}</strong><small>{{ $session->business_date?->translatedFormat('d \d\e F \d\e Y') ?? $session->opened_at->translatedFormat('d \d\e F \d\e Y') }} · {{ $session->payments->pluck('ticket_id')->unique()->count() }} tickets</small></div>
                        <div><span>Efectivo esperado</span><strong>${{ number_format((float) ($session->expected_cash ?? 0) + (float) $session->opening_float, 2) }}</strong></div>
                        <div><span>Diferencia</span><strong class="{{ abs((float) ($session->difference ?? 0)) < .01 ? 'positive' : 'negative' }}">{{ $session->difference === null ? '—' : '$'.number_format((float) $session->difference, 2) }}</strong></div>
                        <span class="pill {{ $statusClasses[$session->status] ?? 'programada' }}">{{ $statusLabels[$session->status] ?? str($session->status)->headline() }}</span>
                        <b>⌄</b>
                    </summary>
                    <div class="cash-session-detail">
                        <div class="cash-method-summary"><span>Tarjetas <b>${{ number_format((float) ($session->expected_card ?? 0), 2) }}</b></span><span>Transferencias <b>${{ number_format((float) ($session->expected_transfer ?? 0), 2) }}</b></span><span>Efectivo <b>${{ number_format((float) ($session->expected_cash ?? 0), 2) }}</b></span><span>Cambio <b>${{ number_format((float) $session->opening_float, 2) }}</b></span></div>
                        @if ($session->cashier_notes)<p class="cash-note"><strong>Comentario de recepción:</strong> {{ $session->cashier_notes }}</p>@endif
                        @if ($session->verification_notes)<p class="cash-note"><strong>Comentario de administración:</strong> {{ $session->verification_notes }}</p>@endif
                        <div class="cash-ticket-list">
                            @forelse ($session->payments->sortBy('created_at') as $payment)
                                <a href="{{ route('tickets.show', $payment->ticket) }}"><time>{{ $payment->created_at->format('H:i') }}</time><span><strong>{{ $payment->ticket->customer?->full_name ?? 'Venta de mostrador' }}</strong><small>{{ $payment->ticket->code }} · {{ $payment->ticket->ticket_type === 'product_sale' ? 'Venta de producto' : ucfirst($payment->method) }}</small></span><b>${{ number_format((float) $payment->amount, 2) }}</b></a>
                            @empty
                                <p class="empty-state">No hubo pagos registrados en esta caja.</p>
                            @endforelse
                        </div>
                    </div>
                </details>
            @empty
                <p class="empty-state">Aún no existen cortes registrados.</p>
            @endforelse
        </div>
    </section>

    @if ($canAuthorize)
        @foreach ($pendingCuts as $session)
            <dialog class="cash-cut-dialog cash-review-dialog" id="review-cut-{{ $session->id }}">
                <form method="POST" action="{{ route('cash.cuts.confirm', $session) }}">
                    @csrf
                    <header><div><p class="eyebrow">REVISIÓN ADMINISTRATIVA</p><h2>{{ $session->register->name }} · {{ $session->business_date?->translatedFormat('d \d\e F') }}</h2><p>{{ $session->payments->pluck('ticket_id')->unique()->count() }} tickets atendidos</p></div><button class="dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button></header>
                    <div class="cash-review-comparison"><div><span>Tarjetas</span><b>${{ number_format((float) $session->expected_card, 2) }}</b><strong>${{ number_format((float) $session->actual_card, 2) }}</strong></div><div><span>Transferencias</span><b>${{ number_format((float) $session->expected_transfer, 2) }}</b><strong>${{ number_format((float) $session->actual_transfer, 2) }}</strong></div><div><span>Efectivo</span><b>${{ number_format((float) $session->expected_cash, 2) }}</b><strong>${{ number_format((float) $session->actual_cash, 2) }}</strong></div><div><span>Cambio</span><b>${{ number_format((float) $session->opening_float, 2) }}</b><strong>${{ number_format((float) $session->actual_change, 2) }}</strong></div></div>
                    <p class="cash-review-difference {{ abs((float) $session->difference) < .01 ? 'matches' : 'does-not-match' }}">{{ abs((float) $session->difference) < .01 ? '✓ El corte cuadra.' : 'Diferencia declarada: $'.number_format((float) $session->difference, 2) }}</p>
                    <label class="cash-notes"><span>Comentario de administración</span><textarea name="verification_notes" rows="3" placeholder="Anota la validación o cualquier observación."></textarea></label>
                    <footer><button class="button button-secondary" type="button" data-dialog-close>Cancelar</button><button class="button button-primary" type="submit">Confirmar corte</button></footer>
                </form>
            </dialog>
        @endforeach
    @endif

    <script>
        document.querySelectorAll('[data-dialog-open]').forEach((button) => button.addEventListener('click', () => document.getElementById(button.dataset.dialogOpen)?.showModal()));
        document.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
    </script>
</x-layouts.app>
