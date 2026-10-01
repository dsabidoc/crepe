<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\PayableInvoice;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayablesWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_payment_reduces_payable_balance_and_records_an_expense(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $supplier = Supplier::query()->create(['name' => 'Proveedor CxP']);
        $order = PurchaseOrder::query()->create(['code' => 'OC-PRUEBA', 'supplier_id' => $supplier->id]);
        $invoice = PayableInvoice::query()->create(['purchase_order_id' => $order->id, 'amount' => 1000, 'due_on' => now()->addDays(30)]);
        $account = FinanceAccount::query()->where('name', 'C-Efectivo')->firstOrFail();

        $this->actingAs($administrator)->post(route('payables.payments.store', $invoice), ['finance_account_id' => $account->id, 'amount' => 400, 'paid_on' => now()->toDateString()])->assertRedirect();

        $this->assertDatabaseHas('payable_invoices', ['id' => $invoice->id, 'paid_amount' => 400, 'status' => 'partial']);
        $this->assertDatabaseHas('finance_transactions', ['finance_account_id' => $account->id, 'amount' => 400, 'type' => 'expense']);
    }

    public function test_reception_cannot_view_or_pay_supplier_invoices(): void
    {
        $this->seed(CrepeSeeder::class);
        $reception = User::factory()->create();
        $reception->assignRole('Recepción');

        $this->actingAs($reception)->get(route('payables.index'))->assertForbidden();
    }

    public function test_administrator_can_manage_purchase_order_items_and_download_the_order(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $supplier = Supplier::query()->create(['name' => 'Proveedor de órdenes']);
        $variants = ProductVariant::query()->take(2)->get();

        $this->actingAs($administrator)->post(route('purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_variant_id' => $variants[0]->id, 'ordered_quantity' => 4],
                ['product_variant_id' => $variants[1]->id, 'ordered_quantity' => 2],
            ],
        ])->assertRedirect();

        $order = PurchaseOrder::query()->whereBelongsTo($supplier)->firstOrFail();
        $firstItem = $order->items()->where('product_variant_id', $variants[0]->id)->firstOrFail();

        $this->put(route('purchase-orders.items.update', [$order, $firstItem]), ['ordered_quantity' => 6])->assertRedirect();
        $this->assertDatabaseHas('purchase_order_items', ['id' => $firstItem->id, 'ordered_quantity' => 6]);
        $this->get(route('purchase-orders.download', $order))->assertOk()->assertHeader('content-type', 'application/pdf');

        $secondItem = $order->items()->where('product_variant_id', $variants[1]->id)->firstOrFail();
        $this->delete(route('purchase-orders.items.destroy', [$order, $secondItem]))->assertRedirect();
        $this->assertDatabaseMissing('purchase_order_items', ['id' => $secondItem->id]);
    }
}
