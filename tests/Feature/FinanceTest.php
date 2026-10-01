<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_view_finance_and_record_a_manual_movement(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $account = FinanceAccount::query()->where('name', 'C-Efectivo')->firstOrFail();

        $this->actingAs($user)->get(route('finance.index'))->assertOk()->assertSee('Resumen financiero');
        $this->actingAs($user)->get(route('finance.index', ['view' => 'accounts']))
            ->assertOk()
            ->assertSee('C-Efectivo')
            ->assertSee('Ingresos hoy')
            ->assertSee('Gastos hoy');

        $this->actingAs($user)->post(route('finance.transactions.store'), [
            'finance_account_id' => $account->id,
            'type' => 'income',
            'concept' => 'Ingreso extraordinario',
            'amount' => '125.50',
            'occurred_on' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('finance_transactions', ['concept' => 'Ingreso extraordinario', 'amount' => 125.50]);
    }

    public function test_non_administrator_cannot_view_finance(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Recepción');

        $this->actingAs($user)->get(route('finance.index'))->assertForbidden();
    }

    public function test_administrator_can_attach_a_receipt_to_a_manual_movement(): void
    {
        Storage::fake('public');
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $account = FinanceAccount::query()->where('name', 'C-Efectivo')->firstOrFail();

        $this->actingAs($user)->post(route('finance.transactions.store'), [
            'finance_account_id' => $account->id,
            'type' => 'income',
            'concept' => 'Ingreso con comprobante',
            'amount' => '320.00',
            'occurred_on' => now()->toDateString(),
            'evidence' => UploadedFile::fake()->image('comprobante.png'),
        ])->assertRedirect();

        $path = (string) FinanceTransaction::query()->where('concept', 'Ingreso con comprobante')->value('evidence_path');
        $this->assertNotSame('', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_finance_dashboard_attributes_product_sales_to_the_selected_recommending_crepera(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $customer = Customer::query()->firstOrFail();
        $recommendingEmployee = Employee::query()->create([
            'first_name' => 'Marina',
            'last_name' => 'Vende',
            'email' => 'marina.vende@example.test',
            'position' => 'Crepera',
            'status' => 'active',
        ]);
        $ticket = Ticket::query()->create([
            'code' => '#PRODUCT-RECOMMENDER',
            'customer_id' => $customer->id,
            'ticket_type' => 'product_sale',
            'status' => 'paid',
            'estimated_total' => 360,
            'opened_at' => now(),
            'paid_at' => now(),
        ]);
        TicketItem::query()->create([
            'ticket_id' => $ticket->id,
            'type' => 'product',
            'name_snapshot' => 'Tratamiento recomendado',
            'quantity' => 1,
            'unit' => 'pieza',
            'unit_price' => 360,
            'line_total' => 360,
            'status' => 'active',
            'metadata' => ['employee_id' => $recommendingEmployee->id],
        ]);

        $this->actingAs($user)
            ->get(route('finance.index'))
            ->assertOk()
            ->assertSee('Marina Vende');
    }
}
