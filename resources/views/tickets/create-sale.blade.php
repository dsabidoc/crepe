<x-layouts.app title="Nueva venta de producto">
    <section class="page-title">
        <div>
            <p class="eyebrow">RECEPCIÓN · PRODUCTOS</p>
            <h1>Nueva venta de producto</h1>
            <p>Registra una compra de productos sin crear una cita.</p>
        </div>
        <a class="button button-secondary" href="{{ route('workspace', 'recepcion') }}">Cancelar</a>
    </section>

    <form class="surface detail-form" method="POST" action="{{ route('tickets.product-sales.store') }}">
        @csrf
        <div class="form-section">
            <h2>Clienta</h2>
            <p>Es opcional. Si no registras una clienta, la venta quedará como <strong>Venta de mostrador</strong> para los reportes.</p>
            <div class="field-grid">
                <div class="customer-picker">
                    <span>Buscar o escribir clienta <small>Opcional</small></span>
                    <div class="customer-input-wrap">
                        <input id="sale-customer-search" name="customer_name" value="{{ old('customer_name') }}" placeholder="Busca una clienta o deja vacío" autocomplete="off">
                        <button type="button" id="sale-customer-toggle" aria-label="Mostrar clientas">⌄</button>
                    </div>
                    <div class="customer-results" id="sale-customer-results" hidden>
                        @foreach($customers as $customer)
                            <button type="button" class="customer-result" data-id="{{ $customer->id }}" data-name="{{ $customer->full_name }}">
                                <strong>{{ $customer->full_name }}</strong>
                                <small>{{ $customer->phone ?: 'Sin celular registrado' }}</small>
                            </button>
                        @endforeach
                    </div>
                    <input type="hidden" name="customer_id" id="sale-customer-id" value="{{ old('customer_id') }}">
                    <small id="sale-customer-match">Puedes dejarlo vacío para una venta de mostrador.</small>
                </div>
                <label>
                    <span>Celular <small>Opcional si es una clienta nueva</small></span>
                    <input name="customer_phone" value="{{ old('customer_phone') }}" placeholder="999 000 0000" inputmode="tel">
                </label>
            </div>
        </div>

        <div class="form-section">
            <h2>Productos</h2>
            <p>Selecciona los productos disponibles en Recepción y la cantidad para esta venta. El inventario se descuenta al crearla.</p>

            @error('products')
                <p class="field-error">{{ $message }}</p>
            @enderror

            @if($variants->isNotEmpty())
                <div class="product-card-grid sale-product-grid">
                    @foreach($variants as $variant)
                        @php
                            $stock = (float) $variant->balances->sum('available_quantity');
                            $selected = (bool) old("products.{$variant->id}.selected");
                        @endphp
                        <article class="pos-product-card sale-product-card {{ $selected ? 'selected' : '' }}" data-sale-product-card>
                            <span class="product-mark">{{ str($variant->product->name)->substr(0, 1)->upper() }}</span>
                            <span class="product-card-copy">
                                <strong title="{{ $variant->product->name }}">{{ $variant->product->name }}</strong>
                                <small>{{ $variant->product->brand ?: 'Producto de Recepción' }}</small>
                                <em>{{ $variant->name }} · {{ rtrim(rtrim(number_format($stock, 3, '.', ''), '0'), '.') }} disponibles</em>
                            </span>
                            <span class="product-card-price">${{ number_format((float) $variant->sale_price, 2) }}</span>

                            <div class="sale-product-controls">
                                <label class="sale-product-select">
                                    <input type="hidden" name="products[{{ $variant->id }}][product_variant_id]" value="{{ $variant->id }}">
                                    <input type="checkbox" name="products[{{ $variant->id }}][selected]" value="1" data-sale-product-toggle @checked($selected)>
                                    <span>Agregar a la venta</span>
                                </label>
                                <label class="sale-product-quantity">
                                    <span>Cantidad</span>
                                    <input
                                        type="number"
                                        name="products[{{ $variant->id }}][quantity]"
                                        value="{{ old("products.{$variant->id}.quantity", 1) }}"
                                        min="1"
                                        max="{{ max(1, floor($stock)) }}"
                                        step="1"
                                        inputmode="numeric"
                                        data-sale-product-quantity
                                        @disabled(! $selected)
                                    >
                                </label>
                                <label class="sale-product-employee">
                                    <span>Crepera que recomendó <small>Opcional</small></span>
                                    <select name="products[{{ $variant->id }}][employee_id]" @disabled(! $selected) data-sale-product-employee>
                                        <option value="">Sin comisión de producto</option>
                                        @foreach($commissionableEmployees as $employee)
                                            <option value="{{ $employee->id }}" @selected((string) old("products.{$variant->id}.employee_id") === (string) $employee->id)>{{ $employee->full_name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <p class="empty-state">No hay productos con existencias disponibles en Recepción. Solicita inventario a Almacén.</p>
            @endif
        </div>

        <footer class="form-footer">
            <a href="{{ route('workspace', 'recepcion') }}">Cancelar</a>
            <button class="button button-primary" type="submit">Crear venta</button>
        </footer>
    </form>

    <script>
        (() => {
            const search = document.getElementById('sale-customer-search');
            const customerId = document.getElementById('sale-customer-id');
            const match = document.getElementById('sale-customer-match');
            const results = document.getElementById('sale-customer-results');
            const toggle = document.getElementById('sale-customer-toggle');
            const options = [...document.querySelectorAll('#sale-customer-results .customer-result')];

            const sync = () => {
                const value = search.value.trim().toLowerCase();
                const selected = options.find((option) => option.dataset.name.toLowerCase() === value);
                customerId.value = selected?.dataset.id || '';
                match.textContent = selected
                    ? 'Clienta existente seleccionada.'
                    : value
                        ? 'Se registrará como clienta nueva con este nombre.'
                        : 'Puedes dejarlo vacío para una venta de mostrador.';
                results.hidden = false;
                options.forEach((option) => {
                    option.hidden = value !== '' && !option.dataset.name.toLowerCase().includes(value);
                });
            };

            search?.addEventListener('input', sync);
            search?.addEventListener('focus', () => {
                results.hidden = false;
                sync();
            });
            toggle?.addEventListener('click', () => {
                results.hidden = !results.hidden;
                if (!results.hidden) search.focus();
            });
            options.forEach((option) => option.addEventListener('click', () => {
                search.value = option.dataset.name;
                customerId.value = option.dataset.id;
                match.textContent = 'Clienta existente seleccionada.';
                results.hidden = true;
            }));
            document.addEventListener('click', (event) => {
                if (!event.target.closest('.customer-picker')) results.hidden = true;
            });
        })();

        (() => {
            document.querySelectorAll('[data-sale-product-card]').forEach((card) => {
                const toggle = card.querySelector('[data-sale-product-toggle]');
                const quantity = card.querySelector('[data-sale-product-quantity]');
                const employee = card.querySelector('[data-sale-product-employee]');

                const sync = () => {
                    card.classList.toggle('selected', toggle.checked);
                    quantity.disabled = !toggle.checked;
                    employee.disabled = !toggle.checked;
                };

                toggle.addEventListener('change', sync);
                sync();
            });
        })();
    </script>
</x-layouts.app>
