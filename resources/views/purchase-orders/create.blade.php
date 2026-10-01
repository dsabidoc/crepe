<x-layouts.app title="Nueva orden de compra">
    <section class="page-heading">
        <div><p class="eyebrow">ALMACÉN · PEDIDOS</p><h1>Nueva orden de compra</h1><p>Selecciona un proveedor y agrega los productos que necesitas solicitar.</p></div>
        <a class="button button-secondary" href="{{ route('purchase-orders.index') }}">Volver a órdenes</a>
    </section>

    <section class="surface form-section purchase-order-create-section">
        <form method="POST" action="{{ route('purchase-orders.store') }}" id="new-purchase-order">
            @csrf
            <div class="field-grid">
                <label><span>Proveedor</span><select name="supplier_id" required><option value="">Selecciona un proveedor</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) old('supplier_id') === (string) $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></label>
                <label><span>Notas <small>Opcional</small></span><input name="notes" maxlength="1000" value="{{ old('notes') }}" placeholder="Indicaciones para el proveedor"></label>
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
