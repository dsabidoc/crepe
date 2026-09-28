<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->actingAs($user)->get(route('finance.index', ['view' => 'accounts']))->assertOk()->assertSee('C-Efectivo');

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
}
