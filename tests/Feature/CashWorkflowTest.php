<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\FinanceAccount;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceTransaction;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CashCutService;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CashWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_receptionist_is_prompted_to_start_the_day_and_can_open_a_cash_register(): void
    {
        $this->seed(CrepeSeeder::class);
        CashSession::query()->update(['business_date' => now()->subDay()->toDateString()]);
        $receptionist = User::factory()->create();
        $receptionist->assignRole('Recepción');

        $this->actingAs($receptionist)
            ->withSession(['crepe.mode' => 'recepcion'])
            ->get(route('workspace', 'recepcion'))
            ->assertOk()
            ->assertSee('Comenzar mi día')
            ->assertSee('1250.00');

        $this->actingAs($receptionist)
            ->post(route('cash.sessions.start-day'), ['opening_float' => 1100])
            ->assertRedirect(route('workspace', 'recepcion'));

        $this->assertDatabaseHas('cash_sessions', [
            'opened_by' => $receptionist->id,
            'opening_float' => 1100,
            'status' => 'open',
        ]);
        $cashSession = CashSession::query()->where('opened_by', $receptionist->id)->firstOrFail();
        $centralCashAccount = FinanceAccount::query()->where('name', 'C-Efectivo')->firstOrFail();
        $this->assertDatabaseHas('finance_transactions', [
            'finance_account_id' => $centralCashAccount->id,
            'transfer_to_account_id' => $cashSession->register->finance_account_id,
            'type' => 'transfer',
            'direction' => 'out',
            'amount' => 1100,
        ]);
    }

    public function test_administrator_can_open_a_specific_cash_register_with_a_custom_float(): void
    {
        $this->seed(CrepeSeeder::class);
        CashSession::query()->update(['business_date' => now()->subDay()->toDateString()]);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $register = CashRegister::query()->where('code', 'REC-01')->firstOrFail();

        $this->actingAs($administrator)
            ->post(route('cash.sessions.store'), [
                'cash_register_id' => $register->id,
                'opening_float' => 875,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('cash_sessions', [
            'cash_register_id' => $register->id,
            'opening_float' => 875,
            'status' => 'open',
        ]);
    }

    public function test_reception_payment_is_sent_to_the_cashier_open_cash_register_automatically(): void
    {
        $this->seed(CrepeSeeder::class);
        CashSession::query()->update(['business_date' => now()->subDay()->toDateString()]);
        $receptionist = User::factory()->create();
        $receptionist->assignRole('Recepción');
        $ticket = Ticket::query()->where('status', 'open')->firstOrFail();

        $this->actingAs($receptionist)
            ->post(route('cash.sessions.start-day'), ['opening_float' => 1250])
            ->assertSessionHasNoErrors();

        $cashSession = CashSession::query()
            ->where('opened_by', $receptionist->id)
            ->whereDate('business_date', now())
            ->firstOrFail();

        $this->actingAs($receptionist)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Se registrará en '.$cashSession->register->name)
            ->assertDontSee('Cuenta de ingreso')
            ->assertDontSee('Registrar en caja');

        $this->actingAs($receptionist)
            ->post(route('tickets.payments.store', $ticket), [
                'amount' => 100,
                'method' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $payment = Payment::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
        $this->assertSame($cashSession->id, $payment->cash_session_id);
        $this->assertSame($cashSession->register->finance_account_id, $payment->finance_account_id);
    }

    public function test_administrator_can_choose_the_cash_register_and_income_account_for_a_payment(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->where('status', 'open')->firstOrFail();

        $this->actingAs($administrator)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Cuenta de ingreso')
            ->assertSee('Registrar en caja');
    }

    public function test_receptionist_can_combine_gift_card_and_cash_payments_for_a_ticket(): void
    {
        $this->seed(CrepeSeeder::class);
        CashSession::query()->update(['business_date' => now()->subDay()->toDateString()]);
        $receptionist = User::factory()->create();
        $receptionist->assignRole('Recepción');
        $ticket = Ticket::query()->where('status', 'open')->firstOrFail();

        $this->actingAs($receptionist)
            ->post(route('cash.sessions.start-day'), ['opening_float' => 1250])
            ->assertSessionHasNoErrors();

        $cashSession = CashSession::query()
            ->where('opened_by', $receptionist->id)
            ->whereDate('business_date', now())
            ->firstOrFail();

        $this->actingAs($receptionist)
            ->post(route('tickets.payments.store', $ticket), [
                'amount' => 150,
                'method' => 'gift_card',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($receptionist)
            ->post(route('tickets.payments.store', $ticket), [
                'amount' => 200,
                'method' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('payments', [
            'ticket_id' => $ticket->id,
            'cash_session_id' => $cashSession->id,
            'method' => 'gift_card',
            'amount' => 150,
            'finance_account_id' => null,
        ]);
        $this->assertDatabaseHas('payments', [
            'ticket_id' => $ticket->id,
            'cash_session_id' => $cashSession->id,
            'method' => 'cash',
            'amount' => 200,
        ]);

        $expected = app(CashCutService::class)->expectedAmounts($cashSession);
        $this->assertSame(150.0, $expected['gift_card']);
        $this->assertSame(200.0, $expected['cash']);

        $this->actingAs($receptionist)
            ->post(route('cash.cuts.store', $cashSession), [
                'actual_card' => 0,
                'actual_cash' => 200,
                'actual_change' => 1250,
                'actual_gift_card' => 150,
                'actual_other' => 0,
                'actual_transfer' => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cash_sessions', [
            'id' => $cashSession->id,
            'expected_gift_card' => 150,
            'actual_gift_card' => 150,
            'difference' => 0,
        ]);

        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $this->actingAs($administrator)
            ->post(route('cash.cuts.confirm', $cashSession))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('finance_transactions', [
            'source_type' => CashSession::class,
            'source_id' => $cashSession->id,
            'type' => 'transfer',
            'amount' => 150,
        ]);
    }

    public function test_receptionist_registers_income_and_expense_in_her_open_cash_register(): void
    {
        $this->seed(CrepeSeeder::class);
        CashSession::query()->update(['business_date' => now()->subDay()->toDateString()]);
        Storage::fake('public');
        $receptionist = User::factory()->create();
        $receptionist->assignRole('Recepción');
        $category = FinanceExpenseCategory::query()->where('name', 'Compras')->firstOrFail();

        $this->actingAs($receptionist)
            ->post(route('cash.sessions.start-day'), ['opening_float' => 1250]);
        $cashSession = CashSession::query()->where('opened_by', $receptionist->id)->firstOrFail();

        $this->actingAs($receptionist)
            ->post(route('reception.cash-movements.store'), [
                'type' => 'expense',
                'finance_expense_category_id' => $category->id,
                'concept' => 'Compra urgente de insumos',
                'amount' => 100,
                'occurred_on' => now()->toDateString(),
                'reference' => 'TCK-102',
                'notes' => 'Compra autorizada por recepción.',
                'evidence' => UploadedFile::fake()->image('ticket.jpg'),
            ])
            ->assertRedirect(route('workspace', 'recepcion'));

        $this->actingAs($receptionist)
            ->post(route('reception.cash-movements.store'), [
                'type' => 'income',
                'concept' => 'Venta fuera de inventario',
                'amount' => 75,
                'occurred_on' => now()->toDateString(),
            ])
            ->assertRedirect(route('workspace', 'recepcion'));

        $expense = FinanceTransaction::query()->where('concept', 'Compra urgente de insumos')->firstOrFail();
        $this->assertSame($cashSession->id, $expense->cash_session_id);
        $this->assertSame($cashSession->register->finance_account_id, $expense->finance_account_id);
        $this->assertSame('expense', $expense->type);
        Storage::disk('public')->assertExists($expense->evidence_path);
        $this->assertDatabaseHas('finance_transactions', [
            'cash_session_id' => $cashSession->id,
            'type' => 'income',
            'concept' => 'Venta fuera de inventario',
            'amount' => 75,
        ]);

        $expected = app(CashCutService::class)->expectedAmounts($cashSession);
        $this->assertSame(1225.0, $expected['change']);

        $this->actingAs($receptionist)
            ->post(route('cash.cuts.store', $cashSession), [
                'actual_card' => 0,
                'actual_cash' => 0,
                'actual_change' => 1225,
                'actual_gift_card' => 0,
                'actual_other' => 0,
                'actual_transfer' => 0,
            ])
            ->assertSessionHasNoErrors();
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($administrator)
            ->post(route('cash.cuts.confirm', $cashSession))
            ->assertSessionHasNoErrors();

        $centralCashAccount = FinanceAccount::query()->where('name', 'C-Efectivo')->firstOrFail();
        $this->assertDatabaseHas('finance_transactions', [
            'finance_account_id' => $centralCashAccount->id,
            'type' => 'transfer',
            'direction' => 'in',
            'amount' => 1225,
        ]);
    }

    public function test_imported_verified_cash_cut_uses_its_saved_amount_snapshot(): void
    {
        $this->seed(CrepeSeeder::class);
        $register = CashRegister::query()->where('code', 'REC-01')->firstOrFail();
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $cashSession = CashSession::query()->create([
            'cash_register_id' => $register->id,
            'business_date' => now()->subMonth()->toDateString(),
            'opened_by' => $administrator->id,
            'opened_at' => now()->subMonth()->setTime(9, 0),
            'opening_float' => 0,
            'expected_cash' => 100,
            'expected_card' => 200,
            'expected_transfer' => 50,
            'expected_gift_card' => 0,
            'expected_other' => 0,
            'actual_cash' => 100,
            'actual_card' => 200,
            'actual_transfer' => 50,
            'actual_gift_card' => 0,
            'actual_other' => 0,
            'actual_change' => 0,
            'closed_at' => now()->subMonth()->setTime(20, 0),
            'closed_by' => $administrator->id,
            'difference' => 0,
            'status' => 'verified',
            'verified_at' => now()->subMonth()->setTime(20, 5),
            'verified_by' => $administrator->id,
        ]);

        $expected = app(CashCutService::class)->expectedAmounts($cashSession);

        $this->assertSame(100.0, $expected['cash']);
        $this->assertSame(200.0, $expected['card']);
        $this->assertSame(50.0, $expected['transfer']);
    }
}
