@if($visits->total())
    <p class="color-history-summary">{{ $visits->total() }} {{ $visits->total() === 1 ? 'visita anterior' : 'visitas anteriores' }} · De la más reciente a la más antigua</p>
    <div class="color-history-visits">
        @foreach($visits as $visit)
            @php($visitDate = $visit->appointment?->starts_at ?? $visit->opened_at ?? $visit->created_at)
            <details class="color-history-visit" @if($loop->first) open @endif>
                <summary>
                    <div><strong><time datetime="{{ $visitDate->toIso8601String() }}">{{ $visitDate->format('d/m/Y · H:i') }}</time></strong><span>{{ $visit->appointment?->services->pluck('name_snapshot')->join(' + ') ?: 'Visita sin cita' }}</span><small>{{ $visit->code }}@if($visit->appointment?->employee) · {{ $visit->appointment->employee->full_name }}@endif</small></div>
                    <span class="color-history-visit-count">{{ $visit->colorFormulas->count() }} {{ $visit->colorFormulas->count() === 1 ? 'fórmula' : 'fórmulas' }} <b aria-hidden="true">⌄</b></span>
                </summary>
                <div class="color-history-formulas">
                    @forelse($visit->colorFormulas as $formula)
                        <section class="color-history-formula">
                            <h3>Fórmula {{ $loop->iteration }} @if($formula->supersedes_color_formula_id)<small>Corregida</small>@endif</h3>
                            @if($formula->service_name_snapshot)<p class="color-history-note">{{ $formula->service_name_snapshot }}{{ $formula->color_bar_included ? ' · Color Bar incluido' : '' }}</p>@endif
                            @forelse($formula->items as $item)
                                <div class="color-history-product">
                                    <div><strong>{{ $item->variant?->product?->name ?? 'Producto no disponible' }}</strong><x-product-reference :product="$item->variant?->product" />@if($item->variant)<small>{{ $item->variant->name }}</small>@endif@if($item->notes)<p>{{ $item->notes }}</p>@endif</div>
                                    <span>{{ number_format((float) $item->quantity, $item->unit === 'g' ? 3 : 0) }} {{ $item->unit === 'g' ? 'gr' : ($item->quantity == 1 ? 'unidad' : 'unidades') }}</span>
                                </div>
                            @empty
                                <p class="color-history-note">Sin productos · corrección confirmada.</p>
                            @endforelse
                            @if($formula->notes)<p class="color-history-note"><strong>Nota de la fórmula:</strong> {{ $formula->notes }}</p>@endif
                        </section>
                    @empty
                        <p class="color-history-note">No se registraron fórmulas de Color Bar en esta visita.</p>
                    @endforelse
                </div>
            </details>
        @endforeach
    </div>
    @if($visits->hasPages())
        <nav class="color-history-pagination" aria-label="Páginas del historial">
            @if($visits->previousPageUrl())<a class="button button-secondary" href="{{ $visits->previousPageUrl() }}" data-color-history-page>Anterior</a>@endif
            <span>Página {{ $visits->currentPage() }} de {{ $visits->lastPage() }}</span>
            @if($visits->nextPageUrl())<a class="button button-secondary" href="{{ $visits->nextPageUrl() }}" data-color-history-page>Siguiente</a>@endif
        </nav>
    @endif
@else
    <p class="empty-state">Esta clienta aún no tiene visitas anteriores de Color Bar.</p>
@endif
