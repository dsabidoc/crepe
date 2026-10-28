@if($reversalRequests->isNotEmpty())
<style>
.activity-reverse-trigger{margin-left:auto;padding:4px 8px;border:1px solid #d9bec8;border-radius:6px;color:#94465f;background:transparent;font:inherit;font-size:11px;cursor:pointer}.activity-reverse-trigger:hover,.activity-reverse-trigger:focus-visible{background:#fff;border-color:#ac526f}.activity-reversal-done{margin-left:auto;color:#6d7784;font-size:11px}.request-reversal-dialog{width:540px;max-width:calc(100vw - 32px);max-height:calc(100dvh - 48px);padding:0;border:1px solid #eadde2;border-radius:18px;background:#fff;color:#303c50;box-shadow:0 24px 90px #1d152f40;overflow:auto}.request-reversal-dialog::backdrop{background:#17203470;backdrop-filter:blur(3px)}.app-body .request-reversal-dialog header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:24px 24px 0}.app-body .request-reversal-dialog h2{margin:6px 0 0;font-size:21px;font-weight:650;line-height:1.35;letter-spacing:-.03em}.request-reversal-dialog .reversal-code{color:#a64d6c;font-size:11px;font-weight:700;letter-spacing:.07em}.request-reversal-close{display:grid;place-items:center;flex:0 0 auto;width:32px;height:32px;padding:0;border:0;border-radius:50%;background:#f5f2f4;color:#6b6470;font-size:23px;cursor:pointer}.request-reversal-body{padding:16px 24px 24px}.request-reversal-body>p{margin:0 0 14px;font-size:13px;line-height:1.7;color:#667085}.request-reversal-products{list-style:none;padding:0;margin:14px 0 18px;max-height:230px;overflow:auto;border:1px solid #e9e6eb;border-radius:10px}.request-reversal-products li{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 14px;border-bottom:1px solid #eeecf0;font-size:12px}.request-reversal-products li:last-child{border:0}.request-reversal-products strong{display:block;line-height:1.5}.request-reversal-products small{display:block;margin-top:3px;color:#7a8495;font-size:10px}.request-reversal-products b{color:#95445e;white-space:nowrap;font-size:12px}.request-reversal-body label{display:grid;gap:7px;font-size:12px;font-weight:600}.app-body .workspace .request-reversal-body textarea{width:100%;min-height:88px;height:88px;padding:10px 12px;border:1px solid #dce1e8;border-radius:9px;font:inherit;font-size:13px;resize:vertical}.request-reversal-body .reversal-physical-confirmation{display:flex;align-items:flex-start;gap:10px;margin-top:14px;font-weight:400;line-height:1.6}.reversal-physical-confirmation input{width:17px;height:17px;flex:0 0 auto;margin:2px 0 0;accent-color:#ac526f}.request-reversal-body footer{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:10px;margin-top:22px;padding-top:18px;border-top:1px solid #eeecf0}.request-reversal-body .button{font-size:12px;min-height:38px}.request-reversal-alert{padding:12px 14px;border:1px solid #edd3dc;border-radius:10px;background:#fff5f8;color:#8b405c;font-size:12px;line-height:1.6}.request-reversal-body .reversal-audit-note{font-size:11px;color:#7b8493}@media(max-width:560px){.app-body .request-reversal-dialog header{padding:20px 18px 0}.request-reversal-body{padding:14px 18px 20px}.app-body .request-reversal-dialog h2{font-size:19px}.request-reversal-body footer .button{flex:1;justify-content:center}}
</style>
@foreach($reversalRequests as $reversalRequest)
    @continue($reversalRequest->reversal)
    @php
        $transfers = $reversalTransfers->get($reversalRequest->id, collect());
        $blocked = $reversalRequest->code === 'SOL-000020' || $reversalRequest->reversal_hold_reason || $transfers->isEmpty();
        $reopen = (string) old('reversal_request_id') === (string) $reversalRequest->id && $errors->any();
    @endphp
    <dialog id="request-reversal-{{ $reversalRequest->id }}" class="request-reversal-dialog" aria-labelledby="reversal-title-{{ $reversalRequest->id }}" @if($reopen) data-reversal-reopen @endif>
        <header><div><span class="reversal-code">{{ $reversalRequest->code }} · {{ $reversalRequest->sourceLocation->name }}</span><h2 id="reversal-title-{{ $reversalRequest->id }}">¿Revertir esta entrega?</h2></div><button type="button" class="request-reversal-close" data-reversal-close aria-label="Cerrar confirmación">×</button></header>
        <div class="request-reversal-body">
            <p>Se retirarán de <strong>{{ $reversalRequest->sourceLocation->name }}</strong> las unidades de esta entrega y se devolverán a <strong>Almacén</strong>.</p>
            @if($transfers->isNotEmpty())<ul class="request-reversal-products" aria-label="Productos que regresarán a Almacén">@foreach($transfers as $transfer)<li><div><strong>{{ $transfer->variant?->product?->name ?: 'Producto no disponible' }}</strong><small>{{ $transfer->variant?->name }}</small></div><b>{{ rtrim(rtrim(number_format((float) $transfer->quantity, 3, '.', ','), '0'), '.') }} unidades</b></li>@endforeach</ul>@endif
            <p class="reversal-audit-note">Se conservará la entrega original y se registrará su devolución con tu nombre y el motivo. Los movimientos posteriores se conservarán. Se comprobará que todavía haya unidades suficientes para devolver toda la entrega.</p>
            @if($blocked)
                <p class="request-reversal-alert">{{ $transfers->isEmpty() ? 'Esta entrega requiere revisar sus movimientos antes de poder revertirse.' : 'Esta solicitud está protegida. Primero debe confirmarse su entrega física antes de habilitar la reversión.' }}</p>
                <footer><button type="button" class="button button-secondary" data-reversal-close>Entendido</button></footer>
            @else
                <form method="POST" action="{{ route('inventory.requests.reverse', $reversalRequest) }}" data-reversal-form>
                    @csrf
                    <input type="hidden" name="key" value="{{ $reopen ? old('key') : (string) str()->uuid() }}">
                    <input type="hidden" name="reversal_request_id" value="{{ $reversalRequest->id }}">
                    <label><span>Motivo de la reversión</span><textarea name="reason" required minlength="5" maxlength="255" rows="2" placeholder="Ej. Se confirmó una entrega por error">{{ $reopen ? old('reason') : '' }}</textarea></label>
                    <label class="reversal-physical-confirmation"><input type="checkbox" required><span>Confirmo que estas unidades se devolverán físicamente a Almacén.</span></label>
                    @if($reopen)<p class="request-reversal-alert" role="alert">{{ implode(' ', $errors->all()) }}</p>@endif
                    <footer><button type="button" class="button button-secondary" data-reversal-close>Cancelar</button><button type="submit" class="button button-primary" data-reversal-submit>Confirmar reversión</button></footer>
                </form>
            @endif
        </div>
    </dialog>
@endforeach
<script>
(() => {
    document.querySelectorAll('[data-reversal-open]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.reversalOpen)?.showModal()));
    document.querySelectorAll('.request-reversal-dialog').forEach(dialog => {
        dialog.querySelectorAll('[data-reversal-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
        dialog.querySelector('[data-reversal-form]')?.addEventListener('submit', () => {
            const submit = dialog.querySelector('[data-reversal-submit]');
            submit.disabled = true;
            submit.textContent = 'Revirtiendo…';
        });
        if (dialog.hasAttribute('data-reversal-reopen')) dialog.showModal();
    });
})();
</script>
@endif
