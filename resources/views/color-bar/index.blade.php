<x-layouts.app title="Color Bar">
    <style>.color-application-mode{margin:20px 0;padding:0;border:0}.color-application-mode[hidden],.formula-quantity-field[hidden]{display:none!important}.color-application-mode legend{margin-bottom:12px;color:#28364b;font-weight:750;font-size:14px}.color-application-mode>div{display:grid;grid-template-columns:1fr 1fr;gap:10px}.color-application-mode label{display:flex!important;align-items:center;gap:8px;padding:14px;border:1px solid #e3e7ed;border-radius:10px;cursor:pointer;font-size:13px}.color-application-mode label:has(input:checked){border-color:#b95378;background:#fff5f8}.color-application-mode input{width:17px!important;height:17px;accent-color:#b95378}</style>
    @vite(['resources/css/color-history.css', 'resources/css/color-bar-workbench.css'])
    @php
        $selectedTicket = $tickets->firstWhere('id', $selectedTicketId);
        $ticketTabLabels = ['pending' => 'Pendientes', 'with_formula' => 'Con fórmula', 'all' => 'Todos'];
        $ticketEmptyMessages = ['pending' => 'No hay tickets pendientes de fórmula.', 'with_formula' => 'No hay tickets con productos confirmados en una fórmula.', 'all' => 'No hay tickets abiertos.'];
        $visibleSavedFormulas = $selectedTicketFormulas->reject(fn ($formula): bool => $formula->id === $editingFormula?->id);
    @endphp

    <section class="page-title color-bar-title">
        <div><p class="eyebrow">COLOR BAR · OPERACIÓN</p><h1>Tickets Color Bar</h1><p>Selecciona una sola clienta, prepara sus consumos y confírmalos cuando estén listos.</p></div>
        <span class="pill confirmada">{{ $tickets->count() }} tickets disponibles</span>
    </section>

    <section class="color-bar-workbench" data-color-bar-workbench>
        <section class="surface color-ticket-selector">
            <header><div><h2>Seleccionar ticket</h2><p>{{ $ticketSearch !== '' ? 'Resultados en tickets abiertos de hoy y días anteriores.' : 'Tickets abiertos de hoy. Busca para encontrar citas anteriores.' }}</p></div>@if($selectedTicket)<a class="text-button" href="{{ route('color-bar.index', ['ticket_search' => $ticketSearch !== '' ? $ticketSearch : null]) }}">Quitar selección</a>@endif</header>
            <form method="GET" class="list-filter-bar color-ticket-filter" aria-label="Buscar tickets Color Bar"><label class="list-search"><span>⌕</span><input name="ticket_search" value="{{ $ticketSearch }}" placeholder="Buscar clienta o ticket"></label><button class="button button-secondary" type="submit">Buscar</button>@if($ticketSearch !== '')<a class="filter-clear" href="{{ route('color-bar.index') }}">Limpiar</a>@endif</form>
            <div class="color-ticket-tabs" role="tablist" aria-label="Estado de los tickets">
                @foreach($ticketTabLabels as $key => $label)
                    <button type="button" role="tab" id="color-ticket-tab-{{ $key }}" aria-selected="{{ $ticketTab === $key ? 'true' : 'false' }}" aria-controls="color-ticket-list" tabindex="{{ $ticketTab === $key ? '0' : '-1' }}" data-ticket-tab="{{ $key }}">{{ $label }} <span>{{ $ticketCounts[$key] }}</span></button>
                @endforeach
            </div>
            <div class="color-ticket-strip" id="color-ticket-list" role="tabpanel" aria-labelledby="color-ticket-tab-{{ $ticketTab }}" aria-label="Tickets activos de Color Bar">
                @forelse($tickets as $ticket)
                    @php($ticketGroup = $ticket->confirmed_color_formulas_count > 0 ? 'with_formula' : 'pending')
                    <a class="color-ticket-card {{ $selectedTicketId === $ticket->id ? 'selected' : '' }}" data-ticket-group="{{ $ticketGroup }}" @if($ticketTab !== 'all' && $ticketTab !== $ticketGroup) hidden @endif href="{{ route('color-bar.index', ['ticket' => $ticket->id, 'ticket_search' => $ticketSearch !== '' ? $ticketSearch : null, 'ticket_tab' => $ticketTab]) }}"><span class="color-ticket-card-top"><strong>{{ $ticket->customer->full_name }}</strong><small>{{ $ticket->code }}</small></span><span>{{ $ticket->appointment?->services->pluck('name_snapshot')->join(' + ') ?: 'Visita sin cita' }}</span>@if($ticket->appointment)<span>Cita: <time datetime="{{ $ticket->appointment->starts_at->toDateTimeString() }}">{{ $ticket->appointment->starts_at->format('d/m/Y · H:i') }}</time></span>@endif<footer><i class="pill {{ $ticket->status === 'in_service' ? 'en-servicio' : 'confirmada' }}">{{ $ticket->status === 'in_service' ? 'EN SERVICIO' : 'ABIERTO' }}</i>@if($ticket->confirmed_color_formulas_count)<em>{{ $ticket->confirmed_color_formulas_count }} fórmula{{ $ticket->confirmed_color_formulas_count === 1 ? '' : 's' }} guardada{{ $ticket->confirmed_color_formulas_count === 1 ? '' : 's' }}</em>@endif @if($selectedTicketId === $ticket->id)<b>✓</b>@endif</footer></a>
                @empty
                    <p class="empty-state">{{ $ticketSearch !== '' ? 'No hay tickets abiertos que coincidan con la búsqueda.' : 'No hay tickets abiertos para Color Bar hoy.' }}</p>
                @endforelse
                <p class="empty-state" data-ticket-group-empty aria-live="polite" @if($ticketCounts[$ticketTab] > 0 || $tickets->isEmpty()) hidden @endif>{{ $ticketEmptyMessages[$ticketTab] }}</p>
            </div>
        </section>

        <section class="color-bar-main">
            <section class="surface color-product-browser">
                <header><div><h2>Productos Color Bar</h2><p>{{ $canViewPrices ? 'Cobra una aplicación a precio fijo o gramos × multiplicador, según el producto.' : 'Selecciona los insumos y registra las cantidades utilizadas en la fórmula.' }}</p></div><span>{{ $variants->count() }} disponibles</span></header>
                <label class="product-search"><span>⌕</span><input type="search" placeholder="Buscar por producto o marca" data-color-product-search></label>
                <div class="color-category-tabs" data-color-category-tabs><button class="selected" type="button" data-category-filter="all">Todos</button>@foreach($categories as $category)<button type="button" data-category-filter="{{ $category->id }}"><i class="category-dot" style="background: {{ $category->color }}"></i>{{ $category->name }}</button>@endforeach</div>
                <div class="color-product-grid" data-color-product-grid tabindex="0" aria-label="Catálogo de productos Color Bar">
                    @forelse($variants as $variant)
                        @php($usesMultiplier = in_array($variant->color_bar_pricing_type, ['multiplier', 'both'], true))
                        <button class="color-product-card" type="button" data-color-product data-category="{{ $variant->product->product_category_id }}" data-id="{{ $variant->id }}" data-name="{{ $variant->product->name }}" data-brand="{{ $variant->product->brand }}" data-supplier="{{ $variant->product->preferredSupplier?->name ?: 'Sin proveedor' }}" data-pricing-type="{{ $variant->color_bar_pricing_type }}" @if($canViewPrices) data-rate="{{ $usesMultiplier ? $variant->product->category->color_bar_multiplier : 0 }}" data-fixed-price="{{ $variant->color_bar_sale_price }}" @endif @disabled(! $selectedTicket)><span class="product-mark">{{ str($variant->product->name)->substr(0, 1) }}</span><span class="color-product-copy"><strong>{{ $variant->product->name }}</strong><x-product-reference :product="$variant->product" /><small>{{ $variant->name }}</small>@if($canViewPrices)<em>@if($variant->color_bar_pricing_type === 'both')Ambos · ${{ number_format((float) $variant->color_bar_sale_price, 2) }} por aplicación · @endif{{ $usesMultiplier ? '× $'.number_format((float) $variant->product->category->color_bar_multiplier, 2).' por gr' : '$'.number_format((float) $variant->color_bar_sale_price, 2).' por aplicación' }}</em>@else<em>{{ $variant->color_bar_pricing_type === 'both' ? 'Aplicación o medida (gr)' : ($usesMultiplier ? 'Por gramos' : 'Por aplicación') }}</em>@endif</span><b>+</b></button>
                    @empty
                        <p class="empty-state">No hay productos configurados para cobro en Color Bar. Asigna un precio fijo o una categoría con multiplicador desde Productos.</p>
                    @endforelse
                </div>
                @unless($selectedTicket)<p class="color-selection-hint">Selecciona un ticket arriba para comenzar a agregar productos.</p>@endunless
            </section>

            <aside class="surface color-cart">
                <header><div><p class="eyebrow">{{ $editingFormula ? 'CORREGIR FÓRMULA' : 'FÓRMULA ACTUAL' }}</p><h2>{{ $selectedTicket?->customer->full_name ?? 'Selecciona un ticket' }}</h2><span>{{ $selectedTicket ? $selectedTicket->code : 'Los productos se agregarán aquí.' }}</span></div>@if($selectedTicket)<button class="button button-secondary color-history-trigger" type="button" data-color-history-open data-history-url="{{ route('color-bar.history', $selectedTicket) }}" aria-haspopup="dialog" aria-controls="color-customer-history"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11a9 9 0 1 1 2.6 7M3 4v7h7M12 7v5l3 2"/></svg>Historial</button>@endif</header>
                <form method="POST" action="{{ $editingFormula ? route('color-bar.update', $editingFormula) : route('color-bar.store') }}" data-formula-cart-form>
                    @csrf
                    <input type="hidden" name="ticket_item_id" value="{{ $selectedServiceId }}" data-selected-service>
                    <input type="hidden" name="ticket_id" value="{{ $selectedTicketId }}" data-selected-ticket>
                    @if($ticketSearch !== '')<input type="hidden" name="ticket_search" value="{{ $ticketSearch }}">@endif
                    <input type="hidden" name="ticket_tab" value="{{ $ticketTab }}" data-selected-ticket-tab>
                    <div class="color-cart-scroll" data-color-cart-scroll tabindex="0" aria-label="Productos y fórmulas del ticket">

                @if($selectedTicket && $serviceOptions->isNotEmpty())
                    <section class="color-service-selector" aria-label="Servicio de la fórmula">
                        <span>Servicio para esta fórmula</span>
                        <div>@foreach($serviceOptions as $option)<button type="button" data-color-service="{{ $option['id'] }}" data-included="{{ $option['included'] ? '1' : '0' }}" aria-pressed="{{ $selectedServiceId === $option['id'] ? 'true' : 'false' }}" class="{{ $selectedServiceId === $option['id'] ? 'selected' : '' }}"><strong>{{ $option['name'] }}</strong><small>{{ $canViewPrices ? ($option['included'] ? 'Color Bar incluido' : 'Cobro normal') : 'Productos del servicio' }}</small></button>@endforeach</div>
                        <p data-color-service-status>{{ ! $canViewPrices && $selectedServiceId ? 'Registra los productos utilizados en este servicio.' : ($selectedServiceId ? ($serviceOptions->firstWhere('id', $selectedServiceId)['included'] ? 'Color Bar y MC incluidos · $0.00 para la clienta.' : 'Se cobrarán los productos y el MC de este servicio.') : 'Elige un servicio para comenzar.') }}</p>
                    </section>
                @elseif($editingFormula && ! $editingFormula->ticket_item_id)
                    <p class="color-legacy-service">Fórmula anterior registrada al ticket.</p>
                @endif
                @error('ticket_item_id')<p class="form-error color-service-error">{{ $message }}</p>@enderror
                @if($visibleSavedFormulas->isNotEmpty())
                    <section class="color-ticket-formulas">
                        <header><span>Fórmulas guardadas en este ticket</span><small>{{ $visibleSavedFormulas->count() }}</small></header>
                        @foreach($visibleSavedFormulas as $formula)
                            <article class="color-ticket-formula">
                                <div class="color-saved-formula-meta"><time datetime="{{ $formula->created_at->toIso8601String() }}">{{ $formula->created_at->format('d/m · H:i') }}</time><a href="{{ route('color-bar.index', ['ticket' => $formula->ticket_id, 'formula' => $formula->id, 'ticket_search' => $ticketSearch !== '' ? $ticketSearch : null]) }}">Editar fórmula</a></div>
                                @if($formula->items->isEmpty())<p>Sin productos · corrección confirmada.</p>@endif
                                @if($formula->service_name_snapshot)<p class="color-saved-service-name">{{ $formula->service_name_snapshot }}{{ $formula->color_bar_included ? ' · Color Bar incluido' : '' }}</p>@endif
                                <ul class="color-saved-formula-items">@foreach($formula->items as $item)<li class="color-cart-item"><span class="product-mark">{{ str($item->variant->product->name)->substr(0, 1) }}</span><span><strong>{{ $item->variant->product->name }}</strong><x-product-reference :product="$item->variant->product" /><small>{{ number_format((float) $item->quantity, $item->unit === 'g' ? 3 : 0) }} {{ $item->unit }}@if($canViewPrices) · ${{ number_format((float) $item->quantity * (float) ($item->charged_unit_price ?? $item->sale_price), 2) }}@endif</small>@if($item->notes)<small>{{ $item->notes }}</small>@endif</span></li>@endforeach</ul>
                                @if($formula->notes)<p class="color-saved-formula-note"><strong>Nota de la fórmula:</strong> {{ $formula->notes }}</p>@endif
                            </article>
                        @endforeach
                    </section>
                @endif
                @if($errors->any())
                    <div class="color-formula-errors" role="alert"><strong>Revisa los datos de la fórmula.</strong><p>Los productos capturados se conservaron para que puedas corregirlos.</p><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
                @endif
                    @if($editingFormula)<p class="color-editing-formula-meta">Guardada el <time datetime="{{ $editingFormula->created_at->toIso8601String() }}">{{ $editingFormula->created_at->format('d/m/Y · H:i') }}</time></p>@endif
                    <div class="color-cart-items" data-color-cart-items></div>
                    <p class="color-cart-empty" data-color-cart-empty>{{ $editingFormula ? ($canViewPrices ? 'Sin productos. Confirma la corrección para retirar sus cargos y MC del ticket.' : 'Sin productos. Confirma la corrección para guardar la fórmula vacía.') : ($selectedTicketFormulas->isNotEmpty() ? 'Las fórmulas confirmadas están guardadas arriba. Agrega productos para preparar una nueva.' : 'Aún no agregas productos a la fórmula.') }}</p>
                    <label class="color-cart-note"><span>Nota general (opcional)</span><textarea name="notes" rows="2" maxlength="600" placeholder="Ej. Aplicación en raíz y medios">{{ old('notes', $editingFormula?->notes) }}</textarea></label>
                    </div>
                    <footer class="color-cart-actions">
                    <button class="button button-primary button-full" type="button" data-confirm-formula @disabled(! $selectedTicket)>{{ $editingFormula ? 'Confirmar corrección' : 'Confirmar fórmula' }}</button>
                    <p class="color-cart-footnote">Las fórmulas no modifican existencias. Registra los envases agotados desde Inventario Color Bar.</p>
                    </footer>
                </form>

            </aside>
        </section>
    </section>

    @if($selectedTicket)
        <dialog class="color-history-dialog" id="color-customer-history" aria-labelledby="color-history-title" data-color-history-dialog>
            <header><div><p class="eyebrow">COLOR BAR · VISITAS ANTERIORES</p><h2 id="color-history-title">Historial de {{ $selectedTicket->customer->full_name }}</h2><p>Consulta las fórmulas y productos utilizados en cada visita.</p></div><button class="dialog-close" type="button" data-color-history-close aria-label="Cerrar historial">×</button></header>
            <div class="color-history-body" data-color-history-body aria-live="polite"></div>
            <footer><button class="button button-secondary" type="button" data-color-history-close>Volver a la fórmula</button></footer>
        </dialog>
    @endif

    <dialog class="color-formula-dialog" data-formula-item-dialog>
        <form method="dialog" data-formula-item-form><header><span class="product-mark" data-dialog-mark>F</span><div><p class="eyebrow" data-formula-item-title>AGREGAR A FÓRMULA</p><h2 data-dialog-title>Producto</h2><span data-dialog-meta></span></div><button class="dialog-close" value="cancel" formnovalidate aria-label="Cerrar">×</button></header><fieldset class="color-application-mode" data-application-mode hidden><legend>¿Cómo aplicarás este producto?</legend><div><label><input type="radio" name="application_mode" value="fixed">Por aplicación</label><label><input type="radio" name="application_mode" value="multiplier">Por medida (gr)</label></div></fieldset><div class="formula-quantity-field" data-formula-quantity-field><label><span>Cantidad a aplicar (gr)</span><input type="number" min="0.001" step="0.001" inputmode="decimal" data-formula-quantity></label></div>@if($canViewPrices)<p class="color-rate-preview" data-formula-rate-preview></p>@endif<label class="formula-item-note"><span>Nota (opcional)</span><textarea rows="3" maxlength="300" placeholder="Ej. mezcla para raíz" data-formula-item-notes></textarea></label><footer><button class="button button-secondary" value="cancel" formnovalidate>Cancelar</button><button class="button button-primary" type="submit" value="confirm" data-formula-item-submit>Agregar al carrito</button></footer></form>
    </dialog>

    <dialog class="formula-confirm-dialog" data-formula-confirm-dialog><form method="dialog"><div class="confirm-symbol">✓</div><p class="eyebrow">CONFIRMAR FÓRMULA</p><h2 data-formula-confirm-title>¿Confirmar los consumos?</h2><p data-formula-confirm-description>{{ $canViewPrices ? 'Se registrarán los productos y cargos en el ticket. Las existencias no cambian.' : 'Se guardarán los productos, cantidades y notas de la fórmula. Las existencias no cambian.' }}</p><footer><button class="button button-secondary" value="cancel">Revisar fórmula</button><button class="button button-primary" type="submit" value="confirm">Sí, confirmar</button></footer></form></dialog>

    <dialog class="color-leave-dialog" aria-labelledby="color-leave-title" aria-describedby="color-leave-description" data-color-leave-dialog>
        <header><p class="eyebrow">COLOR BAR · CAMBIOS SIN GUARDAR</p><h2 id="color-leave-title">¿Guardar antes de salir?</h2></header>
        <p id="color-leave-description">Tienes cambios sin confirmar en la fórmula de <strong>{{ $selectedTicket?->customer->full_name }}</strong>. Al guardar se confirmará la fórmula en su ticket.</p>
        <p class="color-leave-error" data-color-leave-error role="alert" hidden></p>
        <footer><button class="button button-primary" type="button" data-color-leave-save>Guardar y continuar</button><button class="button button-secondary" type="button" data-color-leave-discard>Salir sin guardar</button><button class="text-button" type="button" data-color-leave-stay autofocus>Seguir editando</button></footer>
    </dialog>

    <script>
        (() => {
            if (!document.querySelector('[data-color-bar-workbench]')) return;
            const selectedTicket = @js($selectedTicketId), editingFormula = @js($editingFormula !== null), cart = @js($initialFormulaItems), itemDialog = document.querySelector('[data-formula-item-dialog]'), itemForm = document.querySelector('[data-formula-item-form]'), confirmDialog = document.querySelector('[data-formula-confirm-dialog]'), cartForm = document.querySelector('[data-formula-cart-form]'), cartItems = document.querySelector('[data-color-cart-items]'), cartEmpty = document.querySelector('[data-color-cart-empty]'), confirmButton = document.querySelector('[data-confirm-formula]'), quantityField = document.querySelector('[data-formula-quantity-field]'), quantityInput = document.querySelector('[data-formula-quantity]'), noteInput = document.querySelector('[data-formula-item-notes]'), ratePreview = document.querySelector('[data-formula-rate-preview]');
            const canViewPrices = @js($canViewPrices);
            const ticketTabs = [...document.querySelectorAll('[data-ticket-tab]')];
            const ticketPanel = document.querySelector('#color-ticket-list');
            const ticketCards = [...ticketPanel.querySelectorAll('[data-ticket-group]')];
            const ticketTabInput = document.querySelector('[data-selected-ticket-tab]');
            const ticketEmptyMessages = @js($ticketEmptyMessages);
            const setTicketTab = (tab) => {
                const group = tab.dataset.ticketTab;
                ticketTabs.forEach((button) => {
                    button.setAttribute('aria-selected', String(button === tab));
                    button.tabIndex = button === tab ? 0 : -1;
                });
                ticketPanel.setAttribute('aria-labelledby', tab.id);
                let visibleCount = 0;
                ticketCards.forEach((card) => {
                    card.hidden = group !== 'all' && card.dataset.ticketGroup !== group;
                    if (!card.hidden) visibleCount++;
                    const url = new URL(card.href);
                    url.searchParams.set('ticket_tab', group);
                    card.href = url.href;
                });
                const emptyMessage = ticketPanel.querySelector('[data-ticket-group-empty]');
                emptyMessage.hidden = visibleCount > 0 || ticketCards.length === 0;
                emptyMessage.textContent = ticketEmptyMessages[group];
                ticketPanel.scrollLeft = 0;
                ticketTabInput.value = group;
                const url = new URL(window.location.href);
                url.searchParams.set('ticket_tab', group);
                window.history.replaceState(null, '', url);
            };
            ticketTabs.forEach((tab, index) => {
                tab.addEventListener('click', () => setTicketTab(tab));
                tab.addEventListener('keydown', (event) => {
                    let nextIndex;
                    if (event.key === 'ArrowRight') nextIndex = (index + 1) % ticketTabs.length;
                    else if (event.key === 'ArrowLeft') nextIndex = (index + ticketTabs.length - 1) % ticketTabs.length;
                    else if (event.key === 'Home') nextIndex = 0;
                    else if (event.key === 'End') nextIndex = ticketTabs.length - 1;
                    else return;
                    event.preventDefault();
                    ticketTabs[nextIndex].focus();
                    setTicketTab(ticketTabs[nextIndex]);
                });
            });
            const productBrowser = document.querySelector('.color-product-browser');
            const productGrid = document.querySelector('[data-color-product-grid]');
            const resizePanels = () => {
                const gridTop = productGrid.getBoundingClientRect().top;
                const rows = [];
                for (const product of productGrid.querySelectorAll('[data-color-product]:not([hidden])')) {
                    const rect = product.getBoundingClientRect();
                    const top = rect.top - gridTop + productGrid.scrollTop;
                    let row = rows.find((candidate) => Math.abs(candidate.top - top) < 1);
                    if (!row) {
                        if (rows.length === 5) break;
                        row = { top, bottom: top };
                        rows.push(row);
                    }
                    row.bottom = Math.max(row.bottom, top + rect.height);
                }
                if (rows.length) {
                    const style = getComputedStyle(productGrid);
                    const gap = parseFloat(style.rowGap) || 0;
                    const missingRows = 5 - rows.length;
                    const tallestRow = Math.max(...rows.map((row) => row.bottom - row.top));
                    const height = rows.at(-1).bottom + missingRows * (tallestRow + gap) + (parseFloat(style.paddingBottom) || 0);
                    productGrid.style.setProperty('--color-products-height', `${Math.ceil(height)}px`);
                }
                productBrowser.classList.toggle('fits-viewport', productBrowser.offsetHeight + 112 <= window.innerHeight);
            };
            let catalogWidth = 0;
            new ResizeObserver(([entry]) => {
                if (entry.contentRect.width === catalogWidth) return;
                catalogWidth = entry.contentRect.width;
                resizePanels();
            }).observe(productGrid);
            window.addEventListener('resize', resizePanels);
            document.fonts.ready.then(resizePanels);
            resizePanels();
            const serviceInput = document.querySelector('[data-selected-service]');
            const serviceOptions = @js($serviceOptions);
            const allowsLegacyFormula = @js($editingFormula !== null && $serviceOptions->isEmpty() && $editingFormula->ticket_item_id === null);
            let serviceIncluded = @js((bool) ($editingFormula?->color_bar_included ?? false));
            let activeProduct, editingIndex = null;
            const hasSelectedService = () => Boolean(serviceInput?.value) || allowsLegacyFormula;
            const currentService = () => serviceOptions.find((service) => service.id === Number(serviceInput?.value));
            const updateSelectedService = () => {
                const service = currentService();
                if (service) serviceIncluded = service.included;
                document.querySelectorAll('[data-color-service]').forEach((button) => {
                    const selected = button.dataset.colorService === serviceInput.value;
                    button.classList.toggle('selected', selected);
                    button.setAttribute('aria-pressed', String(selected));
                });
                const status = document.querySelector('[data-color-service-status]');
                if (status) status.textContent = service && !canViewPrices ? 'Registra los productos utilizados en este servicio.' : !service ? 'Elige un servicio para comenzar.' : (service.included ? 'Color Bar y MC incluidos · $0.00 para la clienta.' : 'Se cobrarán los productos y el MC de este servicio.');
                document.querySelectorAll('[data-color-product]').forEach((button) => { button.disabled = !selectedTicket || !hasSelectedService(); });
                renderCart();
            };
            document.querySelectorAll('[data-color-service]').forEach((button) => button.addEventListener('click', () => {
                serviceInput.value = button.dataset.colorService;
                updateSelectedService();
            }));
            const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character]));
            const historyButton = document.querySelector('[data-color-history-open]');
            if (historyButton) {
                const historyDialog = document.querySelector('[data-color-history-dialog]');
                const historyBody = historyDialog.querySelector('[data-color-history-body]');
                let historyLoading = false;
                async function loadHistory(url) {
                    if (historyLoading) return;
                    historyLoading = true;
                    historyBody.setAttribute('aria-busy', 'true');
                    historyBody.innerHTML = '<p class="empty-state" role="status">Cargando historial…</p>';
                    try {
                        const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        if (!response.ok || response.redirected) throw new Error('history-unavailable');
                        historyBody.innerHTML = await response.text();
                        historyBody.scrollTop = 0;
                    } catch {
                        historyBody.innerHTML = '<div class="empty-state" role="alert"><p>No se pudo cargar el historial. Intenta de nuevo.</p><button class="button button-secondary" type="button" data-color-history-retry>Reintentar</button></div>';
                        historyBody.querySelector('[data-color-history-retry]').addEventListener('click', () => loadHistory(url));
                    } finally {
                        historyLoading = false;
                        historyBody.setAttribute('aria-busy', 'false');
                    }
                }
                historyButton.addEventListener('click', () => {
                    historyDialog.showModal();
                    loadHistory(historyButton.dataset.historyUrl);
                });
                historyDialog.querySelectorAll('[data-color-history-close]').forEach((button) => button.addEventListener('click', () => historyDialog.close()));
                historyBody.addEventListener('click', (event) => {
                    const link = event.target.closest('[data-color-history-page]');
                    if (!link) return;
                    event.preventDefault();
                    loadHistory(link.href);
                });
            }
            function isMultiplier(item) { return item.pricingType === 'multiplier'; }
            function renderCart() { cartItems.innerHTML = cart.map((item, index) => { const appliesByGrams = isMultiplier(item); const amount = !canViewPrices || serviceIncluded ? 0 : appliesByGrams ? Number(item.unitPrice) * Number(item.quantity) : Number(item.unitPrice); const detail = appliesByGrams ? `${Number(item.quantity).toLocaleString('es-MX')} gr` : `${Number(item.quantity).toLocaleString('es-MX')} ${Number(item.quantity) === 1 ? 'unidad' : 'unidades'}`; return `<article class="color-cart-item"><span class="product-mark">${escapeHtml(item.name).slice(0, 1)}</span><span><strong>${escapeHtml(item.name)}</strong><span class="product-reference"><span><span class="product-reference-label">Marca</span><span>${escapeHtml(item.brand || 'Sin marca')}</span></span><span><span class="product-reference-label">Proveedor</span><span>${escapeHtml(item.supplier || 'Sin proveedor')}</span></span></span><small>${detail}${canViewPrices ? ` · $${amount.toFixed(2)}` : ''}${item.notes ? ` · ${escapeHtml(item.notes)}` : ''}</small></span><div><button type="button" aria-label="Editar ${escapeHtml(item.name)}" data-cart-edit="${index}" title="Editar producto"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 4 4 4M3 21l5-1L21 7a2.8 2.8 0 0 0-4-4L4 16l-1 5Z"/></svg></button><button type="button" aria-label="Quitar ${escapeHtml(item.name)}" data-cart-remove="${index}">×</button></div><input type="hidden" name="items[${index}][product_variant_id]" value="${item.variantId}"><input type="hidden" name="items[${index}][quantity]" value="${item.quantity}"><input type="hidden" name="items[${index}][unit]" value="${appliesByGrams ? 'g' : 'unidad'}"><input type="hidden" name="items[${index}][notes]" value="${escapeHtml(item.notes)}"></article>`; }).join(''); cartEmpty.hidden = cart.length > 0; confirmButton.disabled = !selectedTicket || !hasSelectedService() || (!editingFormula && cart.length === 0); }
            function renderRatePreview() { if (!canViewPrices) return; if (!activeProduct) return; if (serviceIncluded) { ratePreview.textContent = 'Color Bar incluido en el servicio · Cargo a la clienta: $0.00'; return; } if (!isMultiplier(activeProduct)) { ratePreview.textContent = `Precio fijo por aplicación: $${Number(activeProduct.unitPrice).toFixed(2)}`; return; } const quantity = Number(quantityInput.value) || 0; ratePreview.textContent = `Multiplicador × $${Number(activeProduct.unitPrice).toFixed(2)} por gr · Cargo estimado: $${(quantity * Number(activeProduct.unitPrice)).toFixed(2)}`; }
            const applicationMode = itemForm.querySelector('[data-application-mode]');
            const applicationOptions = [...applicationMode.querySelectorAll('input')];
            function syncApplicationMode() {
                const offersBoth = activeProduct.availablePricingType === 'both';
                const selectedMode = offersBoth ? applicationOptions.find(input => input.checked)?.value : activeProduct.pricingType;
                activeProduct.pricingType = selectedMode || null;
                if (offersBoth && selectedMode) {
                    activeProduct.unitPrice = selectedMode === activeProduct.originalPricingType ? activeProduct.originalUnitPrice : Number(selectedMode === 'multiplier' ? activeProduct.rate : activeProduct.fixedPrice);
                }
                const appliesByGrams = selectedMode === 'multiplier';
                quantityField.hidden = !appliesByGrams;
                quantityInput.required = appliesByGrams;
                applicationMode.hidden = !offersBoth;
                applicationOptions.forEach(input => { input.required = offersBoth; });
                itemForm.querySelector('[data-formula-item-submit]').disabled = !selectedMode;
                if (ratePreview) ratePreview.hidden = !selectedMode;
                if (selectedMode) renderRatePreview();
            }
            applicationOptions.forEach(input => input.addEventListener('change', syncApplicationMode));
            function openItemDialog(product, index = null) {
                const currentItem = index === null ? null : cart[index];
                activeProduct = {...product, availablePricingType: product.availablePricingType || product.pricingType, originalPricingType: currentItem?.pricingType || (product.pricingType === 'both' ? null : product.pricingType), originalUnitPrice: product.unitPrice};
                editingIndex = index;
                applicationOptions.forEach(input => { input.checked = Boolean(currentItem) && input.value === currentItem.pricingType; });
                document.querySelector('[data-formula-item-title]').textContent = index === null ? 'AGREGAR A FÓRMULA' : 'EDITAR PRODUCTO';
                document.querySelector('[data-formula-item-submit]').textContent = index === null ? 'Agregar al carrito' : 'Guardar cambios';
                document.querySelector('[data-dialog-mark]').textContent = product.name.slice(0, 1);
                document.querySelector('[data-dialog-title]').textContent = product.name;
                document.querySelector('[data-dialog-meta]').textContent = `${product.brand || 'Color Bar'} · Aplicación para la fórmula`;
                quantityInput.value = currentItem?.quantity ?? 35;
                noteInput.value = currentItem?.notes ?? '';
                syncApplicationMode();
                itemDialog.showModal();
            }
            document.querySelectorAll('[data-color-product]').forEach((button) => button.addEventListener('click', () => openItemDialog({ id: Number(button.dataset.id), name: button.dataset.name, brand: button.dataset.brand, supplier: button.dataset.supplier, pricingType: button.dataset.pricingType, rate: Number(button.dataset.rate || 0), fixedPrice: Number(button.dataset.fixedPrice || 0), unitPrice: Number((button.dataset.pricingType === 'multiplier' ? button.dataset.rate : button.dataset.fixedPrice) || 0) })));
            itemForm.addEventListener('submit', (event) => {
                if (event.submitter?.value !== 'confirm') return;
                event.preventDefault();
                if (!activeProduct.pricingType) return;
                const appliesByGrams = isMultiplier(activeProduct);
                const quantity = appliesByGrams ? Number(quantityInput.value) : 1;
                if (!Number.isFinite(quantity) || quantity <= 0) { quantityInput.focus(); return; }
                const item = { variantId: activeProduct.id, name: activeProduct.name, brand: activeProduct.brand, supplier: activeProduct.supplier, pricingType: activeProduct.pricingType, availablePricingType: activeProduct.availablePricingType, rate: activeProduct.rate, fixedPrice: activeProduct.fixedPrice, unitPrice: activeProduct.unitPrice, quantity, unit: appliesByGrams ? 'g' : 'unidad', notes: noteInput.value.trim() };
                if (editingIndex === null) cart.push(item); else cart[editingIndex] = item;
                itemDialog.close();
                renderCart();
            });
            quantityInput.addEventListener('input', renderRatePreview);
            quantityInput.addEventListener('keydown', (event) => { if (event.key !== 'Enter' || !activeProduct || !isMultiplier(activeProduct)) return; event.preventDefault(); itemForm.querySelector('button[value="confirm"]').click(); });
            cartItems.addEventListener('click', (event) => { const removeButton = event.target.closest('[data-cart-remove]'); if (removeButton) { cart.splice(Number(removeButton.dataset.cartRemove), 1); renderCart(); return; } const editButton = event.target.closest('[data-cart-edit]'); if (editButton) { const index = Number(editButton.dataset.cartEdit), item = cart[index]; openItemDialog({ ...item, id: item.variantId }, index); } });
            const colorProductSearch = document.querySelector('[data-color-product-search]');
            let selectedProductCategory = 'all';
            const filterColorProducts = () => {
                document.querySelectorAll('[data-color-product]').forEach((product) => {
                    const matchesSearch = window.CrepeSearch.matches(`${product.dataset.name} ${product.dataset.brand}`, colorProductSearch.value);
                    const matchesCategory = selectedProductCategory === 'all' || product.dataset.category === selectedProductCategory;
                    product.hidden = ! matchesSearch || ! matchesCategory;
                });
                resizePanels();
            };
            document.querySelectorAll('[data-category-filter]').forEach((button) => button.addEventListener('click', () => { selectedProductCategory = button.dataset.categoryFilter; document.querySelectorAll('[data-category-filter]').forEach((tab) => tab.classList.toggle('selected', tab === button)); filterColorProducts(); }));
            colorProductSearch.addEventListener('input', filterColorProducts);
            let submitting = false;
            let allowNavigation = false;
            const generalNotes = cartForm.querySelector('[name="notes"]');
            const draftSnapshot = () => JSON.stringify({
                items: cart.map((item) => ({ id: item.variantId, quantity: Number(item.quantity), unit: item.unit, notes: item.notes || '' })),
                notes: generalNotes.value,
                service: serviceInput.value,
            });
            const initialDraft = draftSnapshot();
            const restoredDraft = @js($errors->any());
            const ticketLabel = @js($selectedTicket ? $selectedTicket->customer->full_name.' · '.$selectedTicket->code : '');
            const hasUnsavedChanges = () => Boolean(selectedTicket) && (editingFormula
                ? restoredDraft || draftSnapshot() !== initialDraft
                : cart.length > 0 || generalNotes.value.trim() !== '');
            const leaveDialog = document.querySelector('[data-color-leave-dialog]');
            const leaveSave = leaveDialog.querySelector('[data-color-leave-save]');
            const leaveDiscard = leaveDialog.querySelector('[data-color-leave-discard]');
            const leaveStay = leaveDialog.querySelector('[data-color-leave-stay]');
            const leaveError = leaveDialog.querySelector('[data-color-leave-error]');
            let pendingNavigation = null;
            const proceed = () => {
                allowNavigation = true;
                const navigate = pendingNavigation;
                pendingNavigation = null;
                leaveDialog.close();
                navigate();
            };
            const guardNavigation = (event, navigate) => {
                if (allowNavigation || !hasUnsavedChanges()) return;
                event.preventDefault();
                pendingNavigation = navigate;
                leaveError.hidden = true;
                leaveSave.disabled = confirmButton.disabled;
                leaveDialog.querySelector('#color-leave-description').textContent = !editingFormula && cart.length === 0
                    ? 'Tienes una nota sin guardar. Agrega al menos un producto para poder confirmar esta fórmula, o elige salir sin guardar.'
                    : (editingFormula && cart.length === 0
                        ? `Tienes una corrección sin confirmar. Al guardar se retirarán los ${canViewPrices ? 'productos y cargos' : 'productos'} de esta fórmula del ticket ${ticketLabel}.`
                        : `Tienes cambios sin confirmar en la fórmula del ticket ${ticketLabel}. Al guardar se confirmará la fórmula en ese ticket.`);
                leaveDialog.showModal();
            };
            document.addEventListener('click', (event) => {
                const link = event.target.closest('a[href]');
                if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || !link || link.hasAttribute('download') || (link.target && link.target !== '_self') || link.matches('[data-color-history-page]')) return;
                const destination = new URL(link.href);
                if (!['http:', 'https:'].includes(destination.protocol) || (destination.hash && destination.pathname === location.pathname && destination.search === location.search)) return;
                guardNavigation(event, () => { window.location.href = destination.href; });
            });
            document.addEventListener('submit', (event) => {
                const form = event.target;
                if (event.defaultPrevented || form === cartForm || form.method === 'dialog') return;
                guardNavigation(event, () => form.requestSubmit(event.submitter));
            });
            window.addEventListener('beforeunload', (event) => {
                if (allowNavigation || !hasUnsavedChanges()) return;
                event.preventDefault();
                event.returnValue = '';
            });
            leaveStay.addEventListener('click', () => leaveDialog.close());
            leaveDialog.addEventListener('close', () => { pendingNavigation = null; });
            leaveDialog.addEventListener('cancel', (event) => { if (submitting) event.preventDefault(); });
            leaveDiscard.addEventListener('click', proceed);
            leaveSave.addEventListener('click', async () => {
                if (submitting || confirmButton.disabled || !cartForm.reportValidity()) return;
                submitting = true;
                leaveSave.disabled = leaveDiscard.disabled = leaveStay.disabled = true;
                leaveSave.textContent = 'Guardando fórmula…';
                leaveError.hidden = true;
                try {
                    const response = await fetch(cartForm.action, { method: 'POST', body: new FormData(cartForm), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                    const result = await response.json();
                    if (!response.ok || result.saved !== true) {
                        throw new Error(response.status === 422 ? Object.values(result.errors || {}).flat().join(' ') || result.message : 'No se pudo guardar la fórmula. Revisa tu conexión y sesión e intenta de nuevo.');
                    }
                    proceed();
                } catch (error) {
                    leaveError.textContent = error instanceof TypeError || error instanceof SyntaxError ? 'No se pudo guardar la fórmula. Revisa tu conexión y sesión e intenta de nuevo.' : error.message;
                    leaveError.hidden = false;
                } finally {
                    submitting = false;
                    leaveSave.disabled = leaveDiscard.disabled = leaveStay.disabled = false;
                    leaveSave.textContent = 'Guardar y continuar';
                }
            });
            cartForm.addEventListener('submit', (event) => {
                if (submitting) { event.preventDefault(); return; }
                submitting = true;
                allowNavigation = true;
                confirmButton.disabled = true;
                confirmButton.textContent = 'Guardando fórmula…';
            });
            confirmButton.addEventListener('click', () => {
                if ((!cart.length && !editingFormula) || submitting || !selectedTicket || !hasSelectedService()) return;
                const emptyCorrection = editingFormula && cart.length === 0;
                confirmDialog.querySelector('[data-formula-confirm-title]').textContent = emptyCorrection ? '¿Confirmar sin productos?' : (editingFormula ? '¿Confirmar la corrección?' : '¿Confirmar los consumos?');
                confirmDialog.querySelector('[data-formula-confirm-description]').textContent = !canViewPrices
                    ? (emptyCorrection ? 'Esta fórmula quedará sin productos. Las demás fórmulas se conservarán. Las existencias no cambian.' : 'Se guardarán los productos, cantidades y notas de esta fórmula. Las existencias no cambian.')
                    : emptyCorrection
                    ? 'Se retirarán los cargos y MC de esta fórmula del ticket. Las demás fórmulas se conservarán. Las existencias no cambian.'
                    : (editingFormula ? 'Se actualizarán los productos y cargos de esta fórmula en el ticket. Las existencias no cambian.' : 'Se registrarán los productos y cargos en el ticket. Las existencias no cambian.') + (emptyCorrection ? '' : (serviceIncluded ? ' Color Bar y MC están incluidos en este servicio: cargo a la clienta $0.00.' : ' Se cobrarán los productos y el MC del servicio seleccionado.'));
                confirmDialog.returnValue = '';
                confirmDialog.showModal();
            });
            confirmDialog.addEventListener('close', () => { if (confirmDialog.returnValue === 'confirm' && !submitting) cartForm.requestSubmit(); });
            updateSelectedService();
        })();
    </script>
</x-layouts.app>
