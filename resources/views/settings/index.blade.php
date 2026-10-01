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
    <section class="surface settings-card settings-catalog-card"><header><div><p class="eyebrow">COMISIONES</p><h2>Comisión por venta de productos</h2><p>Configura rangos de productos vendidos y los puestos a los que aplica la regla.</p></div></header><form method="POST" action="{{ route('settings.product-commission-rules.store') }}" class="settings-catalog-form">@csrf<label><span>Desde</span><input name="minimum_sales" type="number" min="0" required></label><label><span>Hasta <small>Opcional</small></span><input name="maximum_sales" type="number" min="0"></label><label><span>Comisión %</span><input name="commission_rate" type="number" min="0" max="100" step=".01" required></label><label><span>Aplica a puestos</span><select name="positions[]" multiple required>@foreach($jobPositions as $jobPosition)<option value="{{ $jobPosition->name }}">{{ $jobPosition->name }}</option>@endforeach</select></label><button class="button button-secondary" type="submit">Agregar rango</button></form><div class="settings-catalog-list">@foreach($productCommissionRules as $rule)<span class="settings-catalog-chip">{{ $rule->minimum_sales }}–{{ $rule->maximum_sales ?? '∞' }} ventas · {{ $rule->commission_rate }}% · {{ implode(', ', $rule->positions ?? []) }}</span>@endforeach</div></section>
</x-layouts.app>
