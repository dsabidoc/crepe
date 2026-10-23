<x-layouts.app title="Baja de insumos">
    @vite('resources/css/color-bar-inventory.css')
    <section class="page-title">
        <div><p class="eyebrow">COLOR BAR · INVENTARIO</p><h1>Baja de insumos</h1><p>Agrega los envases agotados y elige si también necesitas reposición. Revisa todo antes de confirmar.</p></div>
        <div class="title-actions"><a class="button button-secondary" href="{{ route('inventory.index', ['location' => 'CB']) }}">Ver inventario</a><a class="button button-secondary" href="{{ route('inventory.requests.create', ['location' => 'CB']) }}">Solicitar insumos</a></div>
    </section>
    <form class="supply-depletion-form" method="POST" action="{{ route('inventory.color-bar.depletions.store') }}" data-supply-depletion-form>
        @csrf
        <input type="hidden" name="request_key" value="{{ old('request_key', $requestKey) }}">
        <div data-supply-payload hidden></div>
        <section class="surface supply-depletion-catalog">
            <header><h2>Insumos con existencias <small>{{ $balances->count() }}</small></h2><label class="list-search"><span>⌕</span><input type="search" aria-label="Buscar insumo" placeholder="Buscar insumo, marca o proveedor" data-supply-search></label></header>
            <div class="supply-depletion-list">
                @forelse($balances as $balance)
                    @php
                        $packages = (float) $balance->available_quantity;
                    @endphp
                    <article class="supply-depletion-row" data-supply-row data-id="{{ $balance->id }}" data-name="{{ $balance->variant->product->name }} · {{ $balance->variant->name }}" data-search="{{ $balance->variant->product->name }} {{ $balance->variant->product->brand }} {{ $balance->variant->product->preferredSupplier?->name }} {{ $balance->variant->name }} {{ $balance->variant->sku }}" data-packages="{{ $packages }}" data-expected="{{ $balance->available_quantity }}">
                        <div class="supply-depletion-info">
                            <strong>{{ $balance->variant->product->name }}</strong>
                            <small>{{ $balance->variant->product->brand ?: 'Sin marca' }} · {{ $balance->variant->product->preferredSupplier?->name ?: 'Sin proveedor' }} · {{ $balance->variant->name }}</small>
                            <small>Existencia: <b>{{ rtrim(rtrim(number_format($packages, 3, '.', ','), '0'), '.') }} envases</b><span data-supply-badge></span></small>
                        </div>
                        <div class="supply-depletion-actions" role="group" aria-label="Acción para {{ $balance->variant->product->name }}">
                            <button type="button" data-supply-action="deplete" aria-pressed="false">Solo baja</button>
                            <button type="button" data-supply-action="request" aria-pressed="false">Baja y solicitar</button>
                        </div>
                        <div class="supply-depletion-editor" data-supply-editor hidden>
                            <div class="supply-depletion-fields">
                                <label>Envases a dar de baja<input aria-label="Envases a dar de baja de {{ $balance->variant->product->name }}" type="number" min="1" step="1" max="{{ min(1000000, max(0, (int) floor(round($packages, 6)))) }}" value="1" data-supply-units disabled></label>
                                <label data-supply-request-label hidden>Envases a solicitar<input aria-label="Envases a solicitar de {{ $balance->variant->product->name }}" type="number" min="1" step="1" max="1000000" value="1" data-supply-requested disabled></label>
                            </div>
                            <label class="supply-depletion-empty"><input type="checkbox" data-supply-empty disabled>Se agotó toda la existencia</label>
                            <small data-supply-request-help hidden>Puedes solicitar una cantidad distinta a la baja. Almacén recibirá una sola solicitud al confirmar.</small>
                            <div class="supply-depletion-editor-actions"><button class="button button-primary" type="button" data-supply-add>Agregar al listado</button><button class="button button-secondary" type="button" data-supply-edit-cancel>Cancelar</button></div>
                        </div>
                    </article>
                @empty
                    <p class="empty-state">No hay insumos con existencias para dar de baja. Puedes solicitar reposición a Almacén.</p>
                @endforelse
            </div>
            <p class="empty-state" data-supply-no-results hidden>No hay insumos que coincidan con tu búsqueda.</p>
        </section>
        <aside class="surface supply-depletion-summary">
            <header><p class="eyebrow">POR CONFIRMAR</p><h2>Tu listado</h2><p>Agregar al listado todavía no modifica el inventario.</p></header>
            <section><h3>Bajas <span data-supply-count>0</span></h3><ul data-supply-summary aria-live="polite"></ul><p data-supply-empty-list>Agrega los insumos que se agotaron.</p></section>
            <section><h3>Solicitar a Almacén <span data-supply-request-count>0</span></h3><ul data-supply-request-summary aria-live="polite"></ul><p data-supply-empty-request>Usa «Baja y solicitar» si necesitas reposición.</p></section>
            <label class="supply-depletion-note"><span>Nota del lote (opcional)</span><textarea name="notes" rows="2" maxlength="180" placeholder="Ej. Envases agotados durante el turno">{{ old('notes') }}</textarea></label>
            <p data-supply-edit-hint role="status" hidden>Agrega o cancela el insumo que estás editando antes de continuar.</p>
            <button class="button button-primary" type="submit" data-supply-submit disabled>Revisar bajas</button>
            <small>Las fórmulas siguen disponibles aunque un insumo quede en cero. La reposición ingresará al inventario cuando Almacén la entregue.</small>
        </aside>
        <dialog class="customer-ticket-dialog inventory-depletion-dialog" data-supply-confirm aria-labelledby="supply-confirm-title">
            <header><h2 id="supply-confirm-title">Revisar bajas y solicitudes</h2></header>
            <div class="inventory-depletion-body"><h3>Bajas del inventario de Color Bar</h3><ul data-supply-confirm-list></ul><div data-supply-confirm-request-section hidden><h3>Solicitud a Almacén</h3><ul data-supply-confirm-request-list></ul><p>Se enviará una sola solicitud con estos insumos.</p></div><p>La baja y su solicitud se registrarán juntas. Las fórmulas no se modifican.</p></div>
            <footer><button class="button button-secondary" type="button" data-supply-cancel>Seguir editando</button><button class="button button-primary" type="button" data-supply-confirm-save>Confirmar bajas</button></footer>
        </dialog>
    </form>
    <section class="surface supply-depletion-history">
        <header><h2>Últimas bajas por lote</h2><a href="{{ route('activity.index', ['location' => 'CB', 'movement_type' => 'color_bar_depletion']) }}">Ver bitácora completa</a></header>
        @forelse($recentBatches as $batch)
            <details><summary>Baja #{{ $batch->id }} · {{ $batch->created_at->format('d/m/Y H:i') }} · {{ $batch->creator?->name }} · {{ $batch->movements->count() }} insumos</summary>
                @if($batch->inventoryRequest)
                    <p>Solicitud a Almacén:
                        @if(auth()->user()->can('inventory.requests.manage') || $batch->inventoryRequest->requested_by === auth()->id())
                            <a href="{{ route('inventory.requests.show', $batch->inventoryRequest) }}">{{ $batch->inventoryRequest->code }}</a>
                        @else
                            {{ $batch->inventoryRequest->code }}
                        @endif
                        · {{ $batch->inventoryRequest->status === 'pending' ? 'Pendiente de entrega' : 'Cerrada' }}
                    </p>
                    <ul>@foreach($batch->inventoryRequest->items as $item)<li>{{ $item->variant->product->name }} · Solicitados: {{ $item->requested_units }} envases</li>@endforeach</ul>
                @endif
                @if($batch->notes)<p>{{ $batch->notes }}</p>@endif
                <ul>@foreach($batch->movements as $movement)<li><strong>{{ $movement->variant->product->name }}</strong> · Baja: {{ rtrim(rtrim(number_format(abs((float) $movement->quantity), 3), '0'), '.') }} unidades · Existencia: {{ rtrim(rtrim(number_format((float) $movement->before_quantity, 3), '0'), '.') }} → {{ rtrim(rtrim(number_format((float) $movement->after_quantity, 3), '0'), '.') }} unidades</li>@endforeach</ul>
            </details>
        @empty
            <p>Aún no hay bajas por lote. Las bajas anteriores siguen disponibles en la bitácora.</p>
        @endforelse
    </section>
    <script>
        (() => {
            const form = document.querySelector('[data-supply-depletion-form]');
            const rows = Array.from(form.querySelectorAll('[data-supply-row]'));
            const rowById = new Map(rows.map(row => [row.dataset.id, row]));
            const selected = new Map();
            const dialog = form.querySelector('[data-supply-confirm]');
            const submit = form.querySelector('[data-supply-submit]');
            let editor = null;
            let mode = 'deplete';
            let submitting = false;
            const number = value => Number(value).toLocaleString('es-MX', {maximumFractionDigits: 3});
            const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
            const requested = () => Array.from(selected.values()).filter(item => item.requested_units > 0);
            const description = (item, request) => request ? `${number(item.requested_units)} ${item.requested_units === 1 ? 'envase' : 'envases'} a solicitar` : item.empty_stock ? `Toda la existencia (${number(rowById.get(String(item.balance_id)).dataset.packages)} envases)` : `${number(item.units)} ${item.units === 1 ? 'envase' : 'envases'} de baja`;
            const closeEditor = () => {
                if (!editor) return;
                editor.querySelector('[data-supply-editor]').hidden = true;
                editor.querySelectorAll('[data-supply-editor] input').forEach(input => { input.disabled = true; input.required = false; });
                editor = null;
                form.querySelector('[data-supply-edit-hint]').hidden = true;
            };
            const syncEditor = () => {
                const all = editor.querySelector('[data-supply-empty]').checked;
                const units = editor.querySelector('[data-supply-units]');
                units.disabled = all;
                units.required = !all;
                const replenishing = mode === 'request';
                editor.querySelector('[data-supply-request-label]').hidden = !replenishing;
                editor.querySelector('[data-supply-request-help]').hidden = !replenishing;
                const quantity = editor.querySelector('[data-supply-requested]');
                quantity.disabled = !replenishing;
                quantity.required = replenishing;
                editor.querySelectorAll('[data-supply-action]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.supplyAction === mode)));
            };
            const openEditor = (row, action) => {
                if (editor && editor !== row) {
                    form.querySelector('[data-supply-edit-hint]').hidden = false;
                    editor.scrollIntoView({block: 'center', behavior: 'smooth'});
                    return;
                }
                if (!editor) {
                    editor = row;
                    const item = selected.get(row.dataset.id);
                    row.querySelector('[data-supply-units]').value = item?.units || 1;
                    row.querySelector('[data-supply-empty]').checked = item?.empty_stock || false;
                    row.querySelector('[data-supply-requested]').value = item?.requested_units || 1;
                    row.querySelector('[data-supply-empty]').disabled = false;
                    row.querySelector('[data-supply-add]').textContent = item ? 'Actualizar listado' : 'Agregar al listado';
                    row.querySelector('[data-supply-editor]').hidden = false;
                }
                mode = action;
                syncEditor();
            };
            const renderList = (list, items, request, editable = false) => {
                list.replaceChildren();
                items.forEach(item => {
                    const row = rowById.get(String(item.balance_id));
                    const li = document.createElement('li');
                    const name = document.createElement('strong');
                    name.textContent = row.dataset.name;
                    const detail = document.createElement('small');
                    detail.textContent = description(item, request);
                    li.append(name, detail);
                    if (editable) {
                        const actions = document.createElement('div');
                        [['Editar', () => {
                            form.querySelector('[data-supply-search]').value = '';
                            rows.forEach(candidate => { candidate.hidden = false; });
                            form.querySelector('[data-supply-no-results]').hidden = true;
                            openEditor(row, item.requested_units > 0 ? 'request' : 'deplete');
                            if (editor === row) row.scrollIntoView({block: 'center', behavior: 'smooth'});
                        }], ['Quitar', () => {
                            if (editor) { form.querySelector('[data-supply-edit-hint]').hidden = false; return; }
                            if (request) item.requested_units = 0;
                            else selected.delete(row.dataset.id);
                            update();
                        }]].forEach(([label, callback]) => {
                            const button = document.createElement('button');
                            button.type = 'button'; button.textContent = label;
                            button.setAttribute('aria-label', `${label} ${request ? 'solicitud' : 'baja'} de ${row.dataset.name}`);
                            button.addEventListener('click', callback); actions.append(button);
                        });
                        li.append(actions);
                    }
                    list.append(li);
                });
            };
            const update = () => {
                rows.forEach(row => {
                    const item = selected.get(row.dataset.id);
                    row.classList.toggle('is-selected', Boolean(item));
                    row.querySelector('[data-supply-badge]').textContent = item ? (item.requested_units > 0 ? ' · Baja y solicitud en listado' : ' · Baja en listado') : '';
                    if (editor !== row) row.querySelectorAll('[data-supply-action]').forEach(button => button.setAttribute('aria-pressed', String(Boolean(item) && button.dataset.supplyAction === (item.requested_units > 0 ? 'request' : 'deplete'))));
                });
                const items = Array.from(selected.values());
                const requests = requested();
                form.querySelector('[data-supply-count]').textContent = items.length;
                form.querySelector('[data-supply-request-count]').textContent = requests.length;
                form.querySelector('[data-supply-empty-list]').hidden = items.length > 0;
                form.querySelector('[data-supply-empty-request]').hidden = requests.length > 0;
                renderList(form.querySelector('[data-supply-summary]'), items, false, true);
                renderList(form.querySelector('[data-supply-request-summary]'), requests, true, true);
                submit.disabled = items.length === 0;
                submit.textContent = requests.length ? 'Revisar bajas y solicitud' : 'Revisar bajas';
                const payload = form.querySelector('[data-supply-payload]');
                payload.replaceChildren();
                items.forEach((item, index) => Object.entries(item).forEach(([key, value]) => {
                    const input = document.createElement('input');
                    input.type = 'hidden'; input.name = `items[${index}][${key}]`; input.value = typeof value === 'boolean' ? (value ? '1' : '0') : value;
                    payload.append(input);
                }));
            };
            rows.forEach(row => {
                row.querySelectorAll('[data-supply-action]').forEach(button => button.addEventListener('click', () => openEditor(row, button.dataset.supplyAction)));
                row.querySelector('[data-supply-empty]').addEventListener('change', syncEditor);
                row.querySelector('[data-supply-edit-cancel]').addEventListener('click', () => { closeEditor(); update(); });
                row.querySelector('[data-supply-add]').addEventListener('click', () => {
                    if (!Array.from(row.querySelectorAll('[data-supply-editor] input')).every(input => input.disabled || input.reportValidity())) return;
                    if (!selected.has(row.dataset.id) && selected.size >= 100) { alert('Puedes agregar hasta 100 insumos en un lote.'); return; }
                    selected.set(row.dataset.id, {
                        balance_id: Number(row.dataset.id), expected_quantity: Number(row.dataset.expected),
                        units: row.querySelector('[data-supply-empty]').checked ? 0 : Number(row.querySelector('[data-supply-units]').value),
                        empty_stock: row.querySelector('[data-supply-empty]').checked,
                        requested_units: mode === 'request' ? Number(row.querySelector('[data-supply-requested]').value) : 0,
                    });
                    closeEditor(); update();
                });
            });
            form.querySelector('[data-supply-search]').addEventListener('input', event => {
                const terms = normalize(event.target.value).trim().split(/\s+/).filter(Boolean);
                rows.forEach(row => { row.hidden = row !== editor && !terms.every(term => normalize(row.dataset.search).includes(term)); });
                form.querySelector('[data-supply-no-results]').hidden = rows.length === 0 || rows.some(row => !row.hidden);
            });
            form.addEventListener('submit', event => {
                if (submitting) return;
                event.preventDefault();
                if (editor) { form.querySelector('[data-supply-edit-hint]').hidden = false; editor.scrollIntoView({block: 'center'}); return; }
                if (!selected.size || !form.reportValidity()) return;
                renderList(form.querySelector('[data-supply-confirm-list]'), Array.from(selected.values()), false);
                renderList(form.querySelector('[data-supply-confirm-request-list]'), requested(), true);
                form.querySelector('[data-supply-confirm-request-section]').hidden = requested().length === 0;
                form.querySelector('[data-supply-confirm-save]').textContent = requested().length ? 'Confirmar bajas y enviar solicitud' : 'Confirmar bajas';
                dialog.showModal();
            });
            form.querySelector('[data-supply-cancel]').addEventListener('click', () => dialog.close());
            form.querySelector('[data-supply-confirm-save]').addEventListener('click', event => {
                if (!form.reportValidity() || submitting || editor || !selected.size) return;
                submitting = true; event.target.disabled = true; submit.disabled = true; form.requestSubmit();
            });
            window.addEventListener('beforeunload', event => {
                if (!submitting && (selected.size || editor || form.elements.notes.value.trim())) { event.preventDefault(); event.returnValue = ''; }
            });
            Object.values(@js(old('items', []))).forEach(item => {
                const row = rowById.get(String(item.balance_id));
                if (!row) return;
                selected.set(row.dataset.id, {balance_id: Number(row.dataset.id), expected_quantity: Number(row.dataset.expected), units: Number(item.units || 0), empty_stock: item.empty_stock === true || String(item.empty_stock) === '1', requested_units: Number(item.requested_units || 0)});
            });
            update();
        })();
    </script>
</x-layouts.app>
