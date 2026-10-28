<x-layouts.app title="Corregir movimiento">
<section class="page-title"><div><p class="eyebrow">FINANZAS · CORRECCIÓN CON HISTORIAL</p><h1>{{ $transaction->concept }}</h1><p>{{ $transaction->account?->name }} · {{ $transaction->occurred_on->format('d/m/Y') }} · ${{ number_format((float) $transaction->amount, 2) }}</p></div></section>
<section class="surface form-section">
@if($transaction->correction)<p class="notice-info">Este movimiento ya fue {{ $transaction->correction->replacement_transaction_id ? 'corregido' : 'anulado' }}. Motivo: {{ $transaction->correction->reason }}</p>
@elseif($transaction->cash_session_id || ($transaction->source_type && $transaction->source_type !== \App\Models\PayablePayment::class))<p class="notice-info">Este movimiento debe corregirse desde el módulo donde se registró.</p>
@else
<p>El original se conservará. Al corregir, el importe nuevo sustituirá al anterior en los saldos. Al anular, dejará de afectar el saldo. Los traspasos se corrigen en ambas cuentas y los abonos actualizan la factura.</p>
@if($errors->any())<p class="notice-info">{{ $errors->first() }}</p>@endif
<form method="POST" action="{{ route('finance.corrections.store', $transaction->id) }}">@csrf<input type="hidden" name="previous_amount" value="{{ $transaction->amount }}"><input type="hidden" name="key" value="{{ (string) str()->uuid() }}"><div class="field-grid"><label><span>Acción</span><select name="action"><option value="correct">Corregir importe</option><option value="void">Anular movimiento</option></select></label><label><span>Importe correcto</span><input name="amount" type="number" step=".01" min=".01" value="{{ old('amount') }}"><small>Déjalo vacío para anular.</small></label><label><span>Motivo</span><input name="reason" required minlength="5" maxlength="255" value="{{ old('reason') }}"></label></div><footer class="form-footer"><a href="{{ route('finance.index', ['view'=>'movements']) }}">Cancelar</a><button class="button button-primary">Confirmar corrección</button></footer></form>
@endif
</section>
</x-layouts.app>
