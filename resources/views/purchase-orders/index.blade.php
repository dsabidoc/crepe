<x-layouts.app title="Órdenes de compra">
    <section class="page-heading">
        <div><p class="eyebrow">ALMACÉN</p><h1>Órdenes de compra</h1><p>Consulta pedidos por proveedor, revisa su estatus y abre la recepción cuando lleguen.</p></div>
        <div class="title-actions"><a class="button button-secondary" href="{{ route('products.catalogs') }}">Catálogos</a><a class="button button-primary" href="{{ route('purchase-orders.create') }}">+ Nueva orden</a></div>
    </section>

    <section class="surface list-surface">
        <header class="list-controls">
            <div><p class="eyebrow">PEDIDOS</p><h2>Listado de órdenes</h2></div>
            <form method="GET" class="list-filter-bar standard-filter-bar" aria-label="Filtros de órdenes de compra">
                <label class="list-search"><span>⌕</span><input name="search" value="{{ $search }}" placeholder="Folio o proveedor"></label>
                <select name="supplier" aria-label="Filtrar por proveedor"><option value="">Todos los proveedores</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected($supplierId === $supplier->id)>{{ $supplier->name }}</option>@endforeach</select>
                <select name="status" aria-label="Filtrar por estatus"><option value="">Todos los estatus</option><option value="draft" @selected($status === 'draft')>Borradores</option><option value="received" @selected($status === 'received')>Recibidas</option></select>
                <button class="button button-secondary" type="submit">Filtrar</button>
                @if($search || $status || $supplierId)<a class="filter-clear" href="{{ route('purchase-orders.index') }}">Limpiar</a>@endif
            </form>
            <span class="record-count">{{ $orders->count() }} órdenes</span>
        </header>
        <div class="data-table">
            <div class="table-head payroll-head"><span>FOLIO</span><span>PROVEEDOR</span><span>PRODUCTOS</span><span>ESTATUS</span><span></span></div>
            @forelse($orders as $order)
                <a class="table-row payroll-head" href="{{ route('purchase-orders.show', $order) }}"><strong>{{ $order->code }}</strong><span>{{ $order->supplier->name }}</span><span>{{ $order->items->count() }}</span><span class="status-badge">{{ $order->status === 'received' ? 'Recibida' : 'Borrador' }}</span><b>→</b></a>
            @empty
                <p class="empty-state">Aún no hay órdenes de compra. <a href="{{ route('purchase-orders.create') }}">Crear la primera orden</a></p>
            @endforelse
        </div>
    </section>
</x-layouts.app>
