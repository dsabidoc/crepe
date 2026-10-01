<x-layouts.app title="Órdenes de compra">
    <section class="page-heading">
        <div><p class="eyebrow">ALMACÉN</p><h1>Órdenes de compra</h1><p>Prepara pedidos por proveedor, ajusta sus partidas y registra la recepción real.</p></div>
    </section>

    <section class="surface form-section">
        <h2>Catálogo de proveedores</h2>
        <p>Configura el plazo de pago para que la cuenta por pagar se genere con la fecha correcta.</p>
        <form method="POST" action="{{ route('suppliers.store') }}" class="purchase-order-supplier-form">@csrf
            <div class="field-grid">
                <label><span>Nombre</span><input name="name" required></label>
                <label><span>Contacto</span><input name="contact_name"></label>
                <label><span>Teléfono</span><input name="phone"></label>
                <label><span>Días de crédito</span><input name="payment_grace_days" type="number" min="0" value="0"></label>
            </div>
            <button class="button button-secondary" type="submit">Agregar proveedor</button>
        </form>
    </section>

    <section class="surface form-section">
        <h2>Nueva orden</h2>
        <p>Agrega todas las partidas que necesitas para un mismo proveedor.</p>
        <form method="POST" action="{{ route('purchase-orders.store') }}" id="new-purchase-order">@csrf
            <div class="field-grid">
                <label><span>Proveedor</span><select name="supplier_id" required><option value="">Selecciona un proveedor</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) old('supplier_id') === (string) $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></label>
                <label><span>Notas</span><input name="notes" maxlength="1000" value="{{ old('notes') }}" placeholder="Opcional"></label>
            </div>
            <div class="purchase-order-lines" id="new-order-lines">
                <div class="purchase-order-line">
                    <label><span>Producto</span><select name="items[0][product_variant_id]" required><option value="">Selecciona un producto</option>@foreach($variants as $variant)<option value="{{ $variant->id }}">{{ $variant->product->name }} · {{ $variant->name }}</option>@endforeach</select></label>
                    <label><span>Cantidad</span><input name="items[0][ordered_quantity]" type="number" step=".001" min=".001" required placeholder="0"></label>
                    <button type="button" class="button button-secondary purchase-order-remove" aria-label="Quitar producto" disabled>×</button>
                </div>
            </div>
            <template id="purchase-order-line-template"><div class="purchase-order-line"><label><span>Producto</span><select name="items[__INDEX__][product_variant_id]" required><option value="">Selecciona un producto</option>@foreach($variants as $variant)<option value="{{ $variant->id }}">{{ $variant->product->name }} · {{ $variant->name }}</option>@endforeach</select></label><label><span>Cantidad</span><input name="items[__INDEX__][ordered_quantity]" type="number" step=".001" min=".001" required placeholder="0"></label><button type="button" class="button button-secondary purchase-order-remove" aria-label="Quitar producto">×</button></div></template>
            <div class="purchase-order-actions"><button class="button button-secondary" type="button" id="add-purchase-order-line">+ Agregar producto</button><button class="button button-primary" type="submit">Crear orden</button></div>
        </form>
    </section>

    <section class="surface list-surface">
        <header class="list-controls"><div><p class="eyebrow">PEDIDOS</p><h2>Órdenes registradas</h2></div><form method="GET" class="list-filter-bar" aria-label="Filtros de órdenes de compra"><label class="list-search"><span>⌕</span><input name="search" value="{{ $search }}" placeholder="Folio o proveedor"></label><select name="supplier" aria-label="Filtrar por proveedor"><option value="">Todos los proveedores</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected($supplierId === $supplier->id)>{{ $supplier->name }}</option>@endforeach</select><select name="status" aria-label="Filtrar por estatus"><option value="">Todos los estatus</option><option value="draft" @selected($status === 'draft')>Borradores</option><option value="received" @selected($status === 'received')>Recibidas</option></select><button class="button button-secondary" type="submit">Filtrar</button>@if($search || $status || $supplierId)<a class="filter-clear" href="{{ route('purchase-orders.index') }}">Limpiar</a>@endif</form><span class="record-count">{{ $orders->count() }} órdenes</span></header>
        <div class="data-table">
            <div class="table-head payroll-head"><span>FOLIO</span><span>PROVEEDOR</span><span>PRODUCTOS</span><span>ESTATUS</span><span></span></div>
            @forelse($orders as $order)
                <a class="table-row payroll-head" href="{{ route('purchase-orders.show', $order) }}"><strong>{{ $order->code }}</strong><span>{{ $order->supplier->name }}</span><span>{{ $order->items->count() }}</span><span class="status-badge">{{ $order->status === 'received' ? 'Recibida' : 'Borrador' }}</span><b>→</b></a>
            @empty
                <p class="empty-state">Aún no hay órdenes de compra.</p>
            @endforelse
        </div>
    </section>

    <script>
        (() => {
            const lines = document.getElementById('new-order-lines');
            const template = document.getElementById('purchase-order-line-template');
            const refreshRemoveButtons = () => lines.querySelectorAll('.purchase-order-remove').forEach((button) => { button.disabled = lines.children.length === 1; });
            document.getElementById('add-purchase-order-line').addEventListener('click', () => {
                const index = lines.children.length;
                const fragment = template.content.cloneNode(true);
                fragment.firstElementChild.querySelectorAll('[name]').forEach((field) => { field.name = field.name.replace('__INDEX__', index); });
                lines.append(fragment);
                document.dispatchEvent(new Event('DOMContentLoaded'));
                refreshRemoveButtons();
            });
            lines.addEventListener('click', (event) => { const button = event.target.closest('.purchase-order-remove'); if (button && lines.children.length > 1) { button.closest('.purchase-order-line').remove(); refreshRemoveButtons(); } });
            refreshRemoveButtons();
        })();
    </script>
</x-layouts.app>
