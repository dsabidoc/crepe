<?php

namespace Tests\Feature;

use App\Models\CommissionEntry;
use App\Models\Employee;
use App\Models\FinanceAccount;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_generate_payroll_and_download_a_signed_receipt(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $employee = Employee::query()->where('email', 'ana@crepe.mx')->firstOrFail();
        $employee->update(['salary' => 700, 'salary_type' => 'weekly']);
        $ticket = Ticket::query()->firstOrFail();
        $ticket->update(['paid_at' => now()]);
        $ticketItem = TicketItem::query()->whereBelongsTo($ticket)->firstOrFail();
        $commission = CommissionEntry::query()->create(['ticket_id' => $ticket->id, 'ticket_item_id' => $ticketItem->id, 'employee_id' => $employee->id, 'type' => 'service', 'base_amount' => 500, 'rate_snapshot' => 20, 'amount' => 100, 'status' => 'pending']);

        $this->actingAs($administrator)->post(route('payroll.store'), ['period_starts_on' => now()->startOfWeek()->toDateString(), 'period_ends_on' => now()->endOfWeek()->toDateString()])->assertRedirect();

        $payrollRun = PayrollRun::query()->latest('id')->firstOrFail();
        $payrollItem = PayrollItem::query()->whereBelongsTo($payrollRun)->whereBelongsTo($employee)->firstOrFail();
        $this->assertSame('1360.00', $payrollItem->total);
        $this->assertDatabaseHas('commission_entries', ['id' => $commission->id, 'payroll_item_id' => $payrollItem->id, 'status' => 'settled']);

        $this->actingAs($administrator)->put(route('payroll.items.update', [$payrollRun, $payrollItem]), ['infonavit_deduction' => 50, 'other_deductions' => 25, 'tardiness_deduction' => 10])->assertRedirect();
        $this->assertSame('1275.00', $payrollItem->fresh()->total);
        $this->actingAs($administrator)->get(route('payroll.items.receipt', [$payrollRun, $payrollItem]))->assertOk()->assertHeader('content-type', 'application/pdf')->assertHeader('content-disposition');

        $this->actingAs($administrator)->from(route('payroll.index'))->post(route('payroll.store'), [
            'period_starts_on' => now()->startOfWeek()->toDateString(),
            'period_ends_on' => now()->endOfWeek()->toDateString(),
        ])->assertRedirect(route('payroll.index'))->assertSessionHasErrors('period_starts_on');

        $account = FinanceAccount::query()->where('is_active', true)->firstOrFail();
        $total = $payrollRun->items()->sum('total');
        $this->actingAs($administrator)->post(route('payroll.withdrawals.store', $payrollRun), [
            'finance_account_id' => $account->id,
            'amount' => $total,
            'occurred_on' => now()->toDateString(),
        ])->assertRedirect();
        $this->actingAs($administrator)->get(route('payroll.show', $payrollRun))
            ->assertOk()
            ->assertSee('Retiro completo')
            ->assertSee('$0.00');
        $this->actingAs($administrator)->from(route('payroll.show', $payrollRun))->post(route('payroll.withdrawals.store', $payrollRun), [
            'finance_account_id' => $account->id,
            'amount' => 1,
            'occurred_on' => now()->toDateString(),
        ])->assertRedirect(route('payroll.show', $payrollRun))->assertSessionHasErrors('amount');
    }

    public function test_reception_cannot_access_payroll(): void
    {
        $this->seed(CrepeSeeder::class);
        $reception = User::factory()->create();
        $reception->assignRole('Recepción');

        $this->actingAs($reception)->get(route('payroll.index'))->assertForbidden();
    }
}
