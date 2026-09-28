<x-layouts.app :title="$ticket->customer?->full_name ?? 'Venta de mostrador'">
    @php
        $ticketDisplayName = $ticket->customer?->full_name ?? 'Venta de mostrador';
    @endphp
    @php
        $activeItems = $ticket->items->where('status', 'active');
        $serviceItems = $activeItems->where('type', 'service');
        $productItems = $activeItems->where('type', 'product');
        $colorBarItems = $activeItems->where('type', 'color_bar');
        $promotionRewardItems = $activeItems->where('type', 'promotion_reward');
        $serviceCategories = $services->pluck('category')->filter()->unique('id')->values();
        $serviceCatalogById = $services->keyBy('id');
        $unconfirmedServiceItems = $serviceItems->filter(fn ($item): bool => ($item->metadata['price_confirmed'] ?? false) !== true);
    @endphp

    <section class="ticket-hero">
        <div>
            <p class="eyebrow">{{ $ticket->code }} · {{ $ticket->ticket_type === 'product_sale' ? 'VENTA DE PRODUCTO' : 'TICKET' }}</p>
            <h1>{{ $ticketDisplayName }}</h1>
            <p>{{ $ticket->appointment?->starts_at?->translatedFormat('l d \d\e F · H:i') ?? ($ticket->ticket_type === 'product_sale' ? 'Venta directa en Recepción' : 'Visita sin cita') }}</p>
        </div>
        <div class="ticket-hero-actions">
            <span class="pill {{ $ticket->status === 'paid' ? 'en-servicio' : 'confirmada' }}">{{ $ticket->appointment?->status === 'completed' ? 'CITA CERRADA' : ($ticket->status === 'paid' ? 'PAGADO' : str($ticket->status)->headline()) }}</span>
            @if($ticket->appointment && in_array($ticket->appointment->status, ['scheduled', 'confirmed']))
                <form method="POST" action="{{ route('appointments.destroy', $ticket->appointment) }}" onsubmit="return confirm('¿Cancelar esta cita?')">
                    @csrf
                    @method('DELETE')
                    <button class="button button-secondary">Cancelar cita</button>
                </form>
            @endif
            <a class="button button-secondary" href="{{ route('tickets.index') }}">Volver</a>
        </div>
    </section>

    <section class="ticket-layout">
        <div class="ticket-content">
            @if($ticket->ticket_type !== 'product_sale')
            <article class="surface ticket-section">
                <header>
                    <div><h2>Servicios realizados</h2><span>{{ $serviceItems->count() }} registrados en este ticket</span></div>
                    <button class="button button-secondary ticket-add-service" type="button" data-open-service-dialog @disabled($ticket->status === 'paid' || $services->isEmpty())>+ Agregar servicio</button>
                </header>
                @forelse($serviceItems as $item)
                    @php
                        $metadata = $item->metadata ?? [];
                        $service = $serviceCatalogById->get($metadata['salon_service_id'] ?? null) ?? $services->firstWhere('name', $item->name_snapshot);
                        $priceOptions = $service?->prices ?? collect();
                        $isPriceConfirmed = ($metadata['price_confirmed'] ?? false) === true;
                    @endphp
                    <div class="ticket-line ticket-service-line {{ $isPriceConfirmed ? '' : 'needs-price-confirmation' }}">
                        <div>
                            <strong>{{ $item->name_snapshot }}</strong>
                            <small>Servicio @if(($metadata['price_type'] ?? null) === 'variable') · Precio final definido en ticket @elseif($metadata['price_tier'] ?? null) · Tarifa {{ $metadata['price_tier'] }} @endif @if($isPriceConfirmed)<span class="service-confirmed">✓ Confirmado</span>@else<span class="service-pending">! Confirma el tipo</span>@endif</small>
                            @unless($isPriceConfirmed)
                                <button class="button button-primary service-confirm-trigger" type="button" data-confirm-service data-action="{{ route('tickets.services.confirm', [$ticket, $item]) }}" data-service-name="{{ $item->name_snapshot }}" data-current-tier="{{ $metadata['price_tier'] ?? '' }}" data-price-options='@json($priceOptions->map(fn ($price) => ['name' => $price->tier, 'sale' => (float) $price->sale_price])->values())'>! Confirmar tipo de precio</button>
                            @endunless
                        </div>
                        <b>${{ number_format((float) $item->line_total, 0) }}</b>
                    </div>
                @empty
                    <p class="empty-state">Aún no hay servicios registrados. Usa “Agregar servicio” para incluir uno.</p>
                @endforelse
            </article>

            @if($unconfirmedServiceItems->isNotEmpty())
                <dialog class="product-dialog service-confirm-dialog" id="service-confirm-dialog">
                    <form method="POST" action="#" id="service-confirm-form">@csrf
                        <header><div><p class="eyebrow">CONFIRMAR SERVICIO</p><h2 id="confirm-service-name">Selecciona la variante</h2><p>Elige el tipo de precio que se cobrará en este ticket.</p></div><button type="button" class="dialog-close" data-close-dialog aria-label="Cerrar">×</button></header>
                        <label class="quantity-picker" id="confirm-tier-field"><span>Variante o tipo de precio</span><select name="price_tier" id="confirm-service-tier"></select><small id="confirm-service-price"></small></label>
                        <p class="service-confirm-modal-note">Al confirmar, el total del ticket se actualizará con el precio seleccionado.</p>
                        <footer><button type="button" class="button button-secondary" data-close-dialog>Cancelar</button><button class="button button-primary" type="submit">Confirmar y actualizar total</button></footer>
                    </form>
                </dialog>
            @endif
            @endif

            @if($colorBarItems->isNotEmpty())
                <article class="surface ticket-section compact-ticket-section">
                    <header><div><h2>Consumos Color Bar</h2><span>Descontados del inventario real</span></div><span>{{ $colorBarItems->count() }} consumos</span></header>
                    @foreach($colorBarItems as $item)
                        <div class="ticket-line"><div><strong>{{ $item->name_snapshot }}</strong><small>{{ number_format((float) $item->quantity, 0) }} {{ $item->unit }}</small></div><b>${{ number_format((float) $item->line_total, 0) }}</b></div>
                    @endforeach
                </article>
            @endif

            <article class="surface ticket-section product-picker">
                <header><div><h2>Agregar producto</h2><span>Descuenta inventario de Recepción</span></div><span>{{ $variants->count() }} productos disponibles</span></header>
                @if($productItems->isNotEmpty())
                    <div class="ticket-subsection"><strong>Productos registrados</strong>@foreach($productItems as $item)<div class="ticket-line"><div><strong>{{ $item->name_snapshot }}</strong><small>{{ number_format((float) $item->quantity, 0) }} {{ $item->unit }}</small></div><b>${{ number_format((float) $item->line_total, 0) }}</b></div>@endforeach</div>
                @endif
                <div class="product-search"><span>⌕</span><input id="product-search" type="search" placeholder="Buscar por nombre, marca o presentación" autocomplete="off"></div>
                <div class="product-card-grid" id="product-card-grid">
                    @foreach($variants as $variant)
                        @php($stock = (float) $variant->balances->sum('available_quantity'))
                        <button type="button" class="pos-product-card" data-product-card data-search="{{ str($variant->product->name.' '.$variant->product->brand.' '.$variant->name)->lower() }}" data-id="{{ $variant->id }}" data-name="{{ $variant->product->name }}" data-presentation="{{ $variant->name }}" data-price="{{ $variant->sale_price }}" data-stock="{{ $stock }}" @disabled($ticket->status === 'paid' || $stock < 1)><span class="product-mark">{{ str($variant->product->brand ?: $variant->product->name)->substr(0, 1) }}</span><span class="product-card-copy"><strong>{{ $variant->product->name }}</strong><small>{{ $variant->product->brand ?: 'CREPÉ' }} · {{ $variant->name }}</small><em>{{ $stock > 0 ? number_format($stock, 0).' disponibles' : 'Sin existencias' }}</em></span><span class="product-card-price">${{ number_format((float) $variant->sale_price, 0) }}<i>+</i></span></button>
                    @endforeach
                </div>
                <p class="empty-state" id="product-empty" hidden>No hay productos que coincidan con la búsqueda.</p>
            </article>
        </div>

        <aside class="ticket-summary surface">
            <h2>Resumen</h2>
            @error('salon_service_id')<p class="form-error">{{ $message }}</p>@enderror
            @error('price_tier')<p class="form-error">{{ $message }}</p>@enderror
            @error('unit_price')<p class="form-error">{{ $message }}</p>@enderror
            @error('appointment')<p class="form-error">{{ $message }}</p>@enderror
            <div><span>Subtotal</span><b>${{ number_format($ticket->subtotal, 0) }}</b></div><div><span>Anticipos / pagos</span><b>−${{ number_format($ticket->paid_total, 0) }}</b></div><div class="summary-total"><span>Saldo</span><strong>${{ number_format($ticket->balance, 0) }}</strong></div>
            @if($ticket->adjustments->isNotEmpty())
                <div class="ticket-adjustments"><span>Promociones</span>@foreach($ticket->adjustments as $adjustment)<small>{{ $adjustment->reason }} <b>{{ $adjustment->amount < 0 ? '−' : '+' }}${{ number_format(abs((float) $adjustment->amount), 0) }}</b></small>@endforeach</div>
            @endif
            @if($promotionRewardItems->isNotEmpty())<div class="ticket-reward-note">🎁 Regalos incluidos: {{ $promotionRewardItems->pluck('name_snapshot')->join(', ') }}</div>@endif
            @if($ticket->status !== 'paid' && $promotions->isNotEmpty())
                <form method="POST" action="#" class="promotion-apply-form" id="promotion-apply-form">@csrf<label><span>Agregar promoción</span><select name="promotion_id" id="promotion-selector" required><option value="">Selecciona una promo</option>@foreach($promotions as $promotion)<option value="{{ $promotion->id }}" data-action="{{ route('promotions.apply', [$promotion, $ticket]) }}">{{ $promotion->name }}{{ $promotion->method === 'code' ? ' · requiere código' : '' }}</option>@endforeach</select></label><input name="promotion_code" placeholder="Código (si aplica)">@error('promotion')<p class="form-error">{{ $message }}</p>@enderror<button class="button button-secondary button-full" type="submit">Aplicar promoción</button></form>
            @endif
            @if($unconfirmedServiceItems->isNotEmpty())
                <div class="service-confirmation-alert"><strong>! Confirma los servicios</strong><span>Confirma el tipo de precio de cada servicio para habilitar el pago.</span></div>
            @endif
            @if($ticket->status === 'paid' && $ticket->balance <= 0.01)
                @if($ticket->appointment?->status === 'completed')
                    <p class="ticket-closed-note">Esta cita ya fue cerrada.</p>
                @elseif($unconfirmedServiceItems->isNotEmpty())
                    <p class="ticket-closed-note pending">Confirma los tipos de precio antes de cerrar el ticket.</p>
                @else
                    <form method="POST" action="{{ route('tickets.close', $ticket) }}" class="payment-form">@csrf<button class="button button-primary button-full">Cerrar ticket</button></form>
                @endif
            @else
                <form method="POST" action="{{ route('tickets.payments.store', $ticket) }}" class="payment-form">@csrf<label><span>Monto a cobrar</span><input name="amount" type="number" min=".01" step=".01" value="{{ max(0, $ticket->balance) }}" required></label><label><span>Método</span><select name="method"><option value="cash">Efectivo</option><option value="card">Tarjeta</option><option value="transfer">Transferencia</option><option value="other">Otro</option></select></label><label><span>Cuenta de ingreso</span><select name="finance_account_id"><option value="">Asignar automáticamente</option>@foreach($financeAccounts as $financeAccount)<option value="{{ $financeAccount->id }}">{{ $financeAccount->name }}</option>@endforeach</select></label><label><span>Caja que registra</span><select name="cash_register_id" required>@foreach($cashRegisters as $cashRegister)<option value="{{ $cashRegister->id }}" @selected($cashSession?->cash_register_id === $cashRegister->id)>{{ $cashRegister->name }}</option>@endforeach</select></label>@if($cashRegisters->isEmpty())<p class="form-error">No hay una caja abierta para registrar el cobro. <a href="{{ route('cash.index') }}">Abrir caja</a></p>@endif<button class="button button-primary button-full" @disabled($ticket->status === 'paid' || $ticket->balance <= 0 || $cashRegisters->isEmpty() || $unconfirmedServiceItems->isNotEmpty())>Registrar pago</button></form>
            @endif
            @error('amount')<p class="form-error">{{ $message }}</p>@enderror @error('cash_register_id')<p class="form-error">{{ $message }}</p>@enderror @error('ticket')<p class="form-error">{{ $message }}</p>@enderror
            <div class="ticket-timeline"><h3>Actividad</h3><p>{{ $ticket->ticket_type === 'product_sale' ? 'Venta de producto abierta en Recepción' : ($ticket->appointment ? 'Ticket creado desde cita' : 'Ticket abierto en Recepción') }}</p><p>{{ $ticket->items->count() }} conceptos registrados</p><p>{{ $ticket->payments->count() }} pagos / anticipos</p></div>
        </aside>
    </section>

    <dialog class="product-dialog service-dialog" id="service-dialog">
        <section class="service-browser">
            <header><div><p class="eyebrow">AGREGAR SERVICIO</p><h2>Selecciona el servicio realizado</h2><p>El precio se confirma antes de agregarlo al ticket.</p></div><button type="button" class="dialog-close" data-close-dialog aria-label="Cerrar">×</button></header>
            <div class="product-search"><span>⌕</span><input id="ticket-service-search" type="search" placeholder="Buscar servicio" autocomplete="off"></div>
            <div class="service-category-tabs ticket-service-tabs" id="ticket-service-tabs"><button class="selected" type="button" data-service-category="all">Todos</button>@foreach($serviceCategories as $category)<button type="button" data-service-category="{{ $category->id }}"><i class="category-dot" style="background:{{ $category->color }}"></i>{{ $category->name }}</button>@endforeach</div>
            <div class="ticket-service-grid" id="ticket-service-grid">
                @foreach($services as $service)
                    <button type="button" class="ticket-service-card" data-service-card data-category="{{ $service->service_category_id }}" data-search="{{ str($service->name.' '.$service->category?->name)->lower() }}" data-id="{{ $service->id }}" data-name="{{ $service->name }}" data-category-name="{{ $service->category?->name }}" data-price="{{ $service->base_price }}" data-price-type="{{ $service->price_type }}" data-prices='@json($service->prices->map(fn ($price) => ['name' => $price->tier, 'sale' => (float) $price->sale_price])->values())'><span class="service-card-mark" style="background:{{ $service->category?->color ?: '#2F63F5' }}">{{ str($service->category?->name ?: $service->name)->substr(0, 1) }}</span><span><strong>{{ $service->name }}</strong><small>{{ $service->category?->name }} · {{ $service->estimated_duration_minutes }} min</small><em>{{ $service->price_type === 'variable' ? 'Precio variable' : $service->prices->map(fn ($price) => $price->tier.' $'.number_format((float) $price->sale_price, 0))->join(' · ') }}</em></span><i>+</i></button>
                @endforeach
            </div>
            <p class="empty-state" id="ticket-service-empty" hidden>No hay servicios que coincidan con la búsqueda.</p>
        </section>
        <form method="POST" action="{{ route('tickets.services.store', $ticket) }}" class="service-price-form" id="service-price-form" hidden>@csrf<input type="hidden" name="salon_service_id" id="selected-service-id"><header><button type="button" class="dialog-close" data-return-service-browser aria-label="Volver">‹</button><div><p class="eyebrow">AGREGAR SERVICIO</p><h2 id="selected-service-name"></h2><p id="selected-service-category"></p></div><button type="button" class="dialog-close" data-close-dialog aria-label="Cerrar">×</button></header><label class="quantity-picker" id="selected-service-tier-field"><span>Tipo de precio</span><select id="selected-service-tier" name="price_tier"></select></label><label class="quantity-picker"><span id="selected-service-price-label">Precio final del servicio</span><input id="selected-service-price" name="unit_price" type="number" min="0" step=".01" required></label><p class="service-price-hint" id="selected-service-price-hint"></p><footer><button type="button" class="button button-secondary" data-return-service-browser>Cancelar</button><button class="button button-primary" type="submit">Agregar al ticket</button></footer></form>
    </dialog>

    <dialog class="product-dialog" id="product-dialog">
        <form method="POST" action="{{ route('tickets.products.store', $ticket) }}">@csrf<input type="hidden" name="product_variant_id" id="selected-product-id"><header><div class="product-mark" id="selected-product-mark">P</div><div><p class="eyebrow">AGREGAR AL TICKET</p><h2 id="selected-product-name"></h2><p id="selected-product-presentation"></p></div><button type="button" class="dialog-close" data-close-dialog aria-label="Cerrar">×</button></header><div class="dialog-product-price"><span>Precio unitario</span><strong id="selected-product-price"></strong><small id="selected-product-stock"></small></div><label class="quantity-picker"><span>Cantidad</span><div><button type="button" data-quantity-change="-1">−</button><input id="selected-product-quantity" name="quantity" type="number" min="1" value="1" required><button type="button" data-quantity-change="1">+</button></div></label><footer><button type="button" class="button button-secondary" data-close-dialog>Cancelar</button><button class="button button-primary" type="submit">Agregar al ticket</button></footer></form>
    </dialog>

    <script>
        (() => {
            const productSearch = document.getElementById('product-search');
            const productCards = [...document.querySelectorAll('[data-product-card]')];
            const productEmpty = document.getElementById('product-empty');
            const productDialog = document.getElementById('product-dialog');
            const quantity = document.getElementById('selected-product-quantity');
            let stock = 0;

            productSearch?.addEventListener('input', () => {
                const term = productSearch.value.toLowerCase().trim();
                const shown = productCards.filter((card) => {
                    const visible = card.dataset.search.includes(term);
                    card.hidden = !visible;
                    return visible;
                }).length;
                productEmpty.hidden = shown !== 0;
            });

            productCards.forEach((card) => card.addEventListener('click', () => {
                stock = Number(card.dataset.stock);
                document.getElementById('selected-product-id').value = card.dataset.id;
                document.getElementById('selected-product-name').textContent = card.dataset.name;
                document.getElementById('selected-product-presentation').textContent = card.dataset.presentation;
                document.getElementById('selected-product-mark').textContent = card.dataset.name.charAt(0);
                document.getElementById('selected-product-price').textContent = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 0 }).format(card.dataset.price);
                document.getElementById('selected-product-stock').textContent = `${stock} disponibles en Recepción`;
                quantity.value = 1;
                quantity.max = stock;
                productDialog.showModal();
            }));

            document.querySelectorAll('[data-quantity-change]').forEach((button) => button.addEventListener('click', () => {
                quantity.value = Math.max(1, Math.min(stock, Number(quantity.value || 1) + Number(button.dataset.quantityChange)));
            }));

            const serviceDialog = document.getElementById('service-dialog');
            const serviceBrowser = serviceDialog.querySelector('.service-browser');
            const servicePriceForm = document.getElementById('service-price-form');
            const serviceSearch = document.getElementById('ticket-service-search');
            const serviceCards = [...document.querySelectorAll('[data-service-card]')];
            const serviceEmpty = document.getElementById('ticket-service-empty');
            const serviceTier = document.getElementById('selected-service-tier');
            const serviceTierField = document.getElementById('selected-service-tier-field');
            const servicePrice = document.getElementById('selected-service-price');
            let selectedServiceCard = null;
            let selectedCategory = 'all';

            const filterServices = () => {
                const term = serviceSearch.value.toLowerCase().trim();
                const shown = serviceCards.filter((card) => {
                    const visible = card.dataset.search.includes(term) && (selectedCategory === 'all' || card.dataset.category === selectedCategory);
                    card.hidden = !visible;
                    return visible;
                }).length;
                serviceEmpty.hidden = shown !== 0;
            };

            document.querySelector('[data-open-service-dialog]')?.addEventListener('click', () => {
                serviceBrowser.hidden = false;
                servicePriceForm.hidden = true;
                serviceDialog.showModal();
            });
            serviceSearch?.addEventListener('input', filterServices);
            document.querySelectorAll('[data-service-category]').forEach((button) => button.addEventListener('click', () => {
                selectedCategory = button.dataset.serviceCategory;
                document.querySelectorAll('[data-service-category]').forEach((tab) => tab.classList.toggle('selected', tab === button));
                filterServices();
            }));
            serviceCards.forEach((card) => card.addEventListener('click', () => {
                selectedServiceCard = card;
                const selectedServiceId = servicePriceForm.querySelector('[name="salon_service_id"]');
                selectedServiceId.value = String(card.dataset.id);
                selectedServiceId.setAttribute('value', String(card.dataset.id));
                document.getElementById('selected-service-name').textContent = card.dataset.name;
                document.getElementById('selected-service-category').textContent = card.dataset.categoryName;
                const fixed = card.dataset.priceType === 'fixed';
                serviceTierField.hidden = !fixed;
                serviceTier.required = fixed;
                servicePrice.readOnly = fixed;
                const prices = JSON.parse(card.dataset.prices || '[]');
                serviceTier.replaceChildren(...prices.map((price) => new Option(price.name, price.name)));
                serviceTier.value = prices[0]?.name || '';
                servicePrice.value = Number(fixed ? (prices[0]?.sale || 0) : card.dataset.price).toFixed(2);
                document.getElementById('selected-service-price-label').textContent = fixed ? 'Precio de venta' : 'Precio final del servicio';
                document.getElementById('selected-service-price-hint').textContent = fixed
                    ? 'Selecciona la tarifa autorizada para este servicio.'
                    : 'Servicio con precio variable: confirma el importe final antes de agregarlo.';
                serviceBrowser.hidden = true;
                servicePriceForm.hidden = false;
            }));
            serviceTier.addEventListener('change', () => {
                if (selectedServiceCard === null) return;
                const price = JSON.parse(selectedServiceCard.dataset.prices || '[]').find((entry) => entry.name === serviceTier.value);
                servicePrice.value = Number(price?.sale || 0).toFixed(2);
            });
            document.querySelectorAll('[data-return-service-browser]').forEach((button) => button.addEventListener('click', () => {
                servicePriceForm.hidden = true;
                serviceBrowser.hidden = false;
            }));
            document.querySelectorAll('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
            document.getElementById('promotion-selector')?.addEventListener('change', (event) => {
                const option = event.target.selectedOptions[0];
                document.getElementById('promotion-apply-form').action = option?.dataset.action || '#';
            });
            const serviceConfirmDialog = document.getElementById('service-confirm-dialog');
            const serviceConfirmForm = document.getElementById('service-confirm-form');
            const serviceConfirmTier = document.getElementById('confirm-service-tier');
            const serviceConfirmPrice = document.getElementById('confirm-service-price');
            let activeConfirmButton = null;
            document.querySelectorAll('[data-confirm-service]').forEach((button) => button.addEventListener('click', () => {
                activeConfirmButton = button;
                const options = JSON.parse(button.dataset.priceOptions || '[]');
                serviceConfirmForm.action = button.dataset.action;
                document.getElementById('confirm-service-name').textContent = button.dataset.serviceName;
                serviceConfirmTier.replaceChildren(...options.map((price) => new Option(`${price.name} · $${Number(price.sale).toFixed(2)}`, price.name)));
                serviceConfirmTier.value = button.dataset.currentTier || options[0]?.name || '';
                serviceConfirmPrice.textContent = options.length ? `Precio de venta: $${Number(options.find((price) => price.name === serviceConfirmTier.value)?.sale || options[0].sale).toFixed(2)}` : 'Servicio con precio variable: se confirmará el importe ya capturado.';
                document.getElementById('confirm-tier-field').hidden = options.length === 0;
                serviceConfirmDialog.showModal();
            }));
            serviceConfirmTier?.addEventListener('change', () => {
                const price = JSON.parse(activeConfirmButton?.dataset.priceOptions || '[]').find((entry) => entry.name === serviceConfirmTier.value);
                serviceConfirmPrice.textContent = price ? `Precio de venta: $${Number(price.sale).toFixed(2)}` : '';
            });
        })();
    </script>
</x-layouts.app>
