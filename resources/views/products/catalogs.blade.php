<x-layouts.app title="Catálogos de productos">
    <section class="page-title">
        <div><p class="eyebrow">ALMACÉN · CATÁLOGOS</p><h1>Marcas y proveedores</h1><p>Administra los datos que se podrán asignar a los productos y usar en las órdenes de compra.</p></div>
        <a class="button button-secondary" href="{{ route('products.index') }}">Volver a productos</a>
    </section>

    <section class="dashboard-grid">
        <article class="surface form-section">
            <header><div><p class="eyebrow">MARCAS</p><h2>Catálogo de marcas</h2><p>La marca queda disponible al crear o editar un producto.</p></div></header>
            <form method="POST" action="{{ route('products.brands.store') }}" class="catalog-quick-form">@csrf
                <label><span>Nueva marca</span><input name="name" maxlength="120" required placeholder="Ej. Wella"></label>
                <button class="button button-primary" type="submit">Agregar marca</button>
            </form>
            <div class="catalog-record-list">
                @forelse($brands as $brand)
                    <div><span><strong>{{ $brand->name }}</strong><small>{{ $brand->products_count }} {{ $brand->products_count === 1 ? 'producto' : 'productos' }}</small></span><span class="status-badge">{{ $brand->is_active ? 'Activa' : 'Inactiva' }}</span></div>
                @empty
                    <p class="empty-state">Aún no hay marcas registradas.</p>
                @endforelse
            </div>
        </article>

        <article class="surface form-section">
            <header><div><p class="eyebrow">PROVEEDORES</p><h2>Catálogo de proveedores</h2><p>Define su contacto y días de crédito para las cuentas por pagar.</p></div></header>
            <form method="POST" action="{{ route('suppliers.store') }}" class="catalog-supplier-form">@csrf
                <label><span>Proveedor</span><input name="name" maxlength="150" required placeholder="Nombre comercial"></label>
                <label><span>Contacto</span><input name="contact_name" maxlength="120" placeholder="Opcional"></label>
                <label><span>Teléfono</span><input name="phone" maxlength="32" placeholder="Opcional"></label>
                <label><span>Días de crédito</span><input name="payment_grace_days" type="number" min="0" max="365" value="0" required></label>
                <button class="button button-primary" type="submit">Agregar proveedor</button>
            </form>
            <div class="catalog-record-list">
                @forelse($suppliers as $supplier)
                    <div><span><strong>{{ $supplier->name }}</strong><small>{{ $supplier->contact_name ?: 'Sin contacto' }} · {{ $supplier->payment_grace_days }} días de crédito · {{ $supplier->purchase_orders_count }} órdenes</small></span><span class="status-badge">{{ $supplier->is_active ? 'Activo' : 'Inactivo' }}</span></div>
                @empty
                    <p class="empty-state">Aún no hay proveedores registrados.</p>
                @endforelse
            </div>
        </article>
    </section>
</x-layouts.app>
