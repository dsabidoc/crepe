<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\CommissionEntry;
use App\Models\Employee;
use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_generate_payroll_and_download_a_signed_receipt(): void
    {
        Storage::fake('public');
        $this->seed(CrepeSeeder::class);
        AppSetting::put('attendance.tracking_starts_on', now()->addDay()->toDateString());
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
        $this->assertSame(1, $payrollRun->payroll_number);
        $this->assertDatabaseHas('commission_entries', ['id' => $commission->id, 'payroll_item_id' => $payrollItem->id, 'status' => 'settled']);

        $this->actingAs($administrator)->put(route('payroll.discounts.update', $payrollRun), [
            'payroll_item_id' => $payrollItem->id,
            'discount_type' => 'other_deductions',
            'amount' => 25,
        ])->assertRedirect();
        $this->assertSame('1335.00', $payrollItem->fresh()->total);

        $this->actingAs($administrator)->put(route('payroll.items.update', [$payrollRun, $payrollItem]), ['infonavit_deduction' => 50, 'other_deductions' => 25, 'tardiness_deduction' => 10])->assertRedirect();
        $this->assertSame('1275.00', $payrollItem->fresh()->total);
        $receipt = $this->actingAs($administrator)->get(route('payroll.items.receipt', [$payrollRun, $payrollItem]));
        $receipt->assertOk()->assertHeader('content-type', 'application/pdf')->assertHeader('content-disposition');
        $this->assertStringContainsString('recibo-nomina-01-ana-torres-'.$payrollRun->period_ends_on->format('Ymd').'.pdf', (string) $receipt->headers->get('content-disposition'));
        $this->assertStringContainsString('NOMINA 01', $receipt->getContent());
        $this->assertStringContainsString('Periodo:', $receipt->getContent());

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
            'evidence' => UploadedFile::fake()->image('nomina.png'),
        ])->assertRedirect();
        $withdrawal = FinanceTransaction::query()->where('source_id', $payrollRun->id)->where('source_type', PayrollRun::class)->latest('id')->first();
        $this->assertNotNull($withdrawal?->evidence_path);
        Storage::disk('public')->assertExists($withdrawal->evidence_path);
        $this->actingAs($administrator)->get(route('payroll.show', $payrollRun))
            ->assertOk()
            ->assertSee('Retiro completo')
            ->assertSee('$0.00')
            ->assertSee('Agregar descuento');
        $this->actingAs($administrator)->from(route('payroll.show', $payrollRun))->post(route('payroll.withdrawals.store', $payrollRun), [
            'finance_account_id' => $account->id,
            'amount' => 1,
            'occurred_on' => now()->toDateString(),
        ])->assertRedirect(route('payroll.show', $payrollRun))->assertSessionHasErrors('amount');
    }

    public function test_reception_cannot_access_payroll(): void
    {
        $this->seed(CrepeSeeder::class);
        AppSetting::put('attendance.tracking_starts_on', now()->addDay()->toDateString());
        $reception = User::factory()->create();
        $reception->assignRole('Recepción');

        $this->actingAs($reception)->get(route('payroll.index'))->assertForbidden();
    }

    public function test_administrator_can_delete_a_generated_payroll_and_release_commissions(): void
    {
        Storage::fake('public');
        $this->seed(CrepeSeeder::class);
        AppSetting::put('attendance.tracking_starts_on', now()->addDay()->toDateString());
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $employee = Employee::query()->where('email', 'ana@crepe.mx')->firstOrFail();
        $employee->update(['salary' => 700, 'salary_type' => 'weekly']);
        $ticket = Ticket::query()->firstOrFail();
        $ticket->update(['paid_at' => now()]);
        $ticketItem = TicketItem::query()->whereBelongsTo($ticket)->firstOrFail();
        $commission = CommissionEntry::query()->create([
            'ticket_id' => $ticket->id,
            'ticket_item_id' => $ticketItem->id,
            'employee_id' => $employee->id,
            'type' => 'service',
            'base_amount' => 500,
            'rate_snapshot' => 20,
            'amount' => 100,
            'status' => 'pending',
        ]);

        $this->actingAs($administrator)->post(route('payroll.store'), [
            'period_starts_on' => now()->startOfWeek()->toDateString(),
            'period_ends_on' => now()->endOfWeek()->toDateString(),
        ])->assertRedirect();

        $payrollRun = PayrollRun::query()->latest('id')->firstOrFail();
        $payrollItem = PayrollItem::query()->whereBelongsTo($payrollRun)->whereBelongsTo($employee)->firstOrFail();
        $this->assertDatabaseHas('commission_entries', ['id' => $commission->id, 'payroll_item_id' => $payrollItem->id, 'status' => 'settled']);
        $account = FinanceAccount::query()->where('is_active', true)->firstOrFail();
        $evidencePath = 'payroll-withdrawals/delete-test.png';
        Storage::disk('public')->put($evidencePath, 'evidence');
        $withdrawal = FinanceTransaction::query()->create([
            'finance_account_id' => $account->id,
            'type' => 'expense',
            'direction' => 'out',
            'concept' => 'Retiro de nómina',
            'amount' => 100,
            'occurred_on' => now()->toDateString(),
            'evidence_path' => $evidencePath,
            'source_type' => PayrollRun::class,
            'source_id' => $payrollRun->id,
            'created_by' => $administrator->id,
        ]);

        $this->actingAs($administrator)
            ->delete(route('payroll.destroy', $payrollRun))
            ->assertRedirect(route('payroll.index'))
            ->assertSessionHas('success', 'Nómina eliminada. Las comisiones quedaron disponibles para generar una nueva nómina.');

        $this->assertDatabaseMissing('payroll_runs', ['id' => $payrollRun->id]);
        $this->assertDatabaseMissing('payroll_items', ['id' => $payrollItem->id]);
        $this->assertDatabaseMissing('finance_transactions', ['id' => $withdrawal->id]);
        Storage::disk('public')->assertMissing($evidencePath);
        $this->assertDatabaseHas('commission_entries', ['id' => $commission->id, 'payroll_item_id' => null, 'status' => 'pending']);
    }
}
