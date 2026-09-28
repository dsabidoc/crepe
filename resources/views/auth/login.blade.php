<x-layouts.guest>
    <section class="login-shell">
        <div class="login-visual" aria-hidden="true"><div class="visual-mark"><x-brand-logo /></div><div class="visual-copy"><span>Estética contemporánea</span><strong>Todo el salón,<br>conectado.</strong></div></div>
        <div class="login-panel">
            <div class="brand-lockup"><x-brand-logo /></div>
            <div class="form-heading"><p class="eyebrow">BIENVENIDA</p><h1>Ingresa a tu espacio</h1><p>Selecciona tu modo de trabajo después de iniciar sesión.</p></div>
            <form method="POST" action="{{ route('login.store') }}" class="auth-form">@csrf
                <label><span>Correo electrónico</span><input name="email" type="email" value="{{ old('email') }}" placeholder="tu@correo.com" required autofocus autocomplete="email">@error('email')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label><span>Contraseña</span><input name="password" type="password" placeholder="••••••••" required autocomplete="current-password"></label>
                <label class="check-row"><input type="checkbox" name="remember" value="1"><span>Recordarme en este equipo</span></label>
                <button class="button button-primary button-full" type="submit">Entrar al sistema <span>→</span></button>
            </form><p class="login-help">¿Necesitas acceso? Habla con Administración.</p>
        </div>
    </section>
</x-layouts.guest>
