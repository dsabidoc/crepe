<x-layouts.app title="Configuración">
    <section class="page-title"><div><p class="eyebrow">ADMINISTRACIÓN</p><h1>Configuración</h1><p>Define los valores generales que aplican a todas las cajas.</p></div></section>
    <section class="surface settings-card">
        <header><div><p class="eyebrow">CAJAS</p><h2>Monto de inicio de cajas</h2><p>Este fondo se sugiere al abrir una caja nueva cada día. La recepción puede ajustarlo si el efectivo real es distinto.</p></div></header>
        <form method="POST" action="{{ route('settings.update') }}" class="settings-form">
            @csrf @method('PUT')
            <label><span>Monto de inicio</span><small>En pesos mexicanos</small><input type="number" name="opening_float" min="0" step=".01" value="{{ number_format($openingFloat, 2, '.', '') }}" required></label>
            @error('opening_float')<p class="form-error">{{ $message }}</p>@enderror
            <footer><button class="button button-primary" type="submit">Guardar configuración</button></footer>
        </form>
    </section>
    <section class="surface settings-card">
        <header><div><p class="eyebrow">CHECKS</p><h2>Horario y retardos</h2><p>Aplican a quienes tienen activo el registro de entrada y salida.</p></div></header>
        <form method="POST" action="{{ route('settings.attendance.update') }}" class="settings-form">@csrf @method('PUT')
            <div class="field-grid"><label><span>Hora de entrada</span><input name="starts_at" type="time" value="{{ $attendanceStartsAt }}" required></label><label><span>Tolerancia (minutos)</span><input name="tolerance_minutes" type="number" min="0" max="120" value="{{ $attendanceToleranceMinutes }}" required></label><label><span>Hora de salida</span><input name="ends_at" type="time" value="{{ $attendanceEndsAt }}" required></label><label><span>Descuento por retardo</span><input name="tardiness_penalty" type="number" min="0" step=".01" value="{{ number_format($attendanceTardinessPenalty, 2, '.', '') }}" required></label></div>
            <footer><button class="button button-primary" type="submit">Guardar Checks</button></footer>
        </form>
    </section>
    <section class="surface settings-card settings-catalog-card">
        <header><div><p class="eyebrow">CATÁLOGOS</p><h2>Puestos del equipo</h2><p>Define los puestos disponibles al dar de alta o editar a una colaboradora.</p></div></header>
        <form method="POST" action="{{ route('settings.job-positions.store') }}" class="settings-catalog-form">
            @csrf
            <label><span>Nuevo puesto</span><input name="name" value="{{ old('name') }}" maxlength="100" placeholder="Ej. Técnica de uñas" required></label>
            <button class="button button-secondary" type="submit">Agregar puesto</button>
        </form>
        @error('name')<p class="form-error settings-catalog-error">{{ $message }}</p>@enderror
        <div class="settings-catalog-list" aria-label="Puestos disponibles">
            @foreach($jobPositions as $jobPosition)<span class="settings-catalog-chip">{{ $jobPosition->name }}</span>@endforeach
        </div>
    </section>
    <section class="surface settings-card settings-catalog-card settings-commission-card">
        <header><div><p class="eyebrow">COMISIONES</p><h2>Comisión por venta de productos</h2><p>Configura rangos de productos vendidos y los puestos a los que aplica la regla.</p></div></header>
        <form method="POST" action="{{ route('settings.product-commission-rules.store') }}" class="settings-commission-add-form">
            @csrf
            <label><span>Desde</span><input name="minimum_sales" type="number" min="0" value="{{ old('minimum_sales') }}" required></label>
            <label><span>Hasta <small>Opcional</small></span><input name="maximum_sales" type="number" min="0" value="{{ old('maximum_sales') }}"></label>
            <label><span>Comisión %</span><input name="commission_rate" type="number" min="0" max="100" step=".01" value="{{ old('commission_rate') }}" required></label>
            <label><span>Aplica a puestos</span><select name="positions[]" multiple required data-placeholder="Selecciona puestos">@foreach($jobPositions as $jobPosition)<option value="{{ $jobPosition->name }}" @selected(in_array($jobPosition->name, old('positions', []), true))>{{ $jobPosition->name }}</option>@endforeach</select></label>
            <button class="button button-primary" type="submit">Agregar rango</button>
        </form>
        @error('minimum_sales')<p class="form-error settings-catalog-error">{{ $message }}</p>@enderror
        @error('maximum_sales')<p class="form-error settings-catalog-error">{{ $message }}</p>@enderror
        @error('commission_rate')<p class="form-error settings-catalog-error">{{ $message }}</p>@enderror
        @error('positions')<p class="form-error settings-catalog-error">{{ $message }}</p>@enderror
        <div class="settings-commission-table" aria-label="Rangos de comisión por venta de productos">
            <div class="settings-commission-table-head" aria-hidden="true"><span>DESDE</span><span>HASTA</span><span>COMISIÓN</span><span>PUESTOS</span><span>ACCIONES</span></div>
            @forelse($productCommissionRules as $rule)
                <article class="settings-commission-table-row">
                    <form method="POST" action="{{ route('settings.product-commission-rules.update', $rule) }}" class="settings-commission-row">
                        @csrf @method('PUT')
                        <label><span class="sr-only">Desde</span><input name="minimum_sales" type="number" min="0" value="{{ $rule->minimum_sales }}" required></label>
                        <label><span class="sr-only">Hasta</span><input name="maximum_sales" type="number" min="0" value="{{ $rule->maximum_sales }}" placeholder="Sin límite"></label>
                        <label><span class="sr-only">Comisión</span><input name="commission_rate" type="number" min="0" max="100" step=".01" value="{{ $rule->commission_rate }}" required></label>
                        <label><span class="sr-only">Puestos</span><select name="positions[]" multiple required data-placeholder="Selecciona puestos">@foreach($jobPositions as $jobPosition)<option value="{{ $jobPosition->name }}" @selected(in_array($jobPosition->name, $rule->positions ?? [], true))>{{ $jobPosition->name }}</option>@endforeach</select></label>
                        <button class="button button-secondary" type="submit">Guardar</button>
                    </form>
                    <form method="POST" action="{{ route('settings.product-commission-rules.destroy', $rule) }}" class="settings-commission-delete" onsubmit="return confirm('¿Eliminar este rango de comisión?')">
                        @csrf @method('DELETE')
                        <button class="text-action-danger" type="submit">Eliminar</button>
                    </form>
                </article>
            @empty
                <p class="empty-state settings-commission-empty">Aún no hay rangos de comisión configurados.</p>
            @endforelse
        </div>
    </section>
</x-layouts.app>
