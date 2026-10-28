<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\PayableInvoice;
use App\Models\PayablePayment;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceCorrectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CrepeSeeder::class);
        $this->actingAs(User::where('email', 'hi@davidsabido.com')->firstOrFail());
    }

    private function expense(): FinanceTransaction
    {
        return FinanceTransaction::create(['finance_account_id' => FinanceAccount::where('name', 'C-Efectivo')->firstOrFail()->id, 'type' => 'expense', 'direction' => 'out', 'amount' => '6000', 'concept' => 'Robert Nómina', 'occurred_on' => today(), 'created_by' => auth()->id()]);
    }

    public function test_correction_replaces_6000_with_600_in_effective_expenses_without_deleting_original_or_adding_revenue(): void
    {
        $expense = $this->expense();
        $original = $expense->fresh()->toArray();
        $payload = ['action' => 'correct', 'amount' => '600', 'previous_amount' => '6000', 'reason' => 'Importe capturado por error', 'key' => (string) str()->uuid()];
        $this->get(route('finance.corrections.edit', $expense->id))->assertOk();
        $this->post(route('finance.corrections.store', $expense->id), $payload)->assertSessionHasNoErrors();
        $this->post(route('finance.corrections.store', $expense->id), $payload)->assertSessionHasNoErrors();
        $this->assertSame($original, FinanceTransaction::withoutGlobalScope('effective')->findOrFail($expense->id)->toArray());
        $this->assertSame(600.0, (float) FinanceTransaction::where('type', 'expense')->sum('amount'));
        $this->assertSame(0.0, (float) FinanceTransaction::where('type', 'income')->sum('amount'));
        $this->assertDatabaseCount('finance_transaction_corrections', 1);
        $this->get(route('finance.index', ['view' => 'movements']))->assertOk()->assertSee('Corregido')->assertSee('No afecta saldos');
    }

    public function test_voiding_preserves_original_and_removes_effect_on_balances(): void
    {
        $expense = $this->expense();
        $this->post(route('finance.corrections.store', $expense->id), ['action' => 'void', 'previous_amount' => 6000, 'reason' => 'Gasto inexistente', 'key' => (string) str()->uuid()])->assertSessionHasNoErrors();
        $this->assertSame(0, FinanceTransaction::count());
        $this->assertSame(1, FinanceTransaction::withoutGlobalScope('effective')->count());
        $this->assertDatabaseHas('finance_transactions', ['id' => $expense->id, 'amount' => 6000]);
    }

    public function test_correcting_supplier_payment_updates_invoice_and_effective_payment_history(): void
    {
        $supplier = Supplier::create(['name' => 'Proveedor prueba']);
        $order = PurchaseOrder::create(['code' => 'OC-CORRECTION', 'supplier_id' => $supplier->id]);
        $invoice = PayableInvoice::create(['purchase_order_id' => $order->id, 'amount' => 10000, 'due_on' => today()->addDays(10)]);
        $this->post(route('payables.payments.store', $invoice), ['finance_account_id' => FinanceAccount::firstOrFail()->id, 'amount' => 6000, 'paid_on' => today()->toDateString()])->assertSessionHasNoErrors();
        $transaction = FinanceTransaction::where('source_type', PayablePayment::class)->sole();
        $this->post(route('finance.corrections.store', $transaction->id), ['action' => 'correct', 'amount' => 600, 'previous_amount' => 6000, 'reason' => 'Abono correcto', 'key' => (string) str()->uuid()])->assertSessionHasNoErrors();
        $this->assertSame('600.00', $invoice->fresh()->paid_amount);
        $this->assertSame(600.0, (float) $invoice->payments()->sum('amount'));
        $this->assertSame(2, PayablePayment::withoutGlobalScope('effective')->count());
        $this->assertSame(600.0, (float) FinanceTransaction::where('source_type', PayablePayment::class)->sum('amount'));
    }

    public function test_non_admin_and_source_owned_transactions_cannot_be_corrected_directly(): void
    {
        $expense = $this->expense();
        $expense->update(['source_type' => Payment::class, 'source_id' => 999]);
        $payload = ['action' => 'void', 'previous_amount' => 6000, 'reason' => 'Cambio de prueba', 'key' => (string) str()->uuid()];
        $this->post(route('finance.corrections.store', $expense->id), $payload)->assertSessionHasErrors('transaction');
        $user = User::factory()->create();
        $user->assignRole('Almacén');
        $this->actingAs($user)->post(route('finance.corrections.store', $expense->id), $payload)->assertForbidden();
        $this->assertSame(1, FinanceTransaction::count());
        $this->assertDatabaseCount('finance_transaction_corrections', 0);
    }

    public function test_new_transfer_is_corrected_in_both_accounts_atomically(): void
    {
        $accounts = FinanceAccount::query()->take(2)->get();
        $this->post(route('finance.transactions.store'), ['finance_account_id' => $accounts[0]->id, 'transfer_to_account_id' => $accounts[1]->id, 'type' => 'transfer', 'direction' => 'out', 'amount' => 6000, 'concept' => 'Traspaso prueba', 'occurred_on' => today()->toDateString()])->assertSessionHasNoErrors();
        $transaction = FinanceTransaction::where('direction', 'out')->sole();
        $this->post(route('finance.corrections.store', $transaction->id), ['action' => 'correct', 'amount' => 600, 'previous_amount' => 6000, 'reason' => 'Traspaso correcto', 'key' => (string) str()->uuid()])->assertSessionHasNoErrors();
        $this->assertSame(2, FinanceTransaction::count());
        $this->assertSame(4, FinanceTransaction::withoutGlobalScope('effective')->count());
        $this->assertSame(600.0, (float) FinanceTransaction::where('direction', 'out')->sum('amount'));
        $this->assertSame(600.0, (float) FinanceTransaction::where('direction', 'in')->sum('amount'));
    }
}
