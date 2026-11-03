@if($replenishment = session('inventory_replenishment'))
<section class="surface" aria-label="Resultado del envío desde inventario" style="padding:18px 22px;margin-bottom:20px">
    <h2 style="font-size:16px;margin:0 0 8px">Reabasto desde inventario</h2>
    <p style="font-size:13px;margin:0 0 12px">{{ $replenishment['added'] }} productos agregados · {{ $replenishment['existing'] }} ya estaban en borradores y conservaron sus cantidades. Las existencias permanecen sin cambios.</p>
    <div style="display:flex;flex-wrap:wrap;gap:10px">@foreach($replenishment['orders'] as $order)<a class="button button-secondary" href="{{ route('purchase-orders.show', $order['id']) }}">{{ $order['code'] }} · {{ $order['supplier'] }}</a>@endforeach</div>
    @if($replenishment['skipped'])<details style="margin-top:16px"><summary style="cursor:pointer;color:#934560">{{ count($replenishment['skipped']) }} productos no se agregaron: requieren proveedor activo</summary><p style="font-size:12px">Asigna o activa su proveedor preferente en Productos y vuelve a enviarlos desde inventario.</p><ul style="font-size:12px">@foreach($replenishment['skipped'] as $product)<li>{{ $product['name'] }}{{ $product['sku'] ? ' · '.$product['sku'] : '' }}</li>@endforeach</ul></details>@endif
</section>
@endif
