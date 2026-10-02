<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Appointment;
use App\Models\CommissionEntry;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FinanceTransaction;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportHistoricalAppointmentSalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_keeps_finances_untouched_and_assigns_ale_to_ale_torres(): void
    {
        User::factory()->create();
        $this->customer();
        $aleCocom = $this->employee('Ale', 'Cocom');
        $aleTorres = $this->employee('Ale', 'Torres');
        $this->employee('Pame', 'Real');
        $source = $this->sourceFile();

        try {
            $this->artisan('crepe:import-historical-appointment-sales', [
                'file' => $source,
                '--force' => true,
                '--allow-unreconciled' => true,
            ])->assertSuccessful();

            $ticket = Ticket::query()->where('code', '#11410')->firstOrFail();
            $this->assertSame('paid', $ticket->status);
            $this->assertSame(1, Appointment::query()->count());
            $this->assertSame(2, $ticket->items()->count());
            $this->assertSame(0, Payment::query()->count());
            $this->assertSame(0, FinanceTransaction::query()->count());
            $this->assertSame(2, CommissionEntry::query()->count());
            $this->assertDatabaseHas('commission_entries', ['ticket_id' => $ticket->id, 'employee_id' => $aleTorres->id, 'amount' => 120]);
            $this->assertDatabaseMissing('commission_entries', ['ticket_id' => $ticket->id, 'employee_id' => $aleCocom->id]);
        } finally {
            unlink($source);
        }
    }

    public function test_command_rejects_an_unreconciled_import_without_explicit_override(): void
    {
        User::factory()->create();
        $this->customer();
        $this->employee('Ale', 'Torres');
        $this->employee('Ale', 'Cocom');
        $this->employee('Pame', 'Real');
        $source = $this->sourceFile();

        try {
            $this->artisan('crepe:import-historical-appointment-sales', [
                'file' => $source,
                '--force' => true,
            ])->assertFailed();

            $this->assertSame(0, Ticket::query()->count());
            $this->assertSame(0, Appointment::query()->count());
        } finally {
            unlink($source);
        }
    }

    private function sourceFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crepe-appointments-');
        file_put_contents($path, implode(PHP_EOL, [
            'NO. VENTA,RECEPCIÓN,Columna1,CLIENTE,PRODUCTO/SERVICIO,CONCEPTO,TIPO COSTO,PRECIO,CREPERA 1,COMISIÓN 1,CREPERA 2, ,COSTO SIN COMISIÓN',
            '11410,1,9/24/2026,Clienta Real,SERVICIO,Corte de prueba,FIJO,"$600.00",ale,"$120.00",Pame,"$30.00","$0.00","$200.00"',
            '11410,1,9/24/2026,Clienta Real,PRODUCTO,Producto de prueba,FIJO,"$200.00",,"$0.00",,"$0.00","$100.00"',
        ]));

        return $path;
    }

    private function employee(string $firstName, string $lastName): Employee
    {
        return Employee::query()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'position' => 'Estilista',
            'is_bookable' => true,
            'status' => 'active',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::query()->create([
            'first_name' => 'Clienta',
            'last_name' => 'Real',
            'status' => 'active',
        ]);
    }
}
