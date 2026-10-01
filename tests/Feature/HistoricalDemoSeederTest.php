<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\FinanceAccount;
use App\Models\PayrollRun;
use App\Models\Ticket;
use Database\Seeders\CrepeSeeder;
use Database\Seeders\HistoricalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricalDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_demo_is_rich_and_idempotent_without_creating_accounts_or_registers(): void
    {
        $this->seed(CrepeSeeder::class);
        $this->seed(HistoricalDemoSeeder::class);

        $counts = [
            'customers' => Customer::query()->count(),
            'tickets' => Ticket::query()->count(),
            'payroll_runs' => PayrollRun::query()->count(),
            'accounts' => FinanceAccount::query()->count(),
            'registers' => CashRegister::query()->count(),
        ];

        $this->assertGreaterThanOrEqual(48, $counts['customers']);
        $this->assertGreaterThanOrEqual(100, $counts['tickets']);
        $this->assertSame(8, $counts['payroll_runs']);
        $this->assertSame(6, $counts['accounts']);
        $this->assertSame(2, $counts['registers']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'demo.dataset.historical.v2']);

        $this->seed(HistoricalDemoSeeder::class);

        $this->assertSame($counts['customers'], Customer::query()->count());
        $this->assertSame($counts['tickets'], Ticket::query()->count());
        $this->assertSame($counts['payroll_runs'], PayrollRun::query()->count());
        $this->assertSame($counts['accounts'], FinanceAccount::query()->count());
        $this->assertSame($counts['registers'], CashRegister::query()->count());
    }
}
