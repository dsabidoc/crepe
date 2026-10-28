<style>
.inventory-register{overflow:auto;max-height:calc(100vh - 250px)}.inventory-register table{border-collapse:separate;border-spacing:0;width:100%;min-width:1120px;text-align:left}.inventory-register th{position:sticky;top:0;z-index:2;background:#f6f7f8;color:#637083;font-size:11px;font-weight:600;padding:11px 12px;white-space:nowrap;border-bottom:1px solid #e5e7eb}.inventory-register td{padding:10px 12px;border-bottom:1px solid #eceef1;vertical-align:middle;font-size:13px}.inventory-register tbody tr:hover{background:#fafbfc}.inventory-register small{display:block;font-size:11px;color:#718096;margin-top:3px}.inventory-register .product-identity{display:flex;align-items:center;gap:10px;min-width:220px;max-width:340px}.inventory-register .product-thumb{width:36px;height:40px;border-radius:6px;object-fit:contain;background:#f5f5f6;flex-shrink:0}.product-thumb-placeholder{display:grid;place-items:center;color:#9aa5b1}.inventory-register .stock-number{white-space:nowrap;font-variant-numeric:tabular-nums}.inventory-register .stock-total{font-weight:700;background:#fcf7f9}.inventory-register .stock-low{color:#a2395b}.inventory-register a{color:inherit}.inline-cell{display:inline-flex;align-items:center;min-height:32px}.inline-cell-trigger{border:1px solid transparent;border-radius:5px;background:none;color:inherit;cursor:text;padding:5px 6px;font:inherit;min-width:42px;text-align:left}.inline-cell-trigger:hover,.inline-cell-trigger:focus-visible{border-color:#cbd5e1;background:white;outline:2px solid transparent}.inline-cell-input{width:100px!important;min-height:32px!important;padding:5px 7px!important;font:inherit!important;border:1px solid #bcc6d2!important;border-radius:5px!important;background:white!important}.inline-cell.is-dirty{background:#fff8e7;border-radius:5px}.inline-cell.is-dirty .inline-cell-input{border-color:#d6aa52!important;background:#fffaf0!important}[data-inline-root] [hidden],.inventory-save-panel[hidden]{display:none!important}.inventory-save-panel{position:fixed;bottom:22px;right:22px;z-index:60;width:390px;max-width:calc(100vw - 32px);background:white;border:1px solid #dce1e7;border-radius:12px;box-shadow:0 10px 35px #1b253825;padding:16px}.inventory-save-panel h2{font-size:15px;margin:0 0 5px}.inventory-save-panel p{font-size:12px;color:#637083;margin:5px 0 12px}.inventory-save-panel footer{display:flex;gap:9px;justify-content:flex-end;margin-top:14px}.inventory-save-panel .button{font-size:12px;padding:9px 13px;min-height:36px}.inventory-save-panel input{width:100%;padding:9px;min-height:36px}.inventory-save-panel label,.inventory-save-panel summary{font-size:12px}.inventory-save-panel ul{max-height:150px;overflow:auto;padding-left:18px;font-size:12px}.inventory-save-panel .inline-error{color:#a52647;white-space:pre-line}.inventory-save-status{padding:8px 18px;font-size:13px;color:#376344}.location-breakdown{position:relative}.location-breakdown summary{cursor:pointer;white-space:nowrap}.location-breakdown>div{position:fixed;inset:auto;margin:0;z-index:5;background:white;border:1px solid #e0e5eb;box-shadow:0 6px 20px #2430471a;border-radius:8px;min-width:180px;padding:12px}.location-breakdown p{display:flex;justify-content:space-between;gap:20px;font-size:12px;margin:7px 0}.inventory-register .catalog-status{font-size:11px;padding:3px 6px;background:#f0f7f2;border-radius:4px}.inventory-register .catalog-status.inactive{background:#f3f3f3;color:#718096}
</style>
<div class="inventory-save-panel" hidden data-save-panel role="region" aria-label="Cambios pendientes">
<h2 data-save-count>Cambios pendientes</h2><p>Guarda todos los cambios o descártalos.</p>
<details data-stock-review hidden><summary>Revisar ajustes de existencias</summary><ul data-stock-differences></ul></details>
<label data-stock-reason hidden><span>Motivo del ajuste de existencias</span><input data-edit-reason maxlength="255" minlength="5" placeholder="Ej. Conteo físico de cierre"></label>
<p class="inline-error" data-save-error role="alert" hidden></p>
<footer><button type="button" class="button button-secondary" data-discard-edits>Descartar</button><button type="button" class="button button-primary" data-save-edits>Guardar cambios</button></footer>
</div>
<script>
(() => {
    const root = document.querySelector('[data-inline-root]');
    const panel = document.querySelector('[data-save-panel]');
    if (!root || !panel) return;
    root.querySelectorAll('[data-location-popover]').forEach(popover => popover.addEventListener('toggle', event => {
        if (event.newState !== 'open') return;
        const anchor = popover.previousElementSibling.getBoundingClientRect();
        const height = popover.getBoundingClientRect().height;
        popover.style.left = Math.max(12, Math.min(anchor.left, window.innerWidth - popover.offsetWidth - 12)) + 'px';
        popover.style.top = (anchor.bottom + height + 16 > window.innerHeight ? Math.max(12, anchor.top - height - 8) : anchor.bottom + 8) + 'px';
    }));
    const inputs = [...root.querySelectorAll('[data-inline-value]')];
    const reason = panel.querySelector('[data-edit-reason]');
    const error = panel.querySelector('[data-save-error]');
    const save = panel.querySelector('[data-save-edits]');
    const discard = panel.querySelector('[data-discard-edits]');
    const status = document.querySelector('[data-inline-status]');
    let saving = false;
    let allowLeave = false;
    let requestKey = crypto.randomUUID();
    const normalized = (input, value) => value === '' ? (input.dataset.field === 'maximum_stock' ? null : '') : Number(value);
    const dirty = () => inputs.filter(input => normalized(input, input.value) !== normalized(input, input.dataset.original));
    const update = () => {
        const changed = dirty();
        inputs.forEach(input => input.closest('.inline-cell').classList.toggle('is-dirty', changed.includes(input)));
        panel.hidden = changed.length === 0;
        const count = new Set(changed.map(input => input.dataset.variant)).size;
        panel.querySelector('[data-save-count]').textContent = count + (count === 1 ? ' producto con cambios' : ' productos con cambios');
        const quantities = changed.filter(input => input.dataset.field === 'quantity');
        panel.querySelector('[data-stock-review]').hidden = quantities.length === 0;
        panel.querySelector('[data-stock-reason]').hidden = quantities.length === 0;
        const differences = panel.querySelector('[data-stock-differences]');
        differences.replaceChildren();
        quantities.forEach(input => {const li = document.createElement('li');li.textContent = input.dataset.label + ': ' + input.dataset.original + ' → ' + (input.value || '—');differences.append(li);});
        root.querySelectorAll('[data-stock-row]').forEach(row => {
            const cells = [...row.querySelectorAll('[data-stock-location]')];
            const total = cells.reduce((sum, cell) => sum + Number(cell.querySelector('[data-inline-value]')?.value ?? cell.dataset.quantity), 0);
            const target = row.querySelector('[data-stock-total]');
            if (target) target.textContent = total.toLocaleString('es-MX', {maximumFractionDigits:0});
        });
    };
    root.addEventListener('click', event => {
        const button = event.target.closest('[data-edit-cell]');
        if (!button || saving) return;
        const input = button.nextElementSibling;button.hidden = true;input.hidden = false;input.focus();input.select();
    });
    inputs.forEach(input => {
        input.addEventListener('input', () => {requestKey = crypto.randomUUID();error.hidden = true;update();});
        input.addEventListener('blur', () => {if (!dirty().includes(input)) {input.hidden = true;input.previousElementSibling.hidden = false;}});
        input.addEventListener('keydown', event => {if (event.key === 'Enter') {event.preventDefault();input.blur();} if (event.key === 'Escape') {input.value = input.dataset.original;input.blur();update();}});
    });
    reason.addEventListener('input', () => {requestKey = crypto.randomUUID();});
    discard.addEventListener('click', () => {
        if (saving) return;
        inputs.forEach(input => {input.value = input.dataset.original;input.hidden = true;input.previousElementSibling.hidden = false;});
        reason.value = '';error.hidden = true;requestKey = crypto.randomUUID();update();
    });
    save.addEventListener('click', async () => {
        if (saving) return;
        const changed = dirty();
        if (!changed.length) return;
        for (const input of changed) {if (!input.checkValidity()) {input.hidden = false;input.previousElementSibling.hidden = true;input.reportValidity();return;}}
        if (changed.some(input => input.dataset.field === 'quantity') && reason.value.trim().length < 5) {error.textContent = 'Escribe un motivo de al menos 5 caracteres para ajustar existencias.';error.hidden = false;reason.focus();return;}
        saving = true;save.disabled = true;discard.disabled = true;save.textContent = 'Guardando…';inputs.forEach(input => input.disabled = true);reason.disabled = true;
        const changes = changed.map(input => ({variant_id:Number(input.dataset.variant),field:input.dataset.field,location_code:input.dataset.location || null,before:input.dataset.before === '' ? null : input.dataset.before,value:input.value === '' ? null : input.value}));
        try {
            const response = await fetch(@js(route('inventory.inline.store')), {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':root.querySelector('[name="_token"]').value},body:JSON.stringify({key:requestKey,changes,reason:reason.value.trim() || null})});
            const result = await response.json();
            if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join('\n') : result.message || 'No se pudo guardar. Tus cambios siguen pendientes.');
            changed.forEach(input => {
                input.dataset.original = input.value;input.dataset.before = input.value;
                const money = ['cost','sale_price'].includes(input.dataset.field);
                input.previousElementSibling.querySelector('strong').textContent = input.value === '' ? 'Sin máximo' : (money ? '$' : '') + Number(input.value).toLocaleString('es-MX',{minimumFractionDigits:money ? 2 : 0,maximumFractionDigits:money ? 2 : 0});
                input.hidden = true;input.previousElementSibling.hidden = false;
            });
            reason.value = '';error.hidden = true;requestKey = crypto.randomUUID();
            status.textContent = 'Cambios guardados.';status.hidden = false;update();
        } catch (failure) {error.textContent = failure.message || 'No se pudo confirmar el guardado. Puedes volver a intentar sin duplicar ajustes.';error.hidden = false;}
        finally {saving = false;save.disabled = false;discard.disabled = false;save.textContent = 'Guardar cambios';inputs.forEach(input => input.disabled = false);reason.disabled = false;}
    });
    window.addEventListener('beforeunload', event => {if ((dirty().length || saving) && !allowLeave) {event.preventDefault();event.returnValue = '';}});
    const leave = event => {if (saving) {event.preventDefault();return;} if (dirty().length && !window.confirm('Tienes cambios sin guardar. ¿Quieres salir y descartarlos?')) {event.preventDefault();} else {allowLeave = true;}};
    document.addEventListener('click', event => {const link = event.target.closest('a[href]');if (link && !link.getAttribute('href').startsWith('#')) leave(event);}, true);
    document.querySelectorAll('form[method="GET"]').forEach(form => form.addEventListener('submit', leave));
})();
</script>
