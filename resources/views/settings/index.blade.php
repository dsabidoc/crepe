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
</x-layouts.app>
