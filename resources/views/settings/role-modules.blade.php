<x-layouts.app title="Accesos por rol">
    <div id="role-module-settings">
        <section class="page-title"><div><p class="eyebrow">CONFIGURACIÓN</p><h1>Accesos por rol</h1><p>Elige qué módulos puede usar cada rol del equipo.</p></div></section>
        <nav class="role-settings-tabs" aria-label="Secciones de configuración"><a href="{{ route('settings.edit') }}">Configuración general</a><a href="{{ route('settings.role-modules.index', ['role' => $selectedRole->id]) }}" aria-current="page">Accesos por rol</a></nav>
        @if($errors->any())
            <div class="role-access-error" role="alert"><strong>No se guardaron los cambios.</strong>@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach @if($errors->has('revision'))<a href="{{ route('settings.role-modules.index', ['role' => $selectedRole->id]) }}">Recargar accesos actuales</a>@endif</div>
        @endif
        <section class="surface role-access-surface">
            <aside class="role-access-sidebar"><p class="eyebrow">ROL DEL EQUIPO</p><nav aria-label="Seleccionar rol">
                @foreach($roles as $role)
                    <a href="{{ route('settings.role-modules.index', ['role' => $role->id]) }}" class="role-access-picker {{ $selectedRole->is($role) ? 'is-selected' : '' }}" @if($selectedRole->is($role)) aria-current="page" @endif data-role-navigation><span><strong>{{ $role->name }}</strong><small>{{ $role->users_count }} {{ $role->users_count === 1 ? 'persona' : 'personas' }}</small></span>@if($role->name === 'Administrador')<span class="role-access-lock" aria-label="Rol protegido">Protegido</span>@else<span aria-hidden="true">›</span>@endif</a>
                @endforeach
            </nav></aside>
            <form method="POST" action="{{ route('settings.role-modules.update', $selectedRole) }}" class="role-access-editor" data-role-access-form>
                @csrf @method('PUT')
                <input type="hidden" name="revision" value="{{ old('revision', $revision) }}">
                <header class="role-access-heading"><div><h2>{{ $selectedRole->name }}</h2><p>Los cambios aplican a todas las personas con este rol.</p></div><span data-module-count>{{ count($selectedModules) }} de {{ count($modules) }} módulos</span></header>
                <fieldset class="role-module-grid" @disabled($isProtected)><legend class="sr-only">Módulos disponibles para {{ $selectedRole->name }}</legend>
                    @foreach($modules as $key => $module)
                        <label class="role-module-option"><span class="role-module-icon" aria-hidden="true">{{ $module['icon'] }}</span><span><strong>{{ $module['title'] }}</strong><small>{{ $module['description'] }}</small></span><input type="checkbox" name="modules[]" value="{{ $key }}" @checked(in_array($key, old('modules', $selectedModules), true)) aria-label="{{ $module['title'] }} para {{ $selectedRole->name }}"></label>
                    @endforeach
                </fieldset>
                <div class="role-access-note"><span aria-hidden="true">{{ $isProtected ? '⌑' : 'ⓘ' }}</span><p>@if($isProtected)<strong>Administrador es un rol protegido.</strong> Conserva todos los módulos, Administración y Configuración. Sus accesos no se pueden desactivar aquí.@else Activar un módulo permite ver y usar sus funciones. Administración, Configuración y la autorización de cortes permanecen protegidas. Las personas con varios roles suman sus accesos.@endif</p></div>
                @unless($isProtected)
                    <footer class="role-access-footer"><p data-access-status aria-live="polite">Sin cambios pendientes</p><div><button type="reset" class="button button-secondary" data-access-discard>Descartar cambios</button><button type="submit" class="button button-primary" data-access-review>Revisar cambios</button></div></footer>
                @endunless
            </form>
        </section>
        @if($recentChanges->isNotEmpty())
            <section class="surface role-access-history"><header><p class="eyebrow">HISTORIAL</p><h2>Últimos cambios de {{ $selectedRole->name }}</h2></header>@foreach($recentChanges as $change)
                <article><div><strong>{{ $change->user?->name ?? 'Administración' }}</strong><time datetime="{{ $change->created_at->toIso8601String() }}">{{ $change->created_at->format('d/m/Y · H:i') }}</time></div><p>@php($added = collect($change->after['modules'] ?? [])->diff($change->before['modules'] ?? []))@php($removed = collect($change->before['modules'] ?? [])->diff($change->after['modules'] ?? []))@if($added->isNotEmpty())Activó: {{ $added->map(fn ($key) => $modules[$key]['title'] ?? $key)->implode(', ') }}.@endif @if($removed->isNotEmpty())Desactivó: {{ $removed->map(fn ($key) => $modules[$key]['title'] ?? $key)->implode(', ') }}.@endif</p></article>
            @endforeach</section>
        @endif
        <dialog class="role-access-dialog" data-access-dialog aria-labelledby="role-access-review-title"><header><p class="eyebrow">REVISAR CAMBIOS</p><h2 id="role-access-review-title">Accesos de {{ $selectedRole->name }}</h2><p>Se actualizarán para todas las personas con este rol.</p></header><div class="role-access-review-content" data-access-summary></div><p class="role-access-preserve">Los tickets, fórmulas, pagos e inventarios se conservan.</p><footer><button type="button" class="button button-secondary" data-access-cancel>Seguir editando</button><button type="button" class="button button-primary" data-access-confirm>Guardar cambios</button></footer></dialog>
    </div>
    <style>
        #role-module-settings{max-width:1160px;margin:auto}.role-settings-tabs{display:flex;gap:24px;border-bottom:1px solid var(--line);margin-bottom:24px}.role-settings-tabs a{padding:0 0 13px;color:#738096;font-size:13px;text-decoration:none}.role-settings-tabs a[aria-current]{border-bottom:2px solid #b95070;color:#9e3e5c;font-weight:750}.role-access-surface{display:grid;grid-template-columns:215px minmax(0,1fr);padding:26px;gap:30px}.role-access-sidebar nav{display:grid;gap:7px}.role-access-picker{display:flex;align-items:center;justify-content:space-between;gap:12px;text-decoration:none;color:#69758a;padding:13px;border:1px solid transparent;border-radius:9px}.role-access-picker.is-selected{background:#fdf0f4;color:#9e3e5c;border-color:#efc7d4}.role-access-picker strong{display:block;font-size:13px}.role-access-picker small{display:block;font-size:11px;margin-top:4px;font-weight:400}.role-access-lock{font-size:10px;font-weight:700;background:#f2f4f8;padding:4px 6px;border-radius:5px}.role-access-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;margin-bottom:19px}.role-access-heading h2{font-size:19px;margin:0;color:#29364b}.role-access-heading p{color:#738096;font-size:12px;margin:6px 0 0}.role-access-heading>span{color:#738096;font-size:12px;white-space:nowrap;padding-top:4px}.role-module-grid{border:0;padding:0;margin:0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.role-module-option{display:grid;grid-template-columns:22px minmax(0,1fr) 19px;align-items:start;gap:12px;min-height:111px;padding:17px;border:1px solid #dfe5ee;border-radius:11px;cursor:pointer;background:#fff}.role-module-option:has(input:checked){border-color:#c67991;background:#fdf3f6}.role-module-option:has(input:focus-visible){outline:2px solid #9e3e5c;outline-offset:3px}.role-module-option strong{display:block;color:#344157;font-size:13px}.role-module-option small{display:block;color:#707d90;font-size:12px;line-height:1.55;margin-top:6px}.role-module-icon{font-size:20px;line-height:1.1;color:#9b6680}.role-module-option input{width:18px;height:18px;margin:0;accent-color:#b95070}.role-module-grid:disabled .role-module-option{cursor:default}.role-access-note{display:flex;align-items:flex-start;gap:9px;color:#738096;margin-top:16px}.role-access-note>span{font-size:19px;line-height:1.3}.role-access-note p{margin:0;font-size:12px;line-height:1.65}.role-access-note strong{font-weight:750}.role-access-footer{margin-top:23px;padding-top:20px;border-top:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:15px;flex-wrap:wrap}.role-access-footer p{margin:0;color:#738096;font-size:12px}.role-access-footer>div{display:flex;gap:9px;flex-wrap:wrap}.role-access-footer button:disabled{opacity:.5;cursor:default}.role-access-error{padding:16px;border:1px solid #efb4bd;background:#fff4f5;border-radius:10px;color:#a13952;margin-bottom:20px;font-size:13px}.role-access-error p{margin:6px 0}.role-access-error a{color:inherit;text-decoration:underline}.role-access-history{margin-top:20px;padding:24px}.role-access-history h2{font-size:16px;margin:0 0 16px}.role-access-history article{padding:13px 0;border-top:1px solid var(--line);font-size:12px}.role-access-history article>div{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap}.role-access-history time,.role-access-history p{color:#738096}.role-access-history p{margin:7px 0 0;line-height:1.6}.role-access-dialog{width:min(530px,calc(100vw - 32px));padding:0;border:1px solid #e4e8ef;border-radius:15px;color:#29364b;box-shadow:0 24px 70px #17203035}.role-access-dialog::backdrop{background:#17203066}.role-access-dialog header{padding:25px 25px 15px}.role-access-dialog h2{font-size:21px;margin:0}.role-access-dialog header>p:last-child{font-size:13px;line-height:1.6;color:#738096;margin:9px 0 0}.role-access-review-content{padding:0 25px;font-size:13px;line-height:1.7}.role-access-review-content h3{font-size:12px;color:#738096;margin:15px 0 5px}.role-access-review-content ul{padding-left:19px;margin:0}.role-access-preserve{padding:13px 25px;color:#738096;font-size:12px;line-height:1.6}.role-access-dialog footer{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;border-top:1px solid var(--line);padding:18px 25px}
        @media(max-width:750px){.role-access-surface{grid-template-columns:1fr;padding:19px;gap:22px}.role-access-sidebar nav{display:flex;flex-wrap:wrap;gap:6px}.role-access-picker{padding:9px 12px;gap:10px}.role-access-heading{flex-wrap:wrap}.role-module-grid{grid-template-columns:1fr}.role-module-option{min-height:98px}.role-access-footer{align-items:stretch}.role-access-footer>div{width:100%}.role-access-footer button{flex:1}.role-access-dialog footer button{flex:1}.role-settings-tabs{gap:18px}}
    </style>
    @unless($isProtected)
        <script>
            (() => {
                const root = document.getElementById('role-module-settings');
                const form = root.querySelector('[data-role-access-form]');
                const initial = @js($selectedModules);
                const titles = @js(collect($modules)->mapWithKeys(fn ($module, $key) => [$key => $module['title']])->all());
                const boxes = [...form.querySelectorAll('input[name="modules[]"]')];
                const reviewButton = root.querySelector('[data-access-review]');
                const discardButton = root.querySelector('[data-access-discard]');
                const status = root.querySelector('[data-access-status]');
                const dialog = root.querySelector('[data-access-dialog]');
                const selected = () => boxes.filter(input => input.checked).map(input => input.value);
                const changes = () => ({added: selected().filter(key => !initial.includes(key)), removed: initial.filter(key => !selected().includes(key))});
                const isDirty = () => changes().added.length + changes().removed.length > 0;
                let submitting = false;
                function update() {
                    const count = selected().length;
                    root.querySelector('[data-module-count]').textContent = `${count} de ${boxes.length} módulos`;
                    status.textContent = count === 0 ? 'Selecciona al menos un módulo.' : (isDirty() ? 'Cambios pendientes de guardar' : 'Sin cambios pendientes');
                    reviewButton.disabled = !isDirty() || count === 0;
                    discardButton.disabled = !isDirty();
                }
                boxes.forEach(input => input.addEventListener('change', update));
                form.addEventListener('reset', event => {event.preventDefault(); boxes.forEach(input => input.checked = initial.includes(input.value)); update();});
                form.addEventListener('submit', event => {
                    if (submitting) {return;}
                    event.preventDefault();
                    if (!isDirty() || selected().length === 0) {return;}
                    const summary = root.querySelector('[data-access-summary]');
                    summary.replaceChildren();
                    const delta = changes();
                    [['Se activarán', delta.added], ['Se desactivarán', delta.removed]].forEach(([title, keys]) => {
                        if (keys.length === 0) {return;}
                        const heading = document.createElement('h3'); heading.textContent = title;
                        const list = document.createElement('ul');
                        keys.forEach(key => {const item = document.createElement('li'); item.textContent = titles[key]; list.append(item);});
                        summary.append(heading, list);
                    });
                    dialog.showModal();
                });
                root.querySelector('[data-access-cancel]').addEventListener('click', () => dialog.close());
                root.querySelector('[data-access-confirm]').addEventListener('click', event => {submitting = true; event.currentTarget.disabled = true; event.currentTarget.textContent = 'Guardando…'; form.requestSubmit();});
                root.querySelectorAll('[data-role-navigation], .role-settings-tabs a').forEach(link => link.addEventListener('click', event => {if (isDirty() && !window.confirm('Tienes cambios sin guardar. ¿Salir y descartarlos?')) {event.preventDefault();}}));
                window.addEventListener('beforeunload', event => {if (isDirty() && !submitting) {event.preventDefault(); event.returnValue = '';}});
                update();
            })();
        </script>
    @endunless
</x-layouts.app>
