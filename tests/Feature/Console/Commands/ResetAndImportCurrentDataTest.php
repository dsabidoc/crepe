<?php

namespace Tests\Feature\Console\Commands;

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\PayableInvoice;
use App\Models\Product;
use App\Models\ProductCommissionRule;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetAndImportCurrentDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_replaces_operational_data_and_preserves_the_product_catalog(): void
    {
        $this->seed(CrepeSeeder::class);
        $paths = $this->sourceFiles();

        try {
            $this->artisan('crepe:reset-and-import-current-data', [
                '--force' => true,
                '--customers' => $paths['customers'],
                '--team' => $paths['team'],
                '--payables' => $paths['payables'],
                '--movements' => $paths['movements'],
                '--cash-cuts' => $paths['cash-cuts'],
            ])->assertSuccessful();

            $this->assertSame(1, Customer::query()->count());
            $this->assertSame(1, Employee::query()->count());
            $this->assertSame(3, ProductCommissionRule::query()->count());
            $this->assertSame(1, PayableInvoice::query()->count());
            $this->assertSame(1, FinanceTransaction::query()->count());
            $this->assertSame(2, CashSession::query()->count());
            $this->assertSame(3, Product::query()->where('status', 'active')->count());
            $this->assertSame('Caja Admon', FinanceAccount::query()->where('is_primary', true)->where('type', 'cash')->value('name'));
            $this->assertSame(50.0, (float) FinanceAccount::query()->where('name', 'Caja Admon')->value('initial_balance'));
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** @return array<string, string> */
    private function sourceFiles(): array
    {
        $files = [
            'customers' => [
                'FOLIO,CLIENTE',
                '1,Clienta Real',
            ],
            'team' => [
                ',CREPERAS,PUESTO,ALTA EN EL IMSS, SUELDO BASE SEMANAL, VENDE PRODUCTO,COMISIONES SERVICIOS,NOTAS',
                '1,Ana Real,Estilista,Sí,"$ 700",SÍ,20%,',
            ],
            'payables' => [
                'SALDOS A PROVEEDORES',
                '1 de octubre de 2026',
                ',FACTURA,FECHA,VENCE,FECHA PAGO,IMPORTE,PAGADO,SALDO,SALDO TOTAL',
                'Proveedor Uno,F-001,4-sep,4-oct,,"$ 125.50",$ -,"$ 125.50","$ 125.50"',
            ],
            'movements' => [
                'ID,FECHA PAGO,TIPO MOVIMIENTO,CATEGORÍA,SUBCATEGORÍA,CUENTA,TIPO PAGO,PROVEEDOR,CONCEPTO,MONTO',
                '1,18-sep-26,INGRESO,OTROS INGRESOS,VENTAS,Caja admon,EFECTIVO,,Venta histórica,"$ 50.00"',
            ],
            'cash-cuts' => [
                'Fecha,Ingresos efectivo,Ingresos tarjeta,Transferencia,Total,Gastos en,Gastos en,Total,Efectivo,Saldo,Total',
                ',,,,entradas,efectivo,banco,gastos,acumulado,bancario,disponible',
                '9/19/2026,"$ 40.00","$ 30.00","$ 10.00","$ 80.00","$ 5.00",$ -,"$ 5.00","$ 100.00","$ 200.00","$ 300.00"',
            ],
        ];

        return collect($files)->mapWithKeys(function (array $rows, string $name): array {
            $path = tempnam(sys_get_temp_dir(), "crepe-{$name}-");
            file_put_contents($path, implode(PHP_EOL, $rows));

            return [$name => $path];
        })->all();
    }
}
