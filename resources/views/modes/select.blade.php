<x-layouts.guest>
    <section class="mode-page">
        <header class="mode-header"><a class="brand-lockup" href="{{ route('modes.select') }}"><x-brand-logo /></a><div class="account-menu"><div class="avatar">{{ str(auth()->user()->name)->substr(0, 1) }}</div><div><strong>{{ auth()->user()->name }}</strong><span>{{ auth()->user()->getRoleNames()->first() }}</span></div><form method="POST" action="{{ route('logout') }}">@csrf<button class="text-button" type="submit">Salir</button></form></div></header>
        <div class="mode-intro"><p class="eyebrow">CREPÉ · OPERACIÓN</p><h1>¿Dónde quieres trabajar?</h1><p>Elige un modo. Siempre podrás cambiarlo sin cerrar sesión.</p></div>
        @php($details = [
            'administracion' => ['key' => '01', 'description' => 'Dashboard, clientas, equipo, servicios y reportes.', 'icon' => '↗'],
            'recepcion' => ['key' => '02', 'description' => 'Citas, tickets, clientas, cobros e inventario de Recepción.', 'icon' => '◇'],
            'color-bar' => ['key' => '03', 'description' => 'Tickets, fórmulas e inventario exclusivo de Color Bar.', 'icon' => '✦'],
            'finanzas' => ['key' => '04', 'description' => 'Ingresos, gastos, cortes y cuentas de CREPÉ.', 'icon' => '$'],
            'almacen' => ['key' => '05', 'description' => 'Productos, existencias, movimientos y compras.', 'icon' => '▦'],
            'promos' => ['key' => '06', 'description' => 'Descuentos y promociones para aplicar en los tickets.', 'icon' => '%'],
            'configuracion' => ['key' => '07', 'description' => 'Valores generales, cajas y reglas del sistema.', 'icon' => '⚙'],
            'checks' => ['key' => '08', 'description' => 'Entradas, salidas e incidencias del equipo.', 'icon' => '✓'],
        ])
        <div class="mode-grid">@foreach($modes as $key => $mode) @continue(!isset($details[$key]))<form method="POST" action="{{ route('modes.store') }}">@csrf<input type="hidden" name="mode" value="{{ $key }}"><button class="mode-card" type="submit"><span class="mode-number">{{ $details[$key]['key'] }}</span><span class="mode-icon">{{ $details[$key]['icon'] }}</span><span class="mode-card-copy"><strong>{{ $mode['title'] }}</strong><small>{{ $details[$key]['description'] }}</small></span><span class="mode-arrow">→</span></button></form>@endforeach</div>
    </section>
</x-layouts.guest>
