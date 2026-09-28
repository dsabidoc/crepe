<x-layouts.app title="Promos">
    <section class="page-title"><div><p class="eyebrow">VENTAS</p><h1>Promos</h1><p>Crea descuentos para servicios, productos y combinaciones de tu salón.</p></div><a class="button button-primary" href="{{ route('promotions.create') }}">+ Nueva promo</a></section>
    <section class="surface promotions-list">
        <header><div><h2>Promociones</h2><p>Las promociones automáticas aparecen al aplicarlas desde un ticket. Las promociones con código se validan al capturarlo.</p></div><span class="record-count">{{ $promotions->total() }} registradas</span></header>
        <form method="GET" class="list-filter-bar promotions-filter-bar" aria-label="Filtros de promociones">
            <label class="list-search"><span>⌕</span><input name="search" value="{{ $search }}" placeholder="Nombre o código"></label>
            <select name="type" aria-label="Filtrar por tipo"><option value="">Todos los tipos</option><option value="order" @selected($type === 'order')>Total del ticket</option><option value="items" @selected($type === 'items')>Conceptos</option><option value="buy_x_get_y" @selected($type === 'buy_x_get_y')>Compra y regalo</option></select>
            <select name="method" aria-label="Filtrar por aplicación"><option value="">Todas las aplicaciones</option><option value="automatic" @selected($method === 'automatic')>Automáticas</option><option value="code" @selected($method === 'code')>Con código</option></select>
            <select name="status" aria-label="Filtrar por estatus"><option value="">Todos</option><option value="active" @selected($status === 'active')>Activas</option><option value="inactive" @selected($status === 'inactive')>Inactivas</option></select>
            <button class="button button-secondary" type="submit">Filtrar</button>
            @if($search || $type || $method || $status)<a class="filter-clear" href="{{ route('promotions.index') }}">Limpiar</a>@endif
        </form>
        @forelse($promotions as $promotion)
            @php($typeLabels = ['order' => 'Descuento al total', 'items' => 'Descuento en conceptos', 'buy_x_get_y' => 'Compra y recibe regalo'])
            <article class="promotion-row"><div class="promotion-icon">%</div><div><strong>{{ $promotion->name }}</strong><small>{{ $typeLabels[$promotion->type] ?? $promotion->type }} · {{ $promotion->method === 'code' ? 'Código '.$promotion->code : 'Automática' }}</small></div><span class="pill {{ $promotion->status === 'active' ? 'en-servicio' : 'programada' }}">{{ $promotion->status === 'active' ? 'ACTIVA' : 'INACTIVA' }}</span><div class="promotion-value">@if($promotion->type === 'buy_x_get_y'){{ $promotion->buy_quantity }} + {{ $promotion->reward_quantity }} gratis @else{{ $promotion->value_type === 'percentage' ? number_format((float) $promotion->value, 0).'%' : '$'.number_format((float) $promotion->value, 2) }} @endif</div><a class="button button-secondary" href="{{ route('promotions.edit', $promotion) }}">Editar</a></article>
        @empty
            <p class="empty-state">Aún no hay promociones. Crea la primera para comenzar.</p>
        @endforelse
        <div class="pagination-row">{{ $promotions->links() }}</div>
    </section>
</x-layouts.app>
