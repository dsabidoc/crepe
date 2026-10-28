<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\FinanceTransaction;
use App\Models\FinanceTransactionCorrection;
use App\Models\PayableInvoice;
use App\Models\PayablePayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceCorrectionService
{
    public function correct(int $id, User $actor, ?string $amount, string $reason, string $key, string $expectedAmount): FinanceTransactionCorrection
    {
        abort_unless($actor->hasRole('Administrador'), 403);

        return DB::transaction(function () use ($id, $actor, $amount, $reason, $key, $expectedAmount): FinanceTransactionCorrection {
            $transaction = FinanceTransaction::withoutGlobalScope('effective')->lockForUpdate()->findOrFail($id);
            $existing = FinanceTransactionCorrection::query()->where('finance_transaction_id', $id)->first();
            if ($existing) {
                return $existing;
            }
            if ((float) $transaction->amount !== (float) $expectedAmount || ($amount !== null && (! is_numeric($amount) || (float) $amount <= 0 || round((float) $amount, 2) !== (float) $amount || (float) $amount === (float) $transaction->amount))) {
                throw ValidationException::withMessages(['amount' => 'Revisa el importe actual y captura un importe distinto, positivo y con hasta dos decimales.']);
            }
            if ($transaction->cash_session_id || ($transaction->source_type && $transaction->source_type !== PayablePayment::class)) {
                throw ValidationException::withMessages(['transaction' => 'Este movimiento se originó en otro módulo. Debe corregirse desde su operación original.']);
            }
            $originals = collect([$transaction]);
            if ($transaction->type === 'transfer') {
                $pair = FinanceTransaction::query()->whereKeyNot($id)->where('type', 'transfer')->where('finance_account_id', $transaction->transfer_to_account_id)->where('transfer_to_account_id', $transaction->finance_account_id)->where('direction', $transaction->direction === 'in' ? 'out' : 'in')->where('amount', $transaction->amount)->whereNull('source_type')->whereNull('cash_session_id');
                if ($transaction->transfer_group) {
                    $pair->where('transfer_group', $transaction->transfer_group);
                } else {
                    $pair->where('concept', $transaction->concept)->where('created_by', $transaction->created_by)->where('occurred_on', $transaction->occurred_on)->whereBetween('created_at', [$transaction->created_at->copy()->subSeconds(2), $transaction->created_at->copy()->addSeconds(2)]);
                }
                $paired = $pair->lockForUpdate()->get();
                if ($paired->count() !== 1) {
                    throw ValidationException::withMessages(['transaction' => 'No se identificó con certeza la contraparte del traspaso. No se modificó ninguna cuenta.']);
                }
                $originals->push($paired->first());
            }
            $newSource = null;
            if ($transaction->source_type === PayablePayment::class) {
                $payment = PayablePayment::query()->lockForUpdate()->findOrFail($transaction->source_id);
                $invoice = PayableInvoice::query()->lockForUpdate()->findOrFail($payment->payable_invoice_id);
                $paid = round((float) $invoice->paid_amount - (float) $payment->amount + (float) $amount, 2);
                if ($paid < 0 || $paid > (float) $invoice->amount) {
                    throw ValidationException::withMessages(['amount' => 'El nuevo abono excede el saldo de la factura.']);
                }
                $payment->forceFill(['voided_at' => now(), 'voided_by' => $actor->id])->save();
                if ($amount !== null) {
                    $newSource = PayablePayment::query()->create([...$payment->only(['payable_invoice_id', 'finance_account_id', 'paid_on', 'reference', 'notes', 'evidence_path']), 'amount' => $amount, 'created_by' => $actor->id]);
                }
                $invoice->update(['paid_amount' => $paid, 'status' => $paid >= (float) $invoice->amount ? 'paid' : ($paid > 0 ? 'partial' : 'pending')]);
                app(OperationalNotificationService::class)->invoiceChanged($invoice);
            }
            $result = null;
            $group = $transaction->type === 'transfer' ? (string) str()->uuid() : null;
            foreach ($originals as $original) {
                $replacement = $amount === null ? null : FinanceTransaction::query()->create([...$original->only(['finance_account_id', 'transfer_to_account_id', 'finance_expense_category_id', 'type', 'direction', 'concept', 'occurred_on', 'reference', 'notes', 'evidence_path', 'source_type', 'source_id']), 'source_id' => $newSource?->id ?? $original->source_id, 'amount' => $amount, 'created_by' => $actor->id, 'transfer_group' => $group]);
                $correction = FinanceTransactionCorrection::query()->create(['finance_transaction_id' => $original->id, 'replacement_transaction_id' => $replacement?->id, 'request_key' => $key, 'created_by' => $actor->id, 'reason' => $reason, 'snapshot' => $original->toArray()]);
                AuditLog::query()->create(['user_id' => $actor->id, 'action' => 'finance.transaction.corrected', 'subject_type' => FinanceTransaction::class, 'subject_id' => $original->id, 'before' => $original->toArray(), 'after' => ['correction_id' => $correction->id, 'replacement_id' => $replacement?->id, 'amount' => $amount], 'reason' => $reason]);
                $result ??= $correction;
            }

            return $result;
        });
    }
}
