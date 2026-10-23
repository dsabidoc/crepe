<x-layouts.app title="Importar inventario">
    <section class="page-title flex-wrap">
        <div><p class="eyebrow">INVENTARIO · EXCEL Y CSV</p><h1>Actualizar existencias</h1><p>Descarga, edita y revisa tu inventario antes de aplicar los ajustes.</p></div>
        <div class="title-actions min-w-0 flex-wrap">@can('imports.view')<a class="button button-secondary" href="{{ route('imports.index') }}">Historial de importaciones</a>@endcan<a class="button button-secondary" href="{{ route('inventory.index', ['location' => $locationCode]) }}">Volver al inventario</a></div>
    </section>
    @if($errors->any())<section class="surface form-section" role="alert"><h2>No se aplicaron cambios</h2><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></section>@endif
    <section class="surface form-section">
        <header><div><p class="eyebrow">1 · PREPARAR EL ARCHIVO</p><h2>Una fila por existencia</h2><p>Descarga la ubicación que vas a contar. El archivo incluye todas sus existencias activas, sin filtros ni paginación.</p></div></header>
        <ol class="list-decimal space-y-2 pl-5 text-sm leading-6">
            <li>Edita únicamente <strong>Existencia nueva</strong>, la columna verde del Excel. Es la cantidad final, no una entrada de mercancía.</li>
            <li>Cuenta <strong>unidades o envases completos</strong> en Almacén, Recepción e insumos de Color Bar. Los gramos se registran únicamente en las fórmulas.</li>
            <li>Usa cantidades enteras; escribe <strong>0</strong> para dejar la existencia en cero. Conserva los encabezados, ID, Existencia original y Control.</li>
            <li>Puedes quitar las filas que no quieras actualizar. Las filas ausentes no cambian ni se eliminan. Para agregar productos usa Productos.</li>
            <li>Guarda como <strong>.xlsx</strong> o <strong>CSV UTF-8</strong>. Revisa los cambios y confirma con un motivo.</li>
        </ol>
        <p>Si hubo movimientos desde la descarga, descarga de nuevo y actualiza el conteo. Los datos de catálogo, mínimos y máximos se editan desde Productos.</p>
        @can('inventory.export')
            <form method="GET" action="{{ route('inventory.export') }}">
                <div class="field-grid"><label><span>Ubicación a descargar</span><select name="location_code"><option value="ALM" @selected($locationCode === 'ALM')>Almacén</option><option value="REC" @selected($locationCode === 'REC')>Recepción</option><option value="CB" @selected($locationCode === 'CB')>Color Bar</option></select></label><label><span>Formato</span><select name="format"><option value="xlsx">Excel (.xlsx)</option><option value="csv">CSV UTF-8</option></select></label></div>
                <footer><button class="button button-secondary" type="submit">Descargar archivo de conteo</button></footer>
            </form>
        @endcan
    </section>
    <section class="surface form-section">
        <header><div><p class="eyebrow">2 · SUBIR Y VALIDAR</p><h2>Revisar archivo</h2><p>Excel .xlsx o CSV de hasta 5 MB y 10,000 existencias. La revisión todavía no modifica el inventario.</p></div></header>
        <form method="POST" action="{{ route('inventory.import.preview') }}" enctype="multipart/form-data">
            @csrf
            <div class="field-grid"><label><span>Ubicación del archivo</span><select name="location_code" required><option value="ALM" @selected($locationCode === 'ALM')>Almacén</option><option value="REC" @selected($locationCode === 'REC')>Recepción</option><option value="CB" @selected($locationCode === 'CB')>Color Bar</option></select></label><label><span>Archivo de inventario</span><input type="file" name="file" accept=".xlsx,.csv" required></label></div>
            <footer><button class="button button-primary" type="submit">Revisar cambios</button></footer>
        </form>
    </section>
    @if($import)
        <x-import-review :import="$import" />
        @if($import->status === 'reviewed' && $import->created_at->gte(now()->subDay()) && !empty($import->preview['changes']))
            @can('inventory.move')<section class="surface form-section"><h2>3 · Confirmar ajustes</h2><p>Se actualiza únicamente {{ ['ALM' => 'Almacén', 'REC' => 'Recepción', 'CB' => 'Color Bar'][$locationCode] }}. La revisión vence después de 24 horas.</p><form method="POST" action="{{ route('inventory.import.store') }}">@csrf<input type="hidden" name="token" value="{{ $import->token }}"><div class="field-grid"><label><span>Motivo del ajuste</span><input name="reason" maxlength="1000" value="{{ old('reason') }}" placeholder="Ej. Conteo físico" required></label></div><footer><button class="button button-primary" type="submit">Aplicar {{ count($import->preview['changes']) }} {{ count($import->preview['changes']) === 1 ? 'ajuste' : 'ajustes' }}</button></footer></form></section>@endcan
        @endif
    @endif
</x-layouts.app>
