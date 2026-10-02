<?php

namespace App\Console\Commands;

use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FinanceAccount;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceTransaction;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\JobPosition;
use App\Models\PayableInvoice;
use App\Models\Product;
use App\Models\ProductCommissionRule;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetAndImportCurrentData extends Command
{
    protected $signature = 'crepe:reset-and-import-current-data
        {--customers= : Ruta del CSV de clientas}
        {--team= : Ruta del CSV de creperas y comisiones}
        {--payables= : Ruta del CSV de cuentas por pagar}
        {--movements= : Ruta del CSV de movimientos financieros}
        {--cash-cuts= : Ruta del CSV de movimientos de cajas}
        {--force : Confirma la sustitución de los datos operativos}';

    protected $description = 'Reemplaza los datos operativos con los CSV actuales, conservando el catálogo activo de productos';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Usa --force después de validar las rutas para reemplazar los datos operativos.');

            return self::FAILURE;
        }

        $paths = [
            'customers' => (string) $this->option('customers'),
            'team' => (string) $this->option('team'),
            'payables' => (string) $this->option('payables'),
            'movements' => (string) $this->option('movements'),
            'cash-cuts' => (string) $this->option('cash-cuts'),
        ];

        foreach ($paths as $label => $path) {
            if ($path === '' || ! is_readable($path)) {
                $this->error("No se puede leer el CSV de {$label}: {$path}");

                return self::FAILURE;
            }
        }

        $actorId = User::query()->orderBy('id')->value('id');
        if ($actorId === null) {
            $this->error('Se requiere al menos una cuenta de usuario para registrar la importación.');

            return self::FAILURE;
        }

        $summary = DB::transaction(function () use ($actorId, $paths): array {
            $this->clearOperationalData();
            [$cashAccount, $bankAccount] = $this->prepareRequiredAccounts();
            $this->removeInactiveProductsAndResetInventory();

            $team = $this->importTeam($paths['team']);
            $customers = $this->importCustomers($paths['customers']);
            $payables = $this->importPayables($paths['payables']);
            $finance = $this->importFinanceMovements($paths['movements'], $cashAccount, $bankAccount, $actorId);
            $cashSessions = $this->importCashSessions($paths['cash-cuts'], $actorId);

            $cashAccount->update(['initial_balance' => $finance['cash_initial_balance']]);
            $bankAccount->update(['initial_balance' => $finance['bank_initial_balance']]);

            return [
                'team' => $team,
                'customers' => $customers,
                'payables' => $payables,
                'finance' => $finance,
                'cash_sessions' => $cashSessions,
                'products' => Product::query()->where('status', 'active')->count(),
            ];
        }, attempts: 3);

        $this->info("Clientas importadas: {$summary['customers']}.");
        $this->info("Colaboradoras importadas: {$summary['team']['employees']}; reglas de comisión por producto: {$summary['team']['product_rules']}.");
        $this->info("Cuentas por pagar importadas: {$summary['payables']}.");
        $this->info("Movimientos financieros importados: {$summary['finance']['transactions']}.");
        $this->info("Cortes históricos importados: {$summary['cash_sessions']}.");
        $this->info("Productos activos conservados: {$summary['products']}.");

        return self::SUCCESS;
    }

    private function clearOperationalData(): void
    {
        foreach ([
            'payable_payments', 'payable_invoices', 'purchase_order_items', 'purchase_orders',
            'payroll_items', 'payroll_runs', 'commission_entries', 'payments', 'ticket_adjustments', 'color_formula_items', 'color_formulas',
            'ticket_items', 'tickets', 'appointment_services', 'appointments',
            'inventory_request_items', 'inventory_requests', 'inventory_movements', 'inventory_balances',
            'finance_transactions', 'cash_sessions', 'employee_time_blocks', 'employee_schedules',
            'audit_logs', 'customers', 'employees', 'promotions', 'product_commission_rules', 'finance_expense_categories',
            'salon_service_prices', 'salon_services', 'service_categories', 'job_positions',
        ] as $table) {
            DB::table($table)->delete();
        }
    }

    /**
     * @return array{0: FinanceAccount, 1: FinanceAccount}
     */
    private function prepareRequiredAccounts(): array
    {
        $cashAccount = FinanceAccount::query()
            ->whereIn('name', ['Caja Admon', 'C-Efectivo'])
            ->orderByRaw("name = 'Caja Admon' desc")
            ->firstOrFail();
        $bankAccount = FinanceAccount::query()
            ->whereIn('name', ['Bancomer 1', 'C-Bancomer'])
            ->orderByRaw("name = 'Bancomer 1' desc")
            ->firstOrFail();
        $registerAccountIds = CashRegister::query()->whereNotNull('finance_account_id')->pluck('finance_account_id')->all();
        $preservedAccountIds = array_unique([...$registerAccountIds, $cashAccount->id, $bankAccount->id]);

        FinanceAccount::query()->whereNotIn('id', $preservedAccountIds)->delete();
        FinanceAccount::query()->whereIn('id', $preservedAccountIds)->update(['initial_balance' => 0, 'is_active' => true]);
        FinanceAccount::query()->where('id', '!=', $cashAccount->id)->where('type', 'cash')->update(['is_primary' => false]);
        FinanceAccount::query()->where('id', '!=', $bankAccount->id)->where('type', 'bank')->update(['is_primary' => false]);
        $cashAccount->update(['name' => 'Caja Admon', 'type' => 'cash', 'is_primary' => true]);
        $bankAccount->update(['name' => 'Bancomer 1', 'type' => 'bank', 'is_primary' => true]);

        return [$cashAccount->fresh(), $bankAccount->fresh()];
    }

    private function removeInactiveProductsAndResetInventory(): void
    {
        Product::query()->where('status', '!=', 'active')->delete();
        $warehouse = InventoryLocation::query()->where('code', 'ALM')->firstOrFail();

        ProductVariant::query()->whereHas('product', fn ($query) => $query->where('status', 'active'))
            ->select('id')
            ->orderBy('id')
            ->chunkById(250, function ($variants) use ($warehouse): void {
                foreach ($variants as $variant) {
                    InventoryBalance::query()->create([
                        'inventory_location_id' => $warehouse->id,
                        'product_variant_id' => $variant->id,
                        'available_quantity' => 0,
                    ]);
                }
            });
    }

    /**
     * @return array{employees: int, product_rules: int}
     */
    private function importTeam(string $path): array
    {
        $rows = $this->csvRows($path);
        $positions = [];
        $employees = [];
        $sellingPositions = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row[1] ?? ''));
            $position = trim((string) ($row[2] ?? ''));
            if ($name === '' || $position === '' || ! ctype_digit(trim((string) ($row[0] ?? '')))) {
                continue;
            }

            $positions[$position] = true;
            [$firstName, $lastName] = $this->splitName($name);
            $sellsProducts = $this->sourceAffirmative((string) ($row[5] ?? ''));
            $serviceCommission = $this->percentage((string) ($row[6] ?? ''));
            $notes = [
                'Alta IMSS: '.trim((string) ($row[3] ?? 'No especificado')),
                'Vende productos: '.($sellsProducts ? 'Sí' : 'No'),
            ];
            if (trim((string) ($row[7] ?? '')) !== '') {
                $notes[] = trim((string) $row[7]);
            }
            $employees[] = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'position' => $position,
                'hired_at' => null,
                'salary' => $this->money((string) ($row[4] ?? '')),
                'salary_type' => 'weekly',
                'commission_rate' => $serviceCommission,
                'product_commission_rate' => null,
                'is_bookable' => $this->isBookablePosition($position, $serviceCommission),
                'status' => 'active',
                'notes' => implode(PHP_EOL, $notes),
            ];
            if ($sellsProducts) {
                $sellingPositions[$position] = true;
            }
        }

        foreach (array_keys($positions) as $index => $position) {
            JobPosition::query()->create(['name' => $position, 'is_active' => true, 'sort_order' => $index + 1]);
        }
        foreach ($employees as $employee) {
            Employee::query()->create($employee);
        }

        $rules = [
            [1, 5, 5],
            [6, 15, 10],
            [16, null, 20],
        ];
        foreach ($rules as [$minimum, $maximum, $rate]) {
            ProductCommissionRule::query()->create([
                'minimum_sales' => $minimum,
                'maximum_sales' => $maximum,
                'commission_rate' => $rate,
                'positions' => array_keys($sellingPositions),
                'is_active' => true,
            ]);
        }

        return ['employees' => count($employees), 'product_rules' => count($rules)];
    }

    private function importCustomers(string $path): int
    {
        $count = 0;
        foreach ($this->csvRows($path) as $row) {
            $fullName = trim((string) ($row[1] ?? ''));
            if ($fullName === '') {
                continue;
            }
            [$firstName, $lastName] = $this->splitName($fullName);
            Customer::query()->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'notes' => 'Folio histórico: '.trim((string) ($row[0] ?? '')),
                'status' => 'active',
            ]);
            $count++;
        }

        return $count;
    }

    private function importPayables(string $path): int
    {
        $supplierName = null;
        $count = 0;
        foreach ($this->csvRows($path) as $index => $row) {
            $candidateSupplier = trim((string) ($row[0] ?? ''));
            if ($candidateSupplier !== '' && ! in_array(mb_strtoupper($candidateSupplier), ['TOTALES', 'SALDOS A PROVEEDORES'], true) && ! str_contains(mb_strtolower($candidateSupplier), 'octubre de')) {
                $supplierName = $candidateSupplier;
            }
            $amount = $this->money((string) ($row[7] ?? '')) ?: $this->money((string) ($row[5] ?? ''));
            if ($supplierName === null || $amount <= 0 || trim((string) ($row[2] ?? '')) === '') {
                continue;
            }

            $issuedOn = $this->date((string) $row[2], 2026);
            if ($issuedOn === null) {
                continue;
            }
            $dueOn = $this->date((string) ($row[3] ?? ''), $issuedOn->year) ?? $issuedOn->copy();
            if ($dueOn->month < $issuedOn->month) {
                $dueOn->addYear();
            }
            $supplier = Supplier::query()->firstOrCreate(['name' => mb_strtoupper($supplierName)], ['payment_grace_days' => 0]);
            $purchaseOrder = PurchaseOrder::query()->create([
                'code' => 'ODC-IMP-'.str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT),
                'supplier_id' => $supplier->id,
                'status' => 'received',
                'notes' => 'Importado desde el saldo histórico de proveedores.',
                'received_at' => $issuedOn->copy()->setTime(12, 0),
            ]);
            PayableInvoice::query()->create([
                'purchase_order_id' => $purchaseOrder->id,
                'invoice_reference' => trim((string) ($row[1] ?? '')) ?: null,
                'amount' => $amount,
                'paid_amount' => 0,
                'due_on' => $dueOn,
                'status' => 'pending',
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @return array{transactions: int, cash_initial_balance: float, bank_initial_balance: float}
     */
    private function importFinanceMovements(string $path, FinanceAccount $cashAccount, FinanceAccount $bankAccount, int $actorId): array
    {
        $totals = ['cash' => 0.0, 'bank' => 0.0];
        $count = 0;
        foreach ($this->csvRows($path) as $row) {
            $direction = mb_strtoupper(trim((string) ($row[2] ?? '')));
            $account = match (mb_strtolower(trim((string) ($row[5] ?? '')))) {
                'caja admon' => $cashAccount,
                'bancomer 1' => $bankAccount,
                default => null,
            };
            $amount = $this->money((string) ($row[9] ?? ''));
            $occurredOn = $this->date((string) ($row[1] ?? ''), 2026);
            if ($account === null || $occurredOn === null || $amount <= 0 || ! in_array($direction, ['INGRESO', 'EGRESO'], true)) {
                continue;
            }
            $type = $direction === 'INGRESO' ? 'income' : 'expense';
            $categoryName = trim((string) ($row[3] ?? '')).': '.trim((string) ($row[4] ?? ''));
            $category = $type === 'expense'
                ? FinanceExpenseCategory::query()->firstOrCreate(['name' => trim($categoryName, ': ')], ['is_active' => true, 'created_by' => $actorId])
                : null;
            FinanceTransaction::query()->create([
                'finance_account_id' => $account->id,
                'finance_expense_category_id' => $category?->id,
                'type' => $type,
                'direction' => $type === 'income' ? 'in' : 'out',
                'concept' => trim((string) ($row[8] ?? '')) ?: trim((string) ($row[4] ?? '')),
                'amount' => $amount,
                'occurred_on' => $occurredOn,
                'reference' => 'Movimiento fuente '.trim((string) ($row[0] ?? '')),
                'notes' => $this->financeNotes($row),
                'created_by' => $actorId,
            ]);
            $key = $account->id === $cashAccount->id ? 'cash' : 'bank';
            $totals[$key] += $type === 'income' ? $amount : -$amount;
            $count++;
        }

        $lastCashCut = $this->lastCashCutTotals();

        return [
            'transactions' => $count,
            'cash_initial_balance' => round($lastCashCut['cash'] - $totals['cash'], 2),
            'bank_initial_balance' => round($lastCashCut['bank'] - $totals['bank'], 2),
        ];
    }

    private function importCashSessions(string $path, int $actorId): int
    {
        $registers = CashRegister::query()->where('is_active', true)->orderBy('code')->get();
        if ($registers->count() !== 2) {
            throw new \RuntimeException('La importación requiere exactamente las dos cajas activas existentes.');
        }

        $count = 0;
        foreach ($this->csvRows($path) as $row) {
            $businessDate = $this->date((string) ($row[0] ?? ''), 2026);
            if ($businessDate === null) {
                continue;
            }
            $amounts = [
                'cash' => $this->money((string) ($row[1] ?? '')),
                'card' => $this->money((string) ($row[2] ?? '')),
                'transfer' => $this->money((string) ($row[3] ?? '')),
                'cash_expense' => $this->money((string) ($row[5] ?? '')),
                'bank_expense' => $this->money((string) ($row[6] ?? '')),
                'cash_balance' => $this->money((string) ($row[8] ?? '')),
                'bank_balance' => $this->money((string) ($row[9] ?? '')),
            ];
            if (array_sum(array_slice($amounts, 0, 5)) <= 0) {
                continue;
            }
            $split = [];
            foreach ($amounts as $key => $amount) {
                $split[$key] = $this->splitAmount($amount);
            }
            foreach ($registers->values() as $index => $register) {
                CashSession::query()->create([
                    'cash_register_id' => $register->id,
                    'business_date' => $businessDate,
                    'opened_by' => $actorId,
                    'opened_at' => $businessDate->copy()->setTime(9, 0),
                    'opening_float' => 0,
                    'expected_cash' => $split['cash'][$index],
                    'expected_card' => $split['card'][$index],
                    'expected_transfer' => $split['transfer'][$index],
                    'expected_gift_card' => 0,
                    'expected_other' => 0,
                    'actual_cash' => $split['cash'][$index],
                    'actual_card' => $split['card'][$index],
                    'actual_transfer' => $split['transfer'][$index],
                    'actual_gift_card' => 0,
                    'actual_other' => 0,
                    'actual_change' => 0,
                    'cashier_notes' => sprintf(
                        'Importado. Gastos efectivo: $%s. Gastos banco: $%s. Saldos consolidados al cierre: efectivo $%s; bancario $%s.',
                        number_format($split['cash_expense'][$index], 2),
                        number_format($split['bank_expense'][$index], 2),
                        number_format($amounts['cash_balance'], 2),
                        number_format($amounts['bank_balance'], 2),
                    ),
                    'closed_at' => $businessDate->copy()->setTime(20, 0),
                    'closed_by' => $actorId,
                    'difference' => 0,
                    'status' => 'verified',
                    'verified_at' => $businessDate->copy()->setTime(20, 5),
                    'verified_by' => $actorId,
                    'verification_notes' => 'Corte histórico importado y distribuido en partes iguales entre las dos cajas.',
                ]);
                $count++;
            }
        }

        return $count;
    }

    /** @return array{cash: float, bank: float} */
    private function lastCashCutTotals(): array
    {
        $rows = $this->csvRows((string) $this->option('cash-cuts'));
        $last = null;
        foreach ($rows as $row) {
            if ($this->date((string) ($row[0] ?? ''), 2026) !== null) {
                $last = $row;
            }
        }
        if ($last === null) {
            throw new \RuntimeException('El CSV de cajas no contiene saldos de cierre válidos.');
        }

        return ['cash' => $this->money((string) ($last[8] ?? '')), 'bank' => $this->money((string) ($last[9] ?? ''))];
    }

    /** @return array<int, array<int, string|null>> */
    private function csvRows(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("No se puede abrir {$path}.");
        }
        fgetcsv($handle, 0, ',', '"', '\\');
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count(array_filter($row, fn ($value): bool => trim((string) $value) !== '')) > 0) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        $firstName = array_shift($parts) ?: $fullName;

        return [$firstName, implode(' ', $parts)];
    }

    private function sourceAffirmative(string $value): bool
    {
        return in_array(mb_strtoupper(trim($value)), ['SÍ', 'SI'], true);
    }

    private function isBookablePosition(string $position, ?float $commissionRate): bool
    {
        return str_contains(mb_strtolower($position), 'estilista') || ($commissionRate !== null && $commissionRate > 0);
    }

    private function percentage(string $value): ?float
    {
        $value = trim($value);
        if ($value === '' || mb_strtoupper($value) === 'NA') {
            return null;
        }

        return (float) str_replace('%', '', str_replace(',', '.', $value));
    }

    private function money(string $value): float
    {
        $normalized = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $value)) ?? '';

        return $normalized === '' || $normalized === '-' ? 0.0 : (float) $normalized;
    }

    private function date(string $value, int $defaultYear): ?Carbon
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $matches) === 1) {
            return Carbon::create((int) $matches[3], (int) $matches[1], (int) $matches[2])->startOfDay();
        }
        if (preg_match('/^(\d{1,2})-([[:alpha:]áéíóúñ]+)(?:-(\d{2,4}))?$/u', $value, $matches) !== 1) {
            return null;
        }
        $months = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12];
        $month = $months[$matches[2]] ?? null;
        if ($month === null) {
            return null;
        }
        $year = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : $defaultYear;
        if ($year < 100) {
            $year += 2000;
        }

        return Carbon::create($year, $month, (int) $matches[1])->startOfDay();
    }

    /** @return array{0: float, 1: float} */
    private function splitAmount(float $amount): array
    {
        $first = round($amount / 2, 2);

        return [$first, round($amount - $first, 2)];
    }

    /** @param array<int, string|null> $row */
    private function financeNotes(array $row): string
    {
        return collect([
            trim((string) ($row[3] ?? '')) !== '' ? 'Categoría: '.trim((string) $row[3]) : null,
            trim((string) ($row[4] ?? '')) !== '' ? 'Subcategoría: '.trim((string) $row[4]) : null,
            trim((string) ($row[6] ?? '')) !== '' ? 'Tipo de pago: '.trim((string) $row[6]) : null,
            trim((string) ($row[7] ?? '')) !== '' ? 'Proveedor: '.trim((string) $row[7]) : null,
        ])->filter()->implode(PHP_EOL);
    }
}
