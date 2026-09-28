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
            <p>Después de crear la venta podrás buscar y agregar productos del inventario de Recepción.</p>
            <div class="schedule-slot-preview">
                <strong>Venta de producto</strong>
                <span>El inventario se descuenta al agregar cada producto y el cobro se registra en la caja abierta de Recepción.</span>
            </div>
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
    </script>
</x-layouts.app>
