<x-layouts.app title="Permisos de importaciones">
    <section class="page-title flex-wrap"><div><p class="eyebrow">ADMINISTRACIÓN · ACCESOS POR ROL</p><h1>Permisos de archivos e importaciones</h1><p>Controla las descargas, cargas y datos visibles en los archivos y el historial.</p></div><a class="button button-secondary" href="{{ route('imports.index') }}">Volver al historial</a></section>
    @if($errors->any())<section class="surface form-section" role="alert"><h2>No se guardaron los permisos</h2><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></section>@endif
    <section class="surface form-section"><p>Estos permisos se suman al acceso al módulo correspondiente. Confirmar un conteo requiere también permiso para mover inventario. Ver y cargar costos se controla aquí para los archivos y el historial; las fichas individuales mantienen sus permisos actuales.</p><p>Administrador conserva todos los permisos. Los cambios se aplican a todas las personas del rol y quedan en la bitácora.</p><a class="text-button" href="{{ route('settings.role-modules.index') }}">Revisar acceso a módulos</a></section>
    @foreach($roles as $role)
        <section class="surface form-section">
            <header><div><h2>{{ $role->name }}</h2><p>{{ $role->name === 'Administrador' ? 'Rol protegido: acceso completo.' : 'Selecciona las operaciones permitidas.' }}</p></div></header>
            <form method="POST" action="{{ route('imports.permissions.update', $role) }}">
                @csrf @method('PUT')<input type="hidden" name="revision" value="{{ $revisions[$role->id] }}">
                <fieldset class="grid gap-4 md:grid-cols-2" @disabled($role->name === 'Administrador')><legend class="sr-only">Permisos para {{ $role->name }}</legend>
                    @foreach($permissions as $permission => $label)<label class="flex items-center gap-3"><input type="checkbox" name="permissions[]" value="{{ $permission }}" @checked($role->name === 'Administrador' || $role->permissions->contains('name', $permission))><span>{{ $label }}</span></label>@endforeach
                </fieldset>
                @if($role->name !== 'Administrador')<footer><button class="button button-primary" type="submit">Guardar permisos de {{ $role->name }}</button></footer>@endif
            </form>
        </section>
    @endforeach
</x-layouts.app>
