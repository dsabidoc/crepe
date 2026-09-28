<x-layouts.app title="Color Bar">
    @php
        $selectedTicket = $tickets->firstWhere('id', $selectedTicketId);
        $initialFormulaItems = $editingFormula?->items->map(fn ($item) => [
            'variantId' => $item->product_variant_id,
            'name' => $item->variant->product->name,
            'brand' => $item->variant->product->brand,
            'baseUnit' => $item->unit,
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'notes' => $item->notes,
        ])->values() ?? collect();
    @endphp

    <section class="page-title color-bar-title">
        <div><p class="eyebrow">COLOR BAR · OPERACIÓN</p><h1>Tickets Color Bar</h1><p>Selecciona una sola clienta, prepara sus consumos y confírmalos cuando estén listos.</p></div>
        <span class="pill confirmada">{{ $tickets->count() }} tickets disponibles</span>
    </section>

    <section class="color-bar-workbench" data-color-bar-workbench>
        <section class="surface color-ticket-selector">
            <header><div><h2>Seleccionar ticket</h2><p>Trabajas una fórmula a la vez.</p></div>@if($selectedTicket)<a class="text-button" href="{{ route('color-bar.index') }}">Quitar selección</a>@endif</header>
            <form method="GET" class="list-filter-bar color-ticket-filter" aria-label="Buscar tickets Color Bar"><label class="list-search"><span>⌕</span><input name="ticket_search" value="{{ $ticketSearch }}" placeholder="Buscar clienta o ticket"></label><button class="button button-secondary" type="submit">Buscar</button>@if($ticketSearch)<a class="filter-clear" href="{{ route('color-bar.index') }}">Limpiar</a>@endif</form>
            <div class="color-ticket-strip" aria-label="Tickets activos de Color Bar">
                @forelse($tickets as $ticket)
                    <a class="color-ticket-card {{ $selectedTicketId === $ticket->id ? 'selected' : '' }}" href="{{ route('color-bar.index', ['ticket' => $ticket->id]) }}"><span class="color-ticket-card-top"><strong>{{ $ticket->customer->full_name }}</strong><small>{{ $ticket->code }}</small></span><span>{{ $ticket->appointment?->services->pluck('name_snapshot')->join(' + ') ?: 'Visita sin cita' }}</span><footer><i class="pill {{ $ticket->status === 'in_service' ? 'en-servicio' : 'confirmada' }}">{{ $ticket->status === 'in_service' ? 'EN SERVICIO' : 'ABIERTO' }}</i>@if($selectedTicketId === $ticket->id)<b>✓</b>@endif</footer></a>
                @empty
                    <p class="empty-state">No hay tickets abiertos para Color Bar.</p>
                @endforelse
            </div>
        </section>

        <section class="color-bar-main">
            <section class="surface color-product-browser">
                <header><div><h2>Productos Color Bar</h2><p>Solamente existencias disponibles en la ubicación Color Bar.</p></div><span>{{ $variants->count() }} disponibles</span></header>
                <label class="product-search"><span>⌕</span><input type="search" placeholder="Buscar por producto o marca" data-color-product-search></label>
                <div class="color-category-tabs" data-color-category-tabs><button class="selected" type="button" data-category-filter="all">Todos</button>@foreach($categories as $category)<button type="button" data-category-filter="{{ $category->id }}"><i class="category-dot" style="background: {{ $category->color }}"></i>{{ $category->name }}</button>@endforeach</div>
                <div class="color-product-grid" data-color-product-grid>
                    @forelse($variants as $variant)
                        @php($available = (float) $variant->balances->first()?->available_quantity)
                        <button class="color-product-card" type="button" data-color-product data-category="{{ $variant->product->product_category_id }}" data-id="{{ $variant->id }}" data-name="{{ $variant->product->name }}" data-brand="{{ $variant->product->brand }}" data-unit="{{ $variant->base_unit }}" data-available="{{ $available }}" @disabled(! $selectedTicket)><span class="product-mark">{{ str($variant->product->name)->substr(0, 1) }}</span><span class="color-product-copy"><strong>{{ $variant->product->name }}</strong><small>{{ $variant->product->brand }} · {{ $variant->name }}</small><em>{{ number_format($available, 0) }} {{ $variant->base_unit }} disponibles</em></span><b>+</b></button>
                    @empty
                        <p class="empty-state">No hay existencias Color Bar disponibles.</p>
                    @endforelse
                </div>
                @unless($selectedTicket)<p class="color-selection-hint">Selecciona un ticket arriba para comenzar a agregar productos.</p>@endunless
            </section>

            <aside class="surface color-cart">
                <header><div><p class="eyebrow">{{ $editingFormula ? 'CORREGIR FÓRMULA' : 'FÓRMULA ACTUAL' }}</p><h2>{{ $selectedTicket?->customer->full_name ?? 'Selecciona un ticket' }}</h2><span>{{ $selectedTicket ? $selectedTicket->code : 'Los productos se agregarán aquí.' }}</span></div>@if($selectedTicket)<span class="cart-selected-mark">✓</span>@endif</header>
                <form method="POST" action="{{ $editingFormula ? route('color-bar.update', $editingFormula) : route('color-bar.store') }}" data-formula-cart-form>
                    @csrf
                    <input type="hidden" name="ticket_id" value="{{ $selectedTicketId }}" data-selected-ticket>
                    <div class="color-cart-items" data-color-cart-items></div>
                    <p class="color-cart-empty" data-color-cart-empty>{{ $editingFormula ? 'Ajusta los productos de la fórmula.' : 'Aún no agregas productos a la fórmula.' }}</p>
                    <label class="color-cart-note"><span>Nota general (opcional)</span><textarea name="notes" rows="2" placeholder="Ej. Aplicación en raíz y medios">{{ old('notes', $editingFormula?->notes) }}</textarea></label>
                    @error('items')<small class="form-error">{{ $message }}</small>@enderror @error('ticket')<small class="form-error">{{ $message }}</small>@enderror @error('formula')<small class="form-error">{{ $message }}</small>@enderror
                    <button class="button button-primary button-full" type="button" data-confirm-formula @disabled(! $selectedTicket)>{{ $editingFormula ? 'Confirmar corrección' : 'Confirmar fórmula' }}</button>
                    <p class="color-cart-footnote">El descuento de inventario ocurre sólo después de confirmar.</p>
                </form>
            </aside>
        </section>
    </section>

    <section class="surface color-formula-history">
        <header><div><h2>Fórmulas registradas</h2><p>Elige una para corregir sólo sus consumos de Color Bar.</p></div><span>{{ $recentFormulas->count() }} editables</span></header>
        <div class="formula-history-list">
            @forelse($recentFormulas as $formula)
                <a class="formula-history-row {{ $editingFormula?->id === $formula->id ? 'selected' : '' }}" href="{{ route('color-bar.index', ['ticket' => $formula->ticket_id, 'formula' => $formula->id]) }}"><span class="customer-avatar">{{ str($formula->ticket->customer->first_name)->substr(0, 1) }}</span><span><strong>{{ $formula->ticket->customer->full_name }}</strong><small>{{ $formula->ticket->code }} · {{ $formula->items->count() }} productos · {{ $formula->created_at->format('d/m · H:i') }}</small></span><em>{{ $formula->items->pluck('variant.product.name')->join(', ') }}</em><b>{{ $editingFormula?->id === $formula->id ? 'Editando' : 'Editar' }} →</b></a>
            @empty
                <p class="empty-state">Las fórmulas que confirmes aparecerán aquí mientras su ticket siga abierto.</p>
            @endforelse
        </div>
    </section>

    <dialog class="color-formula-dialog" data-formula-item-dialog>
        <form method="dialog" data-formula-item-form><header><span class="product-mark" data-dialog-mark>F</span><div><p class="eyebrow">AGREGAR A FÓRMULA</p><h2 data-dialog-title>Producto</h2><span data-dialog-meta></span></div><button class="dialog-close" value="cancel" aria-label="Cerrar">×</button></header><div class="formula-quantity-field"><label><span>Cantidad a aplicar</span><input type="number" min="0.001" step="0.001" inputmode="decimal" required data-formula-quantity></label><div class="formula-unit-buttons"><button type="button" data-formula-unit="g">gr</button><button type="button" data-formula-unit="ml">ml</button><button type="button" data-formula-unit="l">lt</button></div></div><label class="formula-item-note"><span>Nota (opcional)</span><textarea rows="3" maxlength="300" placeholder="Ej. mezcla para raíz" data-formula-item-notes></textarea></label><footer><button class="button button-secondary" value="cancel">Cancelar</button><button class="button button-primary" type="submit" value="confirm">Agregar al carrito</button></footer></form>
    </dialog>

    <dialog class="formula-confirm-dialog" data-formula-confirm-dialog><form method="dialog"><div class="confirm-symbol">✓</div><p class="eyebrow">CONFIRMAR FÓRMULA</p><h2>¿Confirmar los consumos?</h2><p>Se descontarán del inventario de Color Bar y se registrarán en el ticket seleccionado.</p><footer><button class="button button-secondary" value="cancel">Revisar fórmula</button><button class="button button-primary" type="submit" value="confirm">Sí, confirmar</button></footer></form></dialog>

    <script>
        (() => {
            if (!document.querySelector('[data-color-bar-workbench]')) return;
            const selectedTicket = @js($selectedTicketId), cart = @js($initialFormulaItems), itemDialog = document.querySelector('[data-formula-item-dialog]'), itemForm = document.querySelector('[data-formula-item-form]'), confirmDialog = document.querySelector('[data-formula-confirm-dialog]'), cartForm = document.querySelector('[data-formula-cart-form]'), cartItems = document.querySelector('[data-color-cart-items]'), cartEmpty = document.querySelector('[data-color-cart-empty]'), confirmButton = document.querySelector('[data-confirm-formula]'), quantityInput = document.querySelector('[data-formula-quantity]'), noteInput = document.querySelector('[data-formula-item-notes]'), unitButtons = [...document.querySelectorAll('[data-formula-unit]')];
            let activeProduct, editingIndex = null, activeUnit = 'g';
            const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character]));
            const unitLabel = (unit) => unit === 'l' ? 'lt' : unit === 'g' ? 'gr' : unit;
            const permittedUnits = (baseUnit) => baseUnit === 'g' ? ['g'] : ['ml', 'l'];
            function renderCart() { cartItems.innerHTML = cart.map((item, index) => `<article class="color-cart-item"><span class="product-mark">${escapeHtml(item.name).slice(0, 1)}</span><span><strong>${escapeHtml(item.name)}</strong><small>${Number(item.quantity).toLocaleString('es-MX')} ${unitLabel(item.unit)}${item.notes ? ` · ${escapeHtml(item.notes)}` : ''}</small></span><div><button type="button" aria-label="Editar ${escapeHtml(item.name)}" data-cart-edit="${index}">⌕</button><button type="button" aria-label="Quitar ${escapeHtml(item.name)}" data-cart-remove="${index}">×</button></div><input type="hidden" name="items[${index}][product_variant_id]" value="${item.variantId}"><input type="hidden" name="items[${index}][quantity]" value="${item.quantity}"><input type="hidden" name="items[${index}][unit]" value="${item.unit}"><input type="hidden" name="items[${index}][notes]" value="${escapeHtml(item.notes)}"></article>`).join(''); cartEmpty.hidden = cart.length > 0; confirmButton.disabled = !selectedTicket || cart.length === 0; }
            function setUnit(unit) { activeUnit = unit; const allowed = permittedUnits(activeProduct.baseUnit); unitButtons.forEach((button) => { const buttonUnit = button.dataset.formulaUnit; button.disabled = !allowed.includes(buttonUnit); button.classList.toggle('selected', buttonUnit === activeUnit); }); }
            function openItemDialog(product, index = null) { activeProduct = product; editingIndex = index; const currentItem = index === null ? null : cart[index]; document.querySelector('[data-dialog-mark]').textContent = product.name.slice(0, 1); document.querySelector('[data-dialog-title]').textContent = product.name; document.querySelector('[data-dialog-meta]').textContent = `${product.brand || 'Color Bar'} · ${Number(product.available).toLocaleString('es-MX')} ${product.baseUnit} disponibles`; quantityInput.value = currentItem?.quantity ?? (product.baseUnit === 'g' ? 35 : 50); noteInput.value = currentItem?.notes ?? ''; setUnit(currentItem?.unit ?? permittedUnits(product.baseUnit)[0]); itemDialog.showModal(); }
            document.querySelectorAll('[data-color-product]').forEach((button) => button.addEventListener('click', () => openItemDialog({ id: Number(button.dataset.id), name: button.dataset.name, brand: button.dataset.brand, baseUnit: button.dataset.unit, available: Number(button.dataset.available) })));
            unitButtons.forEach((button) => button.addEventListener('click', () => { if (!button.disabled) setUnit(button.dataset.formulaUnit); }));
            itemForm.addEventListener('submit', (event) => { if (event.submitter?.value !== 'confirm') return; event.preventDefault(); const quantity = Number(quantityInput.value); if (!quantity || quantity <= 0) { quantityInput.focus(); return; } const item = { variantId: activeProduct.id, name: activeProduct.name, brand: activeProduct.brand, baseUnit: activeProduct.baseUnit, quantity, unit: activeUnit, notes: noteInput.value.trim() }; if (editingIndex === null) cart.push(item); else cart[editingIndex] = item; itemDialog.close(); renderCart(); });
            cartItems.addEventListener('click', (event) => { const removeButton = event.target.closest('[data-cart-remove]'); if (removeButton) { cart.splice(Number(removeButton.dataset.cartRemove), 1); renderCart(); return; } const editButton = event.target.closest('[data-cart-edit]'); if (editButton) { const index = Number(editButton.dataset.cartEdit), item = cart[index]; openItemDialog({ id: item.variantId, name: item.name, brand: item.brand, baseUnit: item.baseUnit, available: 0 }, index); } });
            document.querySelectorAll('[data-category-filter]').forEach((button) => button.addEventListener('click', () => { document.querySelectorAll('[data-category-filter]').forEach((tab) => tab.classList.toggle('selected', tab === button)); document.querySelectorAll('[data-color-product]').forEach((product) => product.hidden = button.dataset.categoryFilter !== 'all' && product.dataset.category !== button.dataset.categoryFilter); }));
            document.querySelector('[data-color-product-search]').addEventListener('input', (event) => { const search = event.target.value.toLocaleLowerCase('es-MX'); document.querySelectorAll('[data-color-product]').forEach((product) => product.hidden = !`${product.dataset.name} ${product.dataset.brand}`.toLocaleLowerCase('es-MX').includes(search)); });
            confirmButton.addEventListener('click', () => { if (cart.length) confirmDialog.showModal(); }); confirmDialog.addEventListener('close', () => { if (confirmDialog.returnValue === 'confirm') cartForm.requestSubmit(); }); renderCart();
        })();
    </script>
</x-layouts.app>
