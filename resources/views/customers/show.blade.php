<x-layouts.app :title="$customer->full_name">
    @php
        $ticketStatusLabels = ['open' => 'Abierto', 'paid' => 'Pagado', 'cancelled' => 'Cancelado'];
    @endphp
    <section class="profile-hero"><div class="profile-avatar">{{ str($customer->first_name)->substr(0, 1) }}</div><div><p class="eyebrow">CLIENTA</p><h1>{{ $customer->full_name }}</h1><p>{{ $customer->phone ?: 'Sin teléfono' }} @if($customer->whatsapp) · WhatsApp {{ $customer->whatsapp }} @endif</p></div><div class="profile-actions"><span class="pill en-servicio">Activa</span><a class="button button-secondary" href="{{ route('customers.edit', $customer) }}">Editar ficha</a></div></section>
    <section class="profile-grid"><article class="surface customer-details"><header><h2>Datos de clienta</h2></header><dl><div><dt>Correo</dt><dd>{{ $customer->email ?: '—' }}</dd></div><div><dt>Fecha de nacimiento</dt><dd>{{ $customer->birthday?->translatedFormat('d \d\e F') ?: '—' }}</dd></div><div><dt>Notas</dt><dd>{{ $customer->notes ?: 'Sin observaciones registradas.' }}</dd></div></dl></article><article class="surface future-card"><div class="preview-icon">◎</div><h2>Operación de clienta</h2><p>Crea una cita para abrir su ticket operativo, o consulta sus tickets ya registrados.</p><a class="button button-primary" href="{{ route('appointments.create', ['customer_id' => $customer->id]) }}">Nueva cita</a><a class="button button-secondary" href="{{ route('tickets.index', ['search' => $customer->full_name]) }}">Ver tickets</a></article></section>
    <section class="surface customer-history">
        <header class="customer-history-head"><div><h2>Historial de citas</h2><span>{{ $customer->tickets->count() }} tickets registrados</span></div><a class="button button-secondary" href="{{ route('tickets.index', ['search' => $customer->full_name]) }}">Ver todos los tickets</a></header>
        @forelse($customer->tickets as $ticket)
            @php
                $activeItems = $ticket->items->where('status', 'active');
                $services = $activeItems->where('type', 'service');
                $products = $activeItems->where('type', 'product');
                $colorBar = $activeItems->where('type', 'color_bar');
                $ticketTotal = (float) $activeItems->sum('line_total') + (float) $ticket->adjustments->sum('amount');
                $paidTotal = (float) $ticket->payments->where('status', 'registered')->sum('amount');
                $visitDate = $ticket->appointment?->starts_at ?? $ticket->opened_at ?? $ticket->created_at;
            @endphp
            <article class="customer-history-row"><div class="customer-history-date"><strong>{{ $visitDate?->translatedFormat('d M Y') }}</strong><small>{{ $visitDate?->format('H:i') }} · {{ $ticket->code }}</small></div><div class="customer-history-summary"><strong>{{ $services->pluck('name_snapshot')->join(' · ') ?: 'Ticket sin servicios' }}</strong><small>@if($ticket->appointment?->employee){{ $ticket->appointment->employee->full_name }}@if($ticket->appointment->secondaryEmployee) · {{ $ticket->appointment->secondaryEmployee->full_name }}@endif @else Visita sin cita @endif</small></div><span class="pill {{ $ticket->status === 'paid' ? 'en-servicio' : 'confirmada' }}">{{ $ticketStatusLabels[$ticket->status] ?? str($ticket->status)->headline() }}</span><div class="customer-history-total"><small>Cobrado</small><strong>MXN {{ number_format($paidTotal, 0) }}</strong></div><button class="button button-secondary" type="button" data-customer-ticket="{{ $ticket->id }}">Ver detalle</button></article>
            <dialog class="customer-ticket-dialog" id="customer-ticket-{{ $ticket->id }}">
                <section>
                    <header><div><p class="eyebrow">DETALLE DE VISITA</p><h2>{{ $visitDate?->format('d/m/Y · H:i') }}</h2><p>{{ $ticket->code }} · {{ $ticketStatusLabels[$ticket->status] ?? str($ticket->status)->headline() }}</p></div><button class="dialog-close" type="button" data-close-customer-ticket aria-label="Cerrar">×</button></header>
                    <div class="customer-ticket-detail-body">
                        <div class="customer-ticket-detail-meta"><span>Estilista</span><strong>{{ $ticket->appointment?->employee?->full_name ?: 'Sin cita' }}{{ $ticket->appointment?->secondaryEmployee ? ' · '.$ticket->appointment->secondaryEmployee->full_name : '' }}</strong></div>
                        <h3>Servicios realizados</h3>
                        @if($services->isNotEmpty())
                            @foreach($services as $item)
                                <div class="customer-ticket-item"><span>{{ $item->name_snapshot }}</span><strong>MXN {{ number_format((float) $item->line_total, 0) }}</strong></div>
                            @endforeach
                        @else
                            <p class="customer-history-empty">No hay servicios registrados.</p>
                        @endif
                        @if($products->isNotEmpty())
                            <h3>Productos</h3>
                            @foreach($products as $item)
                                <div class="customer-ticket-item"><span>{{ $item->name_snapshot }} · {{ number_format((float) $item->quantity, 0) }} {{ $item->unit }}</span><strong>MXN {{ number_format((float) $item->line_total, 0) }}</strong></div>
                            @endforeach
                        @endif
                        @if($colorBar->isNotEmpty())
                            <h3>Color Bar utilizado</h3>
                            @foreach($colorBar as $item)
                                <div class="customer-ticket-item"><span>{{ $item->name_snapshot }} · {{ number_format((float) $item->quantity, 0) }} {{ $item->unit }}</span><strong>MXN {{ number_format((float) $item->line_total, 0) }}</strong></div>
                            @endforeach
                        @endif
                        <div class="customer-ticket-totals"><span>Total del ticket</span><strong>MXN {{ number_format($ticketTotal, 0) }}</strong><span>Total cobrado</span><strong>MXN {{ number_format($paidTotal, 0) }}</strong></div>
                    </div>
                    <footer><a class="button button-secondary" href="{{ route('tickets.show', $ticket) }}">Abrir ticket</a><button class="button button-primary" type="button" data-close-customer-ticket>Cerrar</button></footer>
                </section>
            </dialog>
        @empty
            <p class="customer-history-empty">Aún no hay citas o tickets registrados para esta clienta.</p>
        @endforelse
    </section>
    <script>
        (() => {
            document.querySelectorAll('[data-customer-ticket]').forEach((button) => button.addEventListener('click', () => document.getElementById('customer-ticket-' + button.dataset.customerTicket)?.showModal()));
            document.querySelectorAll('[data-close-customer-ticket]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
        })();
    </script>
</x-layouts.app>
