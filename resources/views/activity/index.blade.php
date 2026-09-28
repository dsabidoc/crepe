<x-layouts.app title="Bitácoras">
@php
    $locationLabel = $location ? ($locationOptions[$location] ?? $location) : 'Todas las ubicaciones';
    $movementLabels = [
        'opening' => 'Apertura',
        'purchase' => 'Compra',
        'sale' => 'Venta',
        'transfer_in' => 'Entrada por traspaso',
        'transfer_out' => 'Salida por traspaso',
        'color_consumption' => 'Consumo Color Bar',
        'adjustment' => 'Ajuste',
    ];
@endphp
<section class="page-title activity-page-title">
    <div>
        <p class="eyebrow">CONTROL Y TRAZABILIDAD</p>
        <h1>Bitácoras</h1>
        <p>Consulta movimientos de inventario y cambios de solicitudes sin hacer crecer las tablas operativas.</p>
    </div>
    <div class="title-actions">
        @if($isAdministrator)
            @foreach($locationOptions as $code => $name)
                <a class="button {{ $location === $code ? 'button-primary' : 'button-secondary' }}" href="{{ route('activity.index', ['location' => $code]) }}">{{ $name }}</a>
            @endforeach
        @endif
        <a class="button button-secondary" href="{{ route('inventory.index', $location ? ['location' => $location] : []) }}">Volver al inventario</a>
    </div>
</section>

<section class="surface activity-filters">
    <form method="GET" action="{{ route('activity.index') }}">
        @if($location)<input type="hidden" name="location" value="{{ $location }}">@endif
        <div class="activity-filter-grid">
            <label><span>Buscar</span><input name="search" value="{{ $filters['search'] }}" placeholder="Producto, SKU o motivo"></label>
            <label><span>Tipo de movimiento</span><select name="movement_type"><option value="">Todos</option>@foreach($movementTypes as $type)<option value="{{ $type }}" @selected($filters['movement_type'] === $type)>{{ $movementLabels[$type] ?? str($type)->replace('_', ' ')->headline() }}</option>@endforeach</select></label>
            <label><span>Desde</span><input name="from" type="date" value="{{ $filters['from'] }}"></label>
            <label><span>Hasta</span><input name="to" type="date" value="{{ $filters['to'] }}"></label>
            <button class="button button-primary" type="submit">Aplicar filtros</button>
            <a class="activity-clear" href="{{ route('activity.index', $location ? ['location' => $location] : []) }}">Limpiar</a>
        </div>
    </form>
</section>

<section class="activity-layout">
    <article class="surface activity-list">
        <header><div><p class="eyebrow">{{ $locationLabel }}</p><h2>Movimientos de inventario</h2><p>Entradas, salidas, ventas, consumos y traspasos registrados.</p></div><strong class="activity-count">{{ $movements->total() }} registros</strong></header>
        <div class="activity-table activity-movement-table">
            <div class="activity-table-head"><span>FECHA</span><span>PRODUCTO</span><span>UBICACIÓN</span><span>MOVIMIENTO</span><span>DETALLE</span></div>
            @forelse($movements as $movement)
                <div class="activity-table-row">
                    <time>{{ $movement->created_at->format('d/m/Y H:i') }}</time>
                    <div><strong>{{ $movement->variant?->product?->name ?: 'Producto eliminado' }}</strong><small>{{ $movement->variant?->name ?: '—' }}{{ $movement->variant?->sku ? ' · '.$movement->variant->sku : '' }}</small></div>
                    <span>{{ $movement->location?->name ?: '—' }}</span>
                    <strong class="{{ (float) $movement->quantity < 0 ? 'activity-out' : 'activity-in' }}">{{ (float) $movement->quantity > 0 ? '+' : '' }}{{ number_format((float) $movement->quantity, 3) }} {{ $movement->variant?->base_unit }}</strong>
                    <div><span>{{ $movementLabels[$movement->type] ?? str($movement->type)->replace('_', ' ')->headline() }}</span><small>{{ $movement->reason ?: 'Sin motivo capturado' }}{{ $movement->creator ? ' · '.$movement->creator->name : '' }}</small></div>
                </div>
            @empty
                <p class="empty-state">No hay movimientos que coincidan con los filtros.</p>
            @endforelse
        </div>
        @if($movements->hasPages())<footer class="activity-pagination">{{ $movements->links() }}</footer>@endif
    </article>

    <article class="surface activity-list">
        <header><div><p class="eyebrow">CONTROL DE CAMBIOS</p><h2>Auditoría de solicitudes</h2><p>Creación y cierre de solicitudes de inventario.</p></div><strong class="activity-count">{{ $audits->total() }} registros</strong></header>
        <div class="activity-table activity-audit-table">
            <div class="activity-table-head"><span>FECHA</span><span>EVENTO</span><span>USUARIO</span><span>DETALLE</span></div>
            @forelse($audits as $audit)
                <div class="activity-table-row">
                    <time>{{ $audit->created_at->format('d/m/Y H:i') }}</time>
                    <div><strong>{{ str($audit->action)->replace('.', ' · ')->replace('_', ' ')->headline() }}</strong><small>@if($audit->subject instanceof \App\Models\InventoryRequest)Solicitud {{ $audit->subject->code }} · {{ $audit->subject->sourceLocation?->name }}@else{{ class_basename($audit->subject_type) }} #{{ $audit->subject_id }}@endif</small></div>
                    <span>{{ $audit->user?->name ?: 'Sistema' }}</span>
                    <div><span>{{ $audit->reason ?: 'Sin comentario' }}</span><small>@if($audit->after && isset($audit->after['status'])) Estado: {{ $audit->after['status'] }} @endif @if($audit->after && isset($audit->after['items'])) · {{ count($audit->after['items']) }} conceptos @endif</small></div>
                </div>
            @empty
                <p class="empty-state">No hay eventos de solicitudes para mostrar.</p>
            @endforelse
        </div>
        @if($audits->hasPages())<footer class="activity-pagination">{{ $audits->links() }}</footer>@endif
    </article>
</section>
</x-layouts.app>
