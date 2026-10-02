<x-layouts.app title="Equipo">
    <section class="page-title">
        <div><p class="eyebrow">EQUIPO</p><h1>Colaboradoras</h1><p>Disponibilidad, perfil operativo y comisiones por tipo de venta.</p></div>
        <a class="button button-primary" href="{{ route('employees.create') }}">+ Agregar colaboradora</a>
    </section>

    <section class="team-overview" aria-label="Resumen del equipo">
        <article><span>AGENDA ACTIVA</span><strong>{{ $bookableCount }}</strong><small>Estilistas y dueñas disponibles</small></article>
        <article><span>DUEÑAS</span><strong>{{ $ownerCount }}</strong><small>Perfil operativo más alto</small></article>
        <article><span>CAJA</span><strong>{{ $cashierCount }}</strong><small>Sin asignación a citas</small></article>
    </section>

    <form method="GET" class="list-filter-bar team-filter-bar" aria-label="Filtros del equipo">
        <label class="list-search"><span>⌕</span><input name="search" value="{{ $search }}" placeholder="Nombre o puesto"></label>
        <select name="status" aria-label="Filtrar por estatus"><option value="">Todas</option><option value="active" @selected($status === 'active')>Activas</option><option value="inactive" @selected($status === 'inactive')>Inactivas</option></select>
        <select name="availability" aria-label="Filtrar por agenda"><option value="">Agenda: todas</option><option value="bookable" @selected($availability === 'bookable')>Reciben citas</option><option value="internal" @selected($availability === 'internal')>Sólo operación</option></select>
        <select name="access" aria-label="Filtrar por acceso al sistema"><option value="">Acceso: todos</option><option value="enabled" @selected($access === 'enabled')>Con acceso</option><option value="disabled" @selected($access === 'disabled')>Sin acceso</option></select>
        <button class="button button-secondary" type="submit">Filtrar</button>
        @if($search || $status || $availability || $access)<a class="filter-clear" href="{{ route('employees.index') }}">Limpiar</a>@endif
    </form>

    <section class="team-grid">
        @forelse($employees as $employee)
            <article class="team-card surface">
                <header>
                    <span class="profile-avatar small">{{ str($employee->first_name)->substr(0, 1) }}</span>
                    <span class="pill {{ $employee->status === 'active' ? 'en-servicio' : 'programada' }}">{{ $employee->status === 'active' ? 'Activa' : 'Inactiva' }}</span>
                </header>
                <div class="team-card-heading">
                    <h2>{{ $employee->full_name }}</h2>
                    <p class="team-role {{ $employee->position === 'Dueña' ? 'team-role-owner' : '' }}">{{ $employee->position ?: 'Perfil pendiente de definir' }}</p>
                </div>
                <p class="team-availability">{{ $employee->is_bookable ? 'Disponible para citas' : 'Operación sin agenda' }}</p>
                <p class="team-system-access {{ $employee->user?->is_active ? 'is-active' : '' }}">{{ $employee->user?->is_active ? '● Acceso al sistema activo' : '○ Sin acceso al sistema' }}</p>
                <dl class="team-commissions">
                    <div><dt>Productos</dt><dd>{{ $employee->product_commission_rate !== null ? $employee->product_commission_rate.'%' : 'Sin definir' }}</dd></div>
                    <div><dt>Servicios</dt><dd>{{ $employee->commission_rate !== null ? $employee->commission_rate.'%' : 'Sin definir' }}</dd></div>
                </dl>
                <div class="team-card-actions">
                    <a class="card-link" href="{{ route('employees.edit', $employee) }}">Ver perfil <span>→</span></a>
                    @if($employee->status === 'active')
                        <form method="POST" action="{{ route('employees.destroy', $employee) }}" onsubmit="return confirm('¿Quitar a {{ $employee->full_name }} del equipo? Se conservará su historial y podrá consultarse como inactiva.')">
                            @csrf
                            @method('DELETE')
                            <button class="text-action-danger" type="submit">Quitar del equipo</button>
                        </form>
                    @endif
                </div>
            </article>
        @empty
            <p class="empty-state">Aún no hay colaboradoras registradas.</p>
        @endforelse
    </section>
    <div class="pagination-row">{{ $employees->links() }}</div>
</x-layouts.app>
