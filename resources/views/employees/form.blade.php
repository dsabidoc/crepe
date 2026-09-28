<x-layouts.app :title="$employee->exists ? 'Editar colaboradora' : 'Nueva colaboradora'">
    <section class="page-title"><div><p class="eyebrow">EQUIPO</p><h1>{{ $employee->exists ? 'Editar colaboradora' : 'Nueva colaboradora' }}</h1><p>Información operativa, disponibilidad y comisión.</p></div><a class="button button-secondary" href="{{ route('employees.index') }}">Cancelar</a></section>
    <form class="surface detail-form" method="POST" action="{{ $employee->exists ? route('employees.update', $employee) : route('employees.store') }}">@csrf @if($employee->exists) @method('PUT') @endif
        <div class="form-section"><h2>Información general</h2><div class="field-grid"><label><span>Nombre</span><input name="first_name" value="{{ old('first_name', $employee->first_name) }}" required></label><label><span>Apellidos <small>Opcional</small></span><input name="last_name" value="{{ old('last_name', $employee->last_name) }}"></label><label><span>Correo</span><input type="email" name="email" value="{{ old('email', $employee->email) }}"></label><label><span>Teléfono</span><input name="phone" value="{{ old('phone', $employee->phone) }}"></label><label><span>Puesto</span><input name="position" value="{{ old('position', $employee->position) }}" placeholder="Estilista, Colorista…" required></label><label><span>Fecha de ingreso</span><input type="date" name="hired_at" value="{{ old('hired_at', optional($employee->hired_at)->format('Y-m-d')) }}"></label><label><span>Estatus</span><select name="status"><option value="active" @selected(old('status', $employee->status ?: 'active') === 'active')>Activa</option><option value="inactive" @selected(old('status', $employee->status) === 'inactive')>Inactiva</option></select></label></div></div>
        <div class="form-section"><h2>Disponibilidad y compensación</h2><div class="field-grid"><label class="switch-field"><input type="checkbox" name="is_bookable" value="1" @checked(old('is_bookable', $employee->is_bookable))><span>Disponible para recibir citas</span></label><label><span>Comisión por servicio (%)</span><input type="number" step="0.01" min="0" max="100" name="commission_rate" value="{{ old('commission_rate', $employee->commission_rate) }}"></label><label><span>Comisión por producto (%)</span><input type="number" step="0.01" min="0" max="100" name="product_commission_rate" value="{{ old('product_commission_rate', $employee->product_commission_rate) }}"></label><label><span>Sueldo</span><input type="number" step="0.01" min="0" name="salary" value="{{ old('salary', $employee->salary) }}"></label><label><span>Tipo de sueldo</span><select name="salary_type"><option value="">Sin definir</option><option value="weekly" @selected(old('salary_type', $employee->salary_type) === 'weekly')>Semanal</option><option value="monthly" @selected(old('salary_type', $employee->salary_type) === 'monthly')>Mensual</option></select></label></div></div>
        @php($systemAccessEnabled = old('system_access_enabled', $employee->user?->is_active ?? false))
        <section class="form-section system-access-section">
            <div class="system-access-heading"><div><p class="eyebrow">Acceso a sistema</p><h2>Cuenta de colaboradora</h2><p>Activa un usuario sólo para las personas que deban entrar a CREPÉ.</p></div><span class="system-access-status {{ $systemAccessEnabled ? 'is-active' : '' }}" id="system-access-status">{{ $systemAccessEnabled ? 'Acceso activo' : 'Sin acceso' }}</span></div>
            <label class="switch-field system-access-toggle"><input type="checkbox" id="system-access-enabled" name="system_access_enabled" value="1" @checked($systemAccessEnabled)><span>Puede entrar al sistema</span></label>
            <div class="field-grid system-access-fields" id="system-access-fields" @hidden(! $systemAccessEnabled)>
                <label><span>Usuario (correo)</span><input type="email" name="system_email" value="{{ old('system_email', $employee->user?->email ?? $employee->email) }}" autocomplete="username"></label>
                <label><span>Rol de acceso</span><select name="system_role">@foreach($roles as $role)<option value="{{ $role }}" @selected(old('system_role', $employee->user?->getRoleNames()->first() ?? 'Recepción') === $role)>{{ $role }}</option>@endforeach</select></label>
                <label><span>{{ $employee->user ? 'Nueva contraseña' : 'Contraseña' }}</span><input type="password" name="system_password" minlength="8" autocomplete="new-password"><small>{{ $employee->user ? 'Déjala vacía para conservar la actual.' : 'Mínimo 8 caracteres.' }}</small></label>
                <label><span>Confirmar contraseña</span><input type="password" name="system_password_confirmation" minlength="8" autocomplete="new-password"></label>
            </div>
            @error('system_email')<small class="form-error">{{ $message }}</small>@enderror
            @error('system_role')<small class="form-error">{{ $message }}</small>@enderror
            @error('system_password')<small class="form-error">{{ $message }}</small>@enderror
        </section>
        <div class="form-section"><label class="full-field"><span>Notas</span><textarea name="notes" rows="4">{{ old('notes', $employee->notes) }}</textarea></label></div><footer class="form-footer">@if($employee->exists)<button class="button button-secondary" type="submit" form="deactivate-employee">Desactivar</button>@endif<a href="{{ route('employees.index') }}">Cancelar</a><button class="button button-primary" type="submit">Guardar colaboradora</button></footer>
    </form>
    @if($employee->exists)<form id="deactivate-employee" method="POST" action="{{ route('employees.destroy', $employee) }}" onsubmit="return confirm('¿Desactivar a esta colaboradora? Se conservará su historial.')">@csrf @method('DELETE')</form>@endif
    <script>
        (() => {
            const accessEnabled = document.getElementById('system-access-enabled');
            const accessFields = document.getElementById('system-access-fields');
            const accessStatus = document.getElementById('system-access-status');
            const updateAccessFields = () => {
                accessFields.hidden = !accessEnabled.checked;
                accessStatus.classList.toggle('is-active', accessEnabled.checked);
                accessStatus.textContent = accessEnabled.checked ? 'Acceso activo' : 'Sin acceso';
            };
            accessEnabled.addEventListener('change', updateAccessFields);
            updateAccessFields();
        })();
    </script>
</x-layouts.app>
