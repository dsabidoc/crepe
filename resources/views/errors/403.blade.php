<x-layouts.guest>
    <section class="error-page">
        <a class="brand-lockup" href="{{ route('modes.select') }}"><x-brand-logo /></a>
        <article class="error-card">
            <p class="eyebrow">ACCESO RESTRINGIDO</p>
            <span class="error-code">403</span>
            <h1>Esta área no está disponible para tu perfil.</h1>
            <p>{{ isset($exception) && $exception->getMessage() !== '' ? $exception->getMessage() : 'No tienes permiso para realizar esta acción desde el módulo actual.' }}</p>
            <a class="button button-primary" href="{{ route('modes.select') }}">Elegir otro módulo</a>
        </article>
    </section>
</x-layouts.guest>
