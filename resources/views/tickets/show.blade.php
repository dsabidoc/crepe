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
        $canChargeTicket = $ticket->status === 'open'
            && $ticket->balance > 0
            && $unconfirmedServiceItems->isEmpty()
            && ($isCashAdministrator ? $cashRegisters->isNotEmpty() : $cashSession !== null);
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
            @if($isCashAdministrator && $ticket->status === 'paid')
                <form method="POST" action="{{ route('tickets.reopen', $ticket) }}" onsubmit="return confirm('¿Reabrir este ticket? Los pagos se conservarán en la bitácora de caja.')">
                    @csrf
                    <button class="button button-secondary">Reabrir ticket</button>
                </form>
            @endif
            @if($isCashAdministrator && $ticket->status === 'open' && $ticket->payments->where('status', 'registered')->isEmpty())
                <form method="POST" action="{{ route('tickets.cancel', $ticket) }}" onsubmit="return confirm('¿Cancelar este ticket sin pagos?')">
                    @csrf
                    <button class="button button-secondary">Cancelar ticket</button>
                </form>
            @endif
            <a class="button button-secondary" href="{{ route('tickets.index') }}">Volver</a>
        </div>
    </section>

    @if($ticket->appointment)
        <section class="surface ticket-responsibles">
            <header><div><h2>Estilistas responsables</h2><p>Responsables de atender este ticket.</p></div>@if(! $ticket->appointment->secondaryEmployee && $ticket->status !== 'paid')<button class="button button-secondary" type="button" data-open-stylist-dialog>+ Agregar otra estilista</button>@endif</header>
            <div class="responsible-stylist-list">
                <div class="responsible-stylist"><span class="customer-avatar">{{ str($ticket->appointment->employee?->first_name ?? 'E')->substr(0, 1) }}</span><span><strong>{{ $ticket->appointment->employee?->full_name ?? 'Sin asignar' }}</strong><small>Estilista responsable</small></span></div>
                @if($ticket->appointment->secondaryEmployee)
                    <div class="responsible-stylist"><span class="customer-avatar">{{ str($ticket->appointment->secondaryEmployee->first_name)->substr(0, 1) }}</span><span><strong>{{ $ticket->appointment->secondaryEmployee->full_name }}</strong><small>Estilista responsable</small></span></div>
                @endif
            </div>
            @error('secondary_employee_id')<p class="form-error">{{ $message }}</p>@enderror
        </section>
    @endif

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
                            <small>Servicio @if(($metadata['price_type'] ?? null) === 'variable') · Mínimo ${{ number_format((float) ($metadata['catalog_price'] ?? $item->unit_price), 2) }} @elseif($metadata['price_tier'] ?? null) · Tarifa {{ $metadata['price_tier'] }} @endif @if($isPriceConfirmed)<span class="service-confirmed">✓ Confirmado</span>@else<span class="service-pending">! Confirma el tipo</span>@endif</small>
                            @unless($isPriceConfirmed)
                                <button class="button button-primary service-confirm-trigger" type="button" data-confirm-service data-action="{{ route('tickets.services.confirm', [$ticket, $item]) }}" data-service-name="{{ $item->name_snapshot }}" data-current-tier="{{ $metadata['price_tier'] ?? '' }}" data-current-price="{{ $item->unit_price }}" data-minimum-price="{{ $metadata['catalog_price'] ?? $item->unit_price }}" data-price-type="{{ $metadata['price_type'] ?? 'fixed' }}" data-price-options='@json($priceOptions->map(fn ($price) => ['name' => $price->tier, 'sale' => (float) $price->sale_price])->values())'>! Confirmar tipo de precio</button>
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
                        <div class="price-tier-picker" id="confirm-tier-field"><span>Variante o tipo de precio</span><input type="hidden" name="price_tier" id="confirm-service-tier"><div class="price-tier-grid" id="confirm-service-tier-grid"></div></div><label class="quantity-picker" id="confirm-variable-price-field" hidden><span>Precio final</span><input name="unit_price" id="confirm-variable-price" type="number" min="0" step=".01"><small id="confirm-variable-price-help"></small></label>
                        <p class="service-confirm-modal-note" id="confirm-service-price">Al confirmar, el total del ticket se actualizará con el precio seleccionado.</p>
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
            @if($ticket->payments->where('status', 'registered')->isNotEmpty())
                @php($paymentMethodLabels = ['cash' => 'Efectivo', 'card' => 'Tarjeta', 'transfer' => 'Transferencia', 'gift_card' => 'Gift Card', 'other' => 'Otro'])
                <div class="ticket-payment-list"><span>Pagos aplicados</span>@foreach($ticket->payments->where('status', 'registered')->sortBy('created_at') as $payment)<small>{{ $paymentMethodLabels[$payment->method] ?? str($payment->method)->headline() }} <b>${{ number_format((float) $payment->amount, 2) }}</b></small>@endforeach</div>
            @endif
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
            @elseif($ticket->status === 'open')
                @if(! $canChargeTicket)
                    @if($unconfirmedServiceItems->isNotEmpty())
                        <p class="ticket-closed-note pending">Confirma los tipos de precio antes de cobrar.</p>
                    @else
                        <p class="form-error">No tienes una caja abierta para registrar el cobro. <a href="{{ route('cash.index') }}">Abrir mi caja</a></p>
                    @endif
                @endif
                <button class="button button-primary button-full ticket-charge-trigger" type="button" data-open-payment-dialog @disabled(! $canChargeTicket)>Cobrar ticket</button>
            @else
                <p class="ticket-closed-note">Este ticket está cancelado y no admite cobros.</p>
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
        <form method="POST" action="{{ route('tickets.services.store', $ticket) }}" class="service-price-form" id="service-price-form" hidden>@csrf<input type="hidden" name="salon_service_id" id="selected-service-id"><header><button type="button" class="dialog-close" data-return-service-browser aria-label="Volver">‹</button><div><p class="eyebrow">AGREGAR SERVICIO</p><h2 id="selected-service-name"></h2><p id="selected-service-category"></p></div><button type="button" class="dialog-close" data-close-dialog aria-label="Cerrar">×</button></header><div class="price-tier-picker" id="selected-service-tier-field"><span>Tipo de precio</span><input type="hidden" id="selected-service-tier" name="price_tier"><div class="price-tier-grid" id="selected-service-tier-grid"></div></div><label class="quantity-picker" id="selected-service-price-field"><span id="selected-service-price-label">Precio final del servicio</span><input id="selected-service-price" name="unit_price" type="number" min="0" step=".01" required></label><p class="service-price-hint" id="selected-service-price-hint"></p><footer><button type="button" class="button button-secondary" data-return-service-browser>Cancelar</button><button class="button button-primary" type="submit">Agregar al ticket</button></footer></form>
    </dialog>

    @if($ticket->appointment && ! $ticket->appointment->secondaryEmployee && $ticket->status !== 'paid')
        <dialog class="product-dialog stylist-dialog" id="stylist-dialog">
            <form method="POST" action="{{ route('tickets.stylists.secondary.store', $ticket) }}">
                @csrf
                <header><div><p class="eyebrow">ESTILISTAS RESPONSABLES</p><h2>Agregar otra estilista</h2><p>Selecciona quién acompañó este servicio.</p></div><button type="button" class="dialog-close" data-close-dialog aria-label="Cerrar">×</button></header>
                <div class="stylist-choice-grid">
                    @foreach($responsibleEmployees->where('id', '!=', $ticket->appointment->primary_employee_id) as $employee)
                        <label class="stylist-choice"><input type="radio" name="secondary_employee_id" value="{{ $employee->id }}" required><span class="customer-avatar">{{ str($employee->first_name)->substr(0, 1) }}</span><span><strong>{{ $employee->full_name }}</strong><small>{{ $employee->position }}</small></span></label>
                    @endforeach
                </div>
                <footer><button type="button" class="button button-secondary" data-close-dialog>Cancelar</button><button class="button button-primary" type="submit">Agregar estilista</button></footer>
            </form>
        </dialog>
    @endif

    <dialog class="product-dialog" id="product-dialog">
        <form method="POST" action="{{ route('tickets.products.store', $ticket) }}">@csrf<input type="hidden" name="product_variant_id" id="selected-product-id"><header><div class="product-mark" id="selected-product-mark">P</div><div><p class="eyebrow">AGREGAR AL TICKET</p><h2 id="selected-product-name"></h2><p id="selected-product-presentation"></p></div><button type="button" class="dialog-close" data-close-dialog aria-label="Cerrar">×</button></header><div class="dialog-product-price"><span>Precio unitario</span><strong id="selected-product-price"></strong><small id="selected-product-stock"></small></div><label><span>Crepera que recomendó el producto <small>Opcional</small></span><select name="employee_id"><option value="">Sin comisión de producto</option>@foreach($commissionableEmployees as $employee)<option value="{{ $employee->id }}" @selected((string) old('employee_id', $ticket->appointment?->primary_employee_id) === (string) $employee->id)>{{ $employee->full_name }}</option>@endforeach</select></label><label class="quantity-picker"><span>Cantidad</span><div><button type="button" data-quantity-change="-1">−</button><input id="selected-product-quantity" name="quantity" type="number" min="1" value="1" required><button type="button" data-quantity-change="1">+</button></div></label><footer><button type="button" class="button button-secondary" data-close-dialog>Cancelar</button><button class="button button-primary" type="submit">Agregar al ticket</button></footer></form>
    </dialog>

    @if($ticket->status === 'open')
        <dialog class="product-dialog payment-dialog" id="payment-dialog">
            <form method="POST" action="{{ route('tickets.payments.store', $ticket) }}" class="payment-modal-form">
                @csrf
                <header>
                    <div><p class="eyebrow">COBRAR TICKET</p><h2>Registrar pago</h2><p>Aplica pagos parciales y combina métodos hasta liquidar el saldo.</p></div>
                    <button type="button" class="dialog-close" data-close-dialog aria-label="Cerrar">×</button>
                </header>
                <div class="payment-modal-content">
                    <label class="payment-modal-amount"><span id="payment-amount-label">Monto a cobrar</span><input name="amount" id="payment-amount" type="number" min=".01" max="{{ max(0, $ticket->balance) }}" step=".01" value="{{ number_format(max(0, $ticket->balance), 2, '.', '') }}" required></label>
                    <fieldset class="payment-method-picker"><legend>Método de pago</legend><input type="hidden" name="method" id="payment-method" value="cash"><div class="payment-method-grid"><button class="payment-method-card selected" type="button" data-payment-method="cash"><b>$</b><span>Efectivo</span></button><button class="payment-method-card" type="button" data-payment-method="card"><b>▣</b><span>Tarjeta</span></button><button class="payment-method-card" type="button" data-payment-method="transfer"><b>↗</b><span>Transferencia</span></button><button class="payment-method-card" type="button" data-payment-method="gift_card"><b>◆</b><span>Gift Card</span></button><button class="payment-method-card" type="button" data-payment-method="other"><b>⋯</b><span>Otro</span></button></div></fieldset>
                    <div class="cash-received-field" id="cash-received-field">
                        <label><span>Recibido en efectivo</span><input id="cash-received" type="number" min="0" step=".01" inputmode="decimal" placeholder="0.00"></label>
                        <p id="cash-change" class="cash-change" aria-live="polite">Ingresa el efectivo recibido para calcular el cambio.</p>
                    </div>
                    @if($isCashAdministrator)
                        <div class="payment-admin-fields">
                            <label><span>Cuenta de ingreso</span><select name="finance_account_id"><option value="">Asignar automáticamente desde la caja</option>@foreach($financeAccounts as $financeAccount)<option value="{{ $financeAccount->id }}">{{ $financeAccount->name }}</option>@endforeach</select></label>
                            <label><span>Registrar en caja</span><select name="cash_register_id" required>@foreach($cashRegisters as $cashRegister)<option value="{{ $cashRegister->id }}">{{ $cashRegister->name }}</option>@endforeach</select></label>
                        </div>
                    @elseif($cashSession)
                        <div class="payment-destination"><strong>Se registrará en {{ $cashSession->register?->name }}</strong><span>Tu caja abierta asigna automáticamente el destino del cobro.</span></div>
                    @endif
                </div>
                <footer><button type="button" class="button button-secondary" data-close-dialog>Cancelar</button><button class="button button-primary" type="submit">Registrar pago</button></footer>
            </form>
        </dialog>
    @endif

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
            const serviceTierGrid = document.getElementById('selected-service-tier-grid');
            const servicePrice = document.getElementById('selected-service-price');
            const servicePriceField = document.getElementById('selected-service-price-field');
            let selectedCategory = 'all';

            const renderPriceTierCards = (grid, input, prices, selectedTier, onSelect) => {
                const selectTier = (price) => {
                    input.value = price.name;
                    grid.querySelectorAll('[data-price-tier]').forEach((card) => card.classList.toggle('selected', card.dataset.priceTier === price.name));
                    onSelect?.(price);
                };
                grid.replaceChildren(...prices.map((price) => {
                    const card = document.createElement('button');
                    card.type = 'button';
                    card.className = 'price-tier-card';
                    card.dataset.priceTier = price.name;
                    const name = document.createElement('strong');
                    name.textContent = price.name;
                    const amount = document.createElement('span');
                    amount.textContent = `$${Number(price.sale).toFixed(2)}`;
                    card.append(name, amount);
                    card.addEventListener('click', () => selectTier(price));
                    return card;
                }));
                const selectedPrice = prices.find((price) => price.name === selectedTier) || prices[0];
                if (selectedPrice) selectTier(selectedPrice);
            };

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
                const selectedServiceId = servicePriceForm.querySelector('[name="salon_service_id"]');
                selectedServiceId.value = String(card.dataset.id);
                selectedServiceId.setAttribute('value', String(card.dataset.id));
                document.getElementById('selected-service-name').textContent = card.dataset.name;
                document.getElementById('selected-service-category').textContent = card.dataset.categoryName;
                const fixed = card.dataset.priceType === 'fixed';
                serviceTierField.hidden = !fixed;
                servicePriceField.hidden = fixed;
                servicePrice.required = !fixed;
                const prices = JSON.parse(card.dataset.prices || '[]');
                renderPriceTierCards(serviceTierGrid, serviceTier, prices, prices[0]?.name || '', (price) => {
                    servicePrice.value = Number(price.sale).toFixed(2);
                });
                if (!fixed) {
                    serviceTier.value = '';
                    servicePrice.value = Number(card.dataset.price).toFixed(2);
                }
                document.getElementById('selected-service-price-label').textContent = fixed ? 'Precio de venta' : 'Precio final del servicio';
                document.getElementById('selected-service-price-hint').textContent = fixed
                    ? 'Selecciona la tarifa autorizada para este servicio.'
                    : 'Servicio con precio variable: confirma el importe final antes de agregarlo.';
                serviceBrowser.hidden = true;
                servicePriceForm.hidden = false;
            }));
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
            const serviceConfirmTierGrid = document.getElementById('confirm-service-tier-grid');
            const serviceConfirmPrice = document.getElementById('confirm-service-price');
            const confirmVariablePriceField = document.getElementById('confirm-variable-price-field');
            const confirmVariablePrice = document.getElementById('confirm-variable-price');
            const confirmVariablePriceHelp = document.getElementById('confirm-variable-price-help');
            document.querySelectorAll('[data-confirm-service]').forEach((button) => button.addEventListener('click', () => {
                const options = JSON.parse(button.dataset.priceOptions || '[]');
                const isVariable = button.dataset.priceType === 'variable';
                serviceConfirmForm.action = button.dataset.action;
                document.getElementById('confirm-service-name').textContent = button.dataset.serviceName;
                renderPriceTierCards(serviceConfirmTierGrid, serviceConfirmTier, options, button.dataset.currentTier || options[0]?.name || '', (price) => {
                    serviceConfirmPrice.textContent = `Precio de venta: $${Number(price.sale).toFixed(2)}`;
                });
                confirmVariablePriceField.hidden = !isVariable;
                confirmVariablePrice.required = isVariable;
                confirmVariablePrice.min = button.dataset.minimumPrice || 0;
                confirmVariablePrice.value = button.dataset.currentPrice || button.dataset.minimumPrice || '';
                confirmVariablePriceHelp.textContent = `Mínimo configurado: $${Number(button.dataset.minimumPrice || 0).toFixed(2)}`;
                serviceConfirmPrice.textContent = isVariable ? 'Confirma el precio final para actualizar el total del ticket.' : serviceConfirmPrice.textContent;
                document.getElementById('confirm-tier-field').hidden = isVariable || options.length === 0;
                serviceConfirmDialog.showModal();
            }));
            document.querySelector('[data-open-stylist-dialog]')?.addEventListener('click', () => document.getElementById('stylist-dialog')?.showModal());

            const paymentDialog = document.getElementById('payment-dialog');
            const paymentAmount = document.getElementById('payment-amount');
            const paymentAmountLabel = document.getElementById('payment-amount-label');
            const paymentMethod = document.getElementById('payment-method');
            const cashReceivedField = document.getElementById('cash-received-field');
            const cashReceived = document.getElementById('cash-received');
            const cashChange = document.getElementById('cash-change');
            const money = new Intl.NumberFormat('es-MX', {
                style: 'currency',
                currency: 'MXN',
                minimumFractionDigits: 2,
            });

            const updateCashChange = () => {
                if (! paymentMethod || ! cashReceivedField || ! cashChange) {
                    return;
                }

                const isCash = paymentMethod.value === 'cash';
                cashReceivedField.hidden = !isCash;
                if (paymentAmountLabel) {
                    paymentAmountLabel.textContent = paymentMethod.value === 'gift_card'
                        ? 'Monto a aplicar de la Gift Card'
                        : 'Monto a cobrar';
                }

                if (! isCash) {
                    cashChange.classList.remove('pending');

                    return;
                }

                const amount = Number(paymentAmount?.value || 0);
                const received = Number(cashReceived?.value || 0);

                if (received <= 0) {
                    cashChange.textContent = 'Ingresa el efectivo recibido para calcular el cambio.';
                    cashChange.classList.remove('pending');

                    return;
                }

                const difference = received - amount;
                cashChange.textContent = difference >= 0
                    ? `Cambio a entregar: ${money.format(difference)}`
                    : `Faltan ${money.format(Math.abs(difference))} por recibir.`;
                cashChange.classList.toggle('pending', difference < 0);
            };

            document.querySelector('[data-open-payment-dialog]')?.addEventListener('click', () => {
                paymentDialog?.showModal();
                updateCashChange();
            });
            document.querySelectorAll('[data-payment-method]').forEach((button) => button.addEventListener('click', () => {
                paymentMethod.value = button.dataset.paymentMethod;
                document.querySelectorAll('[data-payment-method]').forEach((card) => card.classList.toggle('selected', card === button));
                updateCashChange();
            }));
            paymentAmount?.addEventListener('input', updateCashChange);
            cashReceived?.addEventListener('input', updateCashChange);
        })();
    </script>
</x-layouts.app>
