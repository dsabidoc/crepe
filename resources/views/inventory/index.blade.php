<x-layouts.app title="Inventario">
@vite('resources/css/color-bar-inventory.css')
@php
    $canDepleteColorBar = $location === 'CB' && auth()->user()->can('mode.color-bar.access');
@endphp
<section class="page-title flex-wrap">
    <div><h1>{{ $location === 'REC' ? 'Inventario Recepción' : ($location === 'CB' ? 'Inventario Color Bar' : 'Inventario') }}</h1><p>{{ $location === 'CB' ? 'Registra la baja de los envases cuando se agoten. Las fórmulas no descuentan existencias; solicita reposición a Almacén cuando la necesites.' : 'Consulta las unidades disponibles en Recepción y solicita reposición a Almacén.' }}</p></div>
    <div class="title-actions min-w-0 flex-wrap">
        @if($canDepleteColorBar)<a class="button button-primary" href="{{ route('inventory.color-bar.depletions.create') }}">Baja de insumos</a>@endif
        @can('inventory.export')<a class="button button-secondary" href="{{ route('inventory.export', ['location_code' => in_array($location, ['ALM', 'REC', 'CB'], true) ? $location : 'ALM']) }}">Descargar Excel</a><a class="button button-secondary" href="{{ route('inventory.export', ['format' => 'csv', 'location_code' => in_array($location, ['ALM', 'REC', 'CB'], true) ? $location : 'ALM']) }}">Descargar CSV</a>@endcan
        @if($location === 'ALM')@can('inventory.import')<a class="button button-primary" href="{{ route('inventory.import.create', ['location_code' => in_array($location, ['ALM', 'REC', 'CB'], true) ? $location : 'ALM']) }}">Importar Excel / CSV</a>@endcan
        @endif
        @can('imports.view')<a class="button button-secondary" href="{{ route('imports.index') }}">Historial de importaciones</a>@endcan
        @if(in_array($location, ['REC', 'CB'], true))<a class="button button-primary" href="{{ route('inventory.requests.create', ['location' => $location]) }}">+ Solicitar inventario</a>@endif
        <a class="button button-secondary" href="{{ route('inventory.requests.index', $location ? ['location' => $location] : []) }}">Solicitudes</a>
        <a class="button button-secondary" href="{{ route('activity.index', $location ? ['location' => $location] : []) }}">Bitácoras</a>
    </div>
</section>
<style>.product-stock-table:not(.color-bar-stock-table) .table-head,.product-stock-table:not(.color-bar-stock-table) .table-row{grid-template-columns:minmax(220px,2fr) repeat(3,minmax(100px,1fr)) 80px 100px}.color-bar-stock-table .table-head,.color-bar-stock-table .table-row{grid-template-columns:minmax(220px,2fr) repeat(3,minmax(100px,1fr)) 80px 150px}@media(max-width:760px){.product-stock-table .table-head{display:none!important}.product-stock-table .table-row{display:flex!important;flex-direction:column;align-items:stretch}}</style><section class="surface list-surface"><header class="list-controls"><form method="GET" class="list-filter-bar" aria-label="Filtros de inventario"><input type="hidden" name="location" value="{{ $location }}"><label class="list-search"><span>⌕</span><input name="search" value="{{ $search }}" placeholder="Producto, marca o SKU"></label><select name="category" aria-label="Filtrar por categoría"><option value="">Todas las categorías</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>@endforeach</select>@if($location === 'ALM')<label class="check-filter"><input type="checkbox" name="low_stock" value="1" @checked($lowStock)><span class="check-filter-control" aria-hidden="true"></span><span>Bajo mínimo</span></label>@endif<button class="button button-secondary" type="submit">Filtrar</button>@if($search || $categoryId || $lowStock)<a class="filter-clear" href="{{ route('inventory.index', $location ? ['location' => $location] : []) }}">Limpiar</a>@endif</form><span class="record-count">{{ $balances->total() }} existencias</span></header><div class="data-table product-data-table product-stock-table {{ $location === 'CB' ? 'color-bar-stock-table' : '' }}">
    <div class="table-head"><span>PRODUCTO</span><span>MARCA</span><span>PROVEEDOR</span><span>UBICACIÓN</span><span>UNIDAD</span><span>EXISTENCIA</span></div>
    @forelse($balances as $balance)
        @php
            $wholePackages = max(0, (int) floor((float) $balance->available_quantity));
        @endphp
        <div class="table-row">
            <div class="product-name-cell"><strong>{{ $balance->variant->product->name }}</strong>@if($location === 'CB' && ! $balance->variant->product->isColorBarSupply())<small>Existencia registrada · no habilitado como insumo</small>@endif@if(! in_array(mb_strtolower(trim($balance->variant->name)), ['unidad', 'unidades'], true))<small>{{ $balance->variant->name }}</small>@endif</div>
            <x-product-reference :product="$balance->variant->product" field="brand" />
            <x-product-reference :product="$balance->variant->product" field="supplier" />
            <span data-label="Ubicación">{{ $balance->location->name }}</span>
            <span data-label="Unidad">Unidades</span>
            <div data-label="Existencia" class="inventory-stock-cell">
                <strong class="product-stock-quantity {{ (float) $balance->available_quantity <= 0 ? 'inventory-low' : '' }}">{{ rtrim(rtrim(number_format((float) $balance->available_quantity, 3), '0'), '.') }}</strong>

                @if($canDepleteColorBar && $balance->variant->product->isColorBarSupply())
                    <button class="text-button inventory-depletion-trigger" type="button" data-deplete-color-bar data-balance-id="{{ $balance->id }}" data-action="{{ route('inventory.color-bar.deplete', $balance) }}" data-name="{{ $balance->variant->product->name }} · {{ $balance->variant->name }}" data-content="Cada envase cuenta como una unidad" data-max="{{ $wholePackages }}" aria-haspopup="dialog" aria-controls="color-bar-depletion" @disabled((float) $balance->available_quantity <= 0)>Registrar envases agotados</button>
                @endif
            </div>
        </div>
    @empty
        <p class="empty-state">No hay existencias registradas con estos filtros.</p>
    @endforelse
</div><div class="pagination-row">{{ $balances->links() }}</div></section>
@if($canDepleteColorBar)
    <dialog class="customer-ticket-dialog inventory-depletion-dialog" id="color-bar-depletion" aria-labelledby="color-bar-depletion-title" data-depletion-dialog>
        <form method="POST" data-depletion-form>
            @csrf
            <input type="hidden" name="balance_id" data-depletion-balance>
            <header><div><p class="eyebrow">INVENTARIO COLOR BAR</p><h2 id="color-bar-depletion-title">Registrar envases agotados</h2><p data-depletion-product></p></div><button class="dialog-close" type="button" data-close-depletion aria-label="Cerrar">×</button></header>
            <div class="inventory-depletion-body">
                <p>Registra únicamente los envases que ya se terminaron. Esta baja queda registrada en la bitácora.</p>
                <small data-depletion-content></small>
                <label><span>Envases agotados</span><input type="number" name="units" min="1" step="1" value="1" required data-depletion-units></label>
                <label class="inventory-depletion-all"><input type="checkbox" name="empty_stock" value="1" data-depletion-all><span>Se agotó toda la existencia registrada de este insumo</span></label>
                @error('units')<p class="form-error">{{ $message }}</p>@enderror
                <label><span>Nota (opcional)</span><textarea name="notes" rows="2" maxlength="300" data-depletion-notes></textarea></label>
                @error('notes')<p class="form-error">{{ $message }}</p>@enderror
            </div>
            <footer><button class="button button-secondary" type="button" data-close-depletion>Cancelar</button><button class="button button-primary" type="submit">Confirmar baja</button></footer>
        </form>
    </dialog>
    <script>
        (() => {
            const dialog = document.querySelector('[data-depletion-dialog]');
            const form = dialog.querySelector('[data-depletion-form]');
            const unitsInput = form.querySelector('[data-depletion-units]');
            const notesInput = form.querySelector('[data-depletion-notes]');
            const emptyStockInput = form.querySelector('[data-depletion-all]');
            const updateDepletionMode = () => {
                unitsInput.disabled = emptyStockInput.checked;
                unitsInput.required = !emptyStockInput.checked;
            };
            const openDepletion = (button, restoreDraft = false) => {
                form.action = button.dataset.action;
                form.querySelector('[data-depletion-balance]').value = button.dataset.balanceId;
                dialog.querySelector('[data-depletion-product]').textContent = button.dataset.name;
                dialog.querySelector('[data-depletion-content]').textContent = button.dataset.content;
                unitsInput.max = button.dataset.max;
                unitsInput.value = restoreDraft ? @js(old('units', 1)) : 1;
                notesInput.value = restoreDraft ? @js(old('notes', '')) : '';
                emptyStockInput.checked = restoreDraft ? @js((bool) old('empty_stock', false)) : Number(button.dataset.max) < 1;
                updateDepletionMode();
                dialog.showModal();
                (emptyStockInput.checked ? emptyStockInput : unitsInput).focus();
            };
            emptyStockInput.addEventListener('change', updateDepletionMode);
            document.querySelectorAll('[data-deplete-color-bar]').forEach((button) => button.addEventListener('click', () => openDepletion(button)));
            dialog.querySelectorAll('[data-close-depletion]').forEach((button) => button.addEventListener('click', () => dialog.close()));
            form.addEventListener('submit', () => { form.querySelector('[type="submit"]').disabled = true; });
            @if($errors->any())
                const previousButton = document.querySelector(`[data-deplete-color-bar][data-balance-id="${Number(@js(old('balance_id')))}"]`);
                if (previousButton) openDepletion(previousButton, true);
            @endif
        })();
    </script>
@endif
</x-layouts.app>
