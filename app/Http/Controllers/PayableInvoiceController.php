<?php

namespace App\Http\Controllers;

use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\PayableInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PayableInvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search'));
        $status = $request->string('status')->toString();
        $invoices = PayableInvoice::query()
            ->with(['purchaseOrder.supplier', 'payments.account'])
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('invoice_reference', 'like', "%{$search}%")
                ->orWhereHas('purchaseOrder', fn ($orders) => $orders
                    ->where('code', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($suppliers) => $suppliers->where('name', 'like', "%{$search}%")))))
            ->when(in_array($status, ['pending', 'partial', 'paid'], true), fn ($query) => $query->where('status', $status))
            ->latest('due_on')
            ->get();

        return view('payables.index', compact('invoices', 'search', 'status') + ['accounts' => FinanceAccount::query()->where('is_active', true)->orderBy('name')->get()]);
    }

    public function storePayment(Request $request, PayableInvoice $payableInvoice): RedirectResponse
    {
        $data = $request->validate(['finance_account_id' => ['required', Rule::exists('finance_accounts', 'id')->where('is_active', true)], 'amount' => ['required', 'numeric', 'min:.01'], 'paid_on' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:1000']]);
        DB::transaction(function () use ($data, $payableInvoice, $request): void {
            $invoice = PayableInvoice::query()->lockForUpdate()->findOrFail($payableInvoice->id);
            $remaining = (float) $invoice->amount - (float) $invoice->paid_amount;
            abort_if((float) $data['amount'] > $remaining + .01, 422, 'El abono no puede exceder el saldo pendiente.');
            $payment = $invoice->payments()->create([...$data, 'created_by' => $request->user()->id]);
            FinanceTransaction::query()->create(['finance_account_id' => $data['finance_account_id'], 'type' => 'expense', 'direction' => 'out', 'concept' => 'Abono proveedor '.$invoice->purchaseOrder->supplier->name, 'amount' => $data['amount'], 'occurred_on' => $data['paid_on'], 'reference' => $data['reference'] ?? $invoice->invoice_reference, 'notes' => $data['notes'] ?? null, 'source_type' => $payment::class, 'source_id' => $payment->id, 'created_by' => $request->user()->id]);
            $paid = (float) $invoice->paid_amount + (float) $data['amount'];
            $invoice->update(['paid_amount' => $paid, 'status' => $paid >= (float) $invoice->amount - .01 ? 'paid' : 'partial']);
        });

        return back()->with('success', 'Abono registrado y gasto reflejado en la cuenta seleccionada.');
    }
}
