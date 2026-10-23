@props(['import'])
@php
    $preview = $import->preview;
    $changes = collect($preview['changes'] ?? []);
    $added = $changes->where('action', 'added')->count();
    $variants = $changes->where('action', 'variant_added')->count();
    $updated = $changes->filter(fn ($change) => ($change['action'] ?? 'updated') === 'updated')->count();
@endphp
<section class="surface form-section">
    <header>
        <div>
            <p class="eyebrow">{{ $import->status === 'applied' ? 'RESULTADO APLICADO' : 'REVISIÓN · TODAVÍA SIN APLICAR' }}</p>
            <h2>{{ $import->module === 'products' ? 'Altas y actualizaciones de catálogo' : $updated.' '.($updated === 1 ? 'existencia por actualizar' : 'existencias por actualizar') }}</h2>
            <p>{{ $preview['total'] ?? 0 }} filas revisadas · {{ $added }} productos nuevos · {{ $variants }} presentaciones nuevas · {{ $updated }} actualizaciones · {{ $preview['unchanged'] ?? 0 }} sin cambios · {{ $preview['skipped'] ?? 0 }} omitidas.</p>
        </div>
        @can('imports.view')<a class="button button-secondary" href="{{ route('imports.result', $import) }}">Descargar resultado CSV</a>@endcan
    </header>
    @if(!empty($preview['errors']))
        <div role="alert"><h3>No se puede aplicar: {{ count($preview['errors']) }} errores</h3><p>Corrige el archivo y vuelve a subirlo. No se aplicó ningún cambio.</p><ul class="list-disc space-y-2 pl-5">@foreach(array_slice($preview['errors'], 0, 50) as $error)<li>{{ $error }}</li>@endforeach</ul>@if(count($preview['errors']) > 50)<p>Descarga el resultado para consultar todos los errores.</p>@endif</div>
    @endif
    @if(!empty($preview['warnings']))
        <details><summary>{{ count($preview['warnings']) }} avisos y filas omitidas</summary><ul class="list-disc space-y-2 pl-5">@foreach($preview['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul></details>
    @endif
    @if($changes->isEmpty() && empty($preview['errors']))
        <p>{{ $import->module === 'inventory' ? 'El archivo no contiene cambios de existencias.' : 'El archivo no contiene altas ni actualizaciones.' }}</p>
    @endif
    @foreach($changes->take(100) as $change)
        <details class="break-words border-b border-gray-200 py-3" @if($changes->count() <= 5) open @endif>
            <summary><strong>{{ ['added' => 'Agregar producto', 'variant_added' => 'Agregar presentación', 'updated' => 'Actualizar'][$change['action'] ?? 'updated'] }}</strong> · Fila {{ $change['row'] }} · {{ $change['name'] }} · {{ $change['sku'] ?? 'Sin SKU' }}</summary>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr><th class="p-3">Campo</th><th class="p-3">Anterior</th><th class="p-3">Nuevo</th></tr></thead>
                    <tbody>
                        @foreach($change['diff'] ?? [['label' => 'Existencia ('.$change['unit'].')', 'before' => $change['before'], 'after' => $change['after']]] as $diff)
                            @if(($diff['field'] ?? '') !== 'cost' || auth()->user()->can('products.costs.view'))
                                <tr class="border-t border-gray-200"><th class="p-3">{{ $diff['label'] }}</th><td class="p-3">{{ $diff['before'] === '' || $diff['before'] === null ? '—' : $diff['before'] }}</td><td class="p-3 font-semibold">{{ $diff['after'] === '' || $diff['after'] === null ? 'Vacío' : $diff['after'] }}</td></tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endforeach
    @if($changes->count() > 100)<p>Se muestran las primeras 100 filas. Descarga el resultado CSV para revisar las {{ $changes->count() }} filas antes de confirmar.</p>@endif
</section>
