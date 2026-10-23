<x-layouts.app title="Importar productos">
    <section class="page-title flex-wrap">
        <div><p class="eyebrow">PRODUCTOS · EXCEL Y CSV</p><h1>Importar catálogo</h1><p>Agrega productos o revisa cambios a los existentes. Esta carga no modifica existencias.</p></div>
        <div class="title-actions min-w-0 flex-wrap">@can('imports.view')<a class="button button-secondary" href="{{ route('imports.index') }}">Historial de importaciones</a>@endcan<a class="button button-secondary" href="{{ route('products.index') }}">Volver a productos</a></div>
    </section>
    @if($errors->any())<section class="surface form-section" role="alert"><h2>No se aplicaron cambios</h2><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></section>@endif
    <section class="surface form-section">
        <header><div><p class="eyebrow">1 · PREPARAR</p><h2>Una fila por presentación</h2><p>Puedes subir solo cinco productos nuevos o las filas que quieras actualizar. Los productos ausentes quedan intactos.</p></div></header>
        <p>El ID o SKU de presentación identifica el registro. SKU producto agrupa las presentaciones. En actualizaciones, una celda vacía conserva el dato actual; escribe 0 para establecer cero. Para quitar marca, proveedor, descripción o máximo, escribe <strong>[BORRAR]</strong>.</p>
        <p>Usa categorías, marcas y proveedores registrados. Para altas completa SKU producto, SKU presentación, Producto, Presentación, Categoría, Unidad, Contenido y Costo. Recepción se activa por defecto; puedes elegir los usos con 1 o 0.</p>
        <p>Nombre del producto, categoría, marca, proveedor, descripción, usos y estado son compartidos por todas sus presentaciones. Si incluyes varias filas del mismo producto, esos datos deben coincidir.</p>
        @can('products.export')<div class="title-actions min-w-0 flex-wrap"><a class="button button-secondary" href="{{ route('products.export', ['template' => 1]) }}">Plantilla Excel</a><a class="button button-secondary" href="{{ route('products.export', ['template' => 1, 'format' => 'csv']) }}">Plantilla CSV</a><a class="button button-secondary" href="{{ route('products.export') }}">Catálogo Excel</a><a class="button button-secondary" href="{{ route('products.export', ['format' => 'csv']) }}">Catálogo CSV</a></div>@endcan
    </section>
    <section class="surface form-section">
        <header><div><p class="eyebrow">2 · VALIDAR</p><h2>Subir archivo</h2><p>Excel .xlsx o CSV UTF-8. Hasta 5 MB y 10,000 presentaciones. Sin fórmulas.</p></div></header>
        <form method="POST" action="{{ route('products.import.preview') }}" enctype="multipart/form-data">
            @csrf
            <div class="field-grid"><label><span>Qué hacer con el archivo</span><select name="mode" required><option value="upsert" @selected(old('mode', $import?->mode) === 'upsert')>Agregar nuevos y actualizar existentes</option><option value="add" @selected(old('mode', $import?->mode) === 'add')>Solo agregar nuevos (omitir existentes)</option><option value="update" @selected(old('mode', $import?->mode) === 'update')>Solo actualizar existentes</option></select></label><label><span>Archivo</span><input type="file" name="file" accept=".xlsx,.csv" required></label></div>
            <footer><button class="button button-primary" type="submit">Revisar cambios</button></footer>
        </form>
    </section>
    @if($import)
        <x-import-review :import="$import" />
        @if($import->status === 'reviewed' && $import->created_at->gte(now()->subDay()) && !empty($import->preview['changes']))
            <section class="surface form-section"><h2>3 · Confirmar importación</h2><p>Se aplican todas las filas válidas juntas. La revisión vence después de 24 horas.</p><form method="POST" action="{{ route('products.import.store') }}">@csrf<input type="hidden" name="token" value="{{ $import->token }}"><div class="field-grid"><label><span>Motivo de la importación</span><textarea name="reason" maxlength="1000" required>{{ old('reason') }}</textarea></label></div><footer><button class="button button-primary" type="submit">Aplicar {{ count($import->preview['changes']) }} altas o actualizaciones</button></footer></form></section>
        @endif
    @endif
</x-layouts.app>
