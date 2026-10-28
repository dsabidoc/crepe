<x-layouts.app title="Nueva solicitud de inventario">
<section class="page-title"><div><p class="eyebrow">INVENTARIO · {{ $source->name }}</p><h1>{{ $source->code === 'CB' ? 'Solicitar insumos' : 'Solicitar inventario' }}</h1><p>Selecciona lo que necesitas. Almacén entregará lo disponible y cerrará esta solicitud sin pendientes.</p></div><a class="button button-secondary" href="{{ route('inventory.requests.index', ['location' => $source->code]) }}">Cancelar</a></section>
<form class="surface inventory-request-form" method="POST" action="{{ route('inventory.requests.store') }}">
    @csrf
    <input type="hidden" name="location" value="{{ $source->code }}">

    <div class="request-unit-notice"><strong>Solicita por unidades de producto</strong><span>No necesitas indicar gramos, mililitros o litros. Cada producto o envase completo cuenta como una unidad en el inventario.</span></div>

    <div class="inventory-request-workspace">
        <section class="inventory-request-catalog">
            <header class="inventory-request-catalog-head"><div><h2>{{ $source->code === 'CB' ? 'Insumos disponibles' : 'Productos disponibles' }}</h2><p>Busca y captura las unidades que necesitas.</p></div><span>{{ $variants->count() }} productos</span></header>
            <label class="product-search request-product-search"><span>⌕</span><input type="search" placeholder="Buscar por producto, marca o presentación" autocomplete="off" data-inventory-request-search></label>

            <div class="inventory-request-product-grid">
                @foreach($variants as $variant)
                    @php($stock = (float) $variant->balances->first(fn ($balance) => $balance->location->code === 'ALM')?->available_quantity)
                    @php($availableUnits = (int) floor($stock))
                    <label class="inventory-request-product" data-inventory-request-product data-product-id="{{ $variant->id }}" data-product-name="{{ $variant->product->name }}" data-product-brand="{{ $variant->product->brand ?: 'Sin marca' }}" data-product-supplier="{{ $variant->product->preferredSupplier?->name ?: 'Sin proveedor' }}" data-product-presentation="{{ $variant->name }}" data-search="{{ $variant->product->name }} {{ $variant->product->brand }} {{ $variant->name }} {{ $variant->sku }}">
                        <div><strong>{{ $variant->product->name }}</strong><x-product-reference :product="$variant->product" /><small>{{ $variant->name }} · Almacén: {{ $availableUnits }} unidades · En {{ $source->name }}: {{ (float) ($variant->balances->firstWhere('inventory_location_id', $source->id)?->available_quantity ?? 0) }} · Solicitadas pendientes: {{ (float) ($pendingUnits[$variant->id] ?? 0) }}</small></div>
                        <input type="hidden" name="items[{{ $variant->id }}][product_variant_id]" value="{{ $variant->id }}">
                        <input aria-label="Unidades de {{ $variant->product->name }}" type="number" name="items[{{ $variant->id }}][requested_units]" min="0" step="1" value="{{ old('items.'.$variant->id.'.requested_units', 0) }}" data-request-quantity>
                        <span>unidades</span>
                    </label>
                @endforeach
            </div>
            <p class="empty-state inventory-request-empty" data-inventory-request-empty hidden>No hay productos que coincidan con la búsqueda.</p>
        </section>

        <aside class="inventory-request-summary" aria-live="polite">
            <header><div><p class="eyebrow">SOLICITUD ACTUAL</p><h2>{{ $source->code === 'CB' ? 'Insumos seleccionados' : 'Productos seleccionados' }}</h2><span data-inventory-request-summary-count>0 productos · 0 unidades</span></div><button class="button button-primary" type="submit">Enviar solicitud</button></header>
            <p class="inventory-request-summary-empty" data-inventory-request-summary-empty>Aún no agregas productos. Captura unidades desde el catálogo.</p>
            <div class="inventory-request-summary-list" data-inventory-request-summary-list></div>
            <footer><strong>Total solicitado</strong><b data-inventory-request-summary-total>0 unidades</b></footer>
        </aside>
    </div>

    <label class="inventory-request-notes"><span>Nota opcional</span><textarea name="notes" rows="3" placeholder="Ej. Reponer antes del fin de semana">{{ old('notes') }}</textarea></label>
    <footer><a href="{{ route('inventory.requests.index', ['location' => $source->code]) }}">Cancelar</a><button class="button button-primary" type="submit">Enviar solicitud</button></footer>
</form>
<style>.inventory-request-workspace{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(280px,.8fr);gap:18px;align-items:start}.inventory-request-catalog{min-width:0}.inventory-request-catalog-head,.inventory-request-summary header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin:0 0 13px}.inventory-request-catalog-head h2,.inventory-request-summary h2{margin:0;color:#25324a;font-size:16px}.inventory-request-catalog-head p,.inventory-request-summary header span{margin:4px 0 0;color:#7d8aa0;font-size:11px}.inventory-request-catalog-head>span{display:inline-flex;align-items:center;min-height:26px;padding:0 9px;border-radius:999px;color:#9c4865;background:#fff0f4;font-size:10px;font-weight:800;white-space:nowrap}.request-product-search{width:100%;margin:0 0 14px}.inventory-request-workspace .inventory-request-product-grid{grid-template-columns:1fr;max-height:660px;padding-right:4px;overflow:auto}.inventory-request-summary{position:sticky;top:18px;min-height:292px;padding:16px;border:1px solid #ead6dc;border-radius:14px;background:#fffafb;box-shadow:0 10px 24px rgba(94,59,70,.06)}.inventory-request-summary header{padding-bottom:13px;border-bottom:1px solid #f1dfe4}.inventory-request-summary .eyebrow{margin:0 0 4px;font-size:9px}.inventory-request-summary header .button{flex:0 0 auto;white-space:nowrap}.inventory-request-summary-empty{margin:22px 0;color:#8490a3;font-size:11px;line-height:1.5;text-align:center}.inventory-request-summary-list{display:grid;gap:8px;max-height:430px;overflow:auto}.inventory-request-summary-item{display:grid;grid-template-columns:minmax(0,1fr) 74px 20px;align-items:center;gap:8px;padding:10px;border:1px solid #eedfe3;border-radius:9px;background:#fff}.inventory-request-summary-item>div{display:grid;gap:3px;min-width:0}.inventory-request-summary-item strong{overflow:hidden;color:#354159;font-size:11px;text-overflow:ellipsis;white-space:nowrap}.inventory-request-summary-item small{overflow:hidden;color:#8995a6;font-size:9px;text-overflow:ellipsis;white-space:nowrap}.inventory-request-summary-item input{width:100%;min-height:34px;padding:0 7px;border:1px solid #dce3ee;border-radius:7px;color:#344159;font:inherit;font-size:11px;font-weight:800;text-align:center}.inventory-request-summary-remove{display:grid;place-items:center;width:20px;height:20px;padding:0;border:0;border-radius:50%;color:#9e4c66;background:#fff0f3;font-size:15px;line-height:1;cursor:pointer}.inventory-request-summary footer{display:flex;justify-content:space-between;align-items:center;margin-top:14px;padding-top:13px;border-top:1px solid #f1dfe4;color:#657187;font-size:11px}.inventory-request-summary footer b{color:#a44665;font-size:13px}.inventory-request-empty{margin:16px 0 0}@media(max-width:900px){.inventory-request-workspace{grid-template-columns:1fr}.inventory-request-workspace .inventory-request-product-grid{max-height:none}.inventory-request-summary{position:static}}@media(max-width:560px){.inventory-request-summary header{align-items:stretch;flex-direction:column}.inventory-request-summary header .button{width:100%}.inventory-request-summary-item{grid-template-columns:minmax(0,1fr) 68px 20px}}</style>
<script>
    (() => {
        const search = document.querySelector('[data-inventory-request-search]');
        const products = [...document.querySelectorAll('[data-inventory-request-product]')];
        const empty = document.querySelector('[data-inventory-request-empty]');
        const summaryList = document.querySelector('[data-inventory-request-summary-list]');
        const summaryEmpty = document.querySelector('[data-inventory-request-summary-empty]');
        const summaryCount = document.querySelector('[data-inventory-request-summary-count]');
        const summaryTotal = document.querySelector('[data-inventory-request-summary-total]');
        const quantities = new Map(products.map((product) => [product.dataset.productId, product.querySelector('[data-request-quantity]')]));

        search?.addEventListener('input', () => {
            const shown = products.filter((product) => {
                const matches = window.CrepeSearch.matches(product.dataset.search, search.value);
                product.hidden = ! matches;

                return matches;
            }).length;
            empty.hidden = shown > 0;
        });

        const requestedProducts = () => products.map((product) => ({
            id: product.dataset.productId,
            name: product.dataset.productName,
            brand: product.dataset.productBrand,
            supplier: product.dataset.productSupplier,
            presentation: product.dataset.productPresentation,
            quantity: Math.max(0, Number(quantities.get(product.dataset.productId)?.value || 0)),
        })).filter((product) => product.quantity > 0);

        const pluralize = (amount, singular, plural) => `${amount} ${amount === 1 ? singular : plural}`;

        const renderSummary = () => {
            const selected = requestedProducts();
            const totalUnits = selected.reduce((total, product) => total + product.quantity, 0);
            summaryList.replaceChildren();
            summaryEmpty.hidden = selected.length > 0;
            summaryCount.textContent = `${pluralize(selected.length, 'producto', 'productos')} · ${pluralize(totalUnits, 'unidad', 'unidades')}`;
            summaryTotal.textContent = pluralize(totalUnits, 'unidad', 'unidades');

            selected.forEach((product) => {
                const item = document.createElement('article');
                item.className = 'inventory-request-summary-item';
                const copy = document.createElement('div');
                const name = document.createElement('strong');
                const details = document.createElement('small');
                name.textContent = product.name;
                details.textContent = `Marca: ${product.brand} · Proveedor: ${product.supplier}`;
                details.title = `${product.brand} · ${product.supplier} · ${product.presentation}`;
                copy.append(name, details);

                const quantity = document.createElement('input');
                quantity.type = 'number';
                quantity.min = '0';
                quantity.step = '1';
                quantity.value = product.quantity;
                quantity.setAttribute('aria-label', `Unidades solicitadas de ${product.name}`);
                quantity.dataset.summaryQuantity = product.id;

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'inventory-request-summary-remove';
                remove.textContent = '×';
                remove.setAttribute('aria-label', `Quitar ${product.name} de la solicitud`);
                remove.dataset.summaryRemove = product.id;
                item.append(copy, quantity, remove);
                summaryList.append(item);
            });
        };

        products.forEach((product) => product.querySelector('[data-request-quantity]')?.addEventListener('input', renderSummary));
        summaryList?.addEventListener('input', (event) => {
            const input = event.target.closest('[data-summary-quantity]');
            if (! input) return;
            quantities.get(input.dataset.summaryQuantity).value = input.value;
            renderSummary();
        });
        summaryList?.addEventListener('click', (event) => {
            const remove = event.target.closest('[data-summary-remove]');
            if (! remove) return;
            quantities.get(remove.dataset.summaryRemove).value = 0;
            renderSummary();
        });
        products[0]?.closest('form').addEventListener('submit', () => {
            products.forEach(product => { if (Number(product.querySelector('[data-request-quantity]').value) <= 0) { product.querySelectorAll('[name]').forEach(input => { input.disabled = true; }); } });
        });
        renderSummary();
    })();
</script>
</x-layouts.app>
