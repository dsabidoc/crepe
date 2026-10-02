<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\CashSession;
use App\Models\CommissionEntry;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FinanceTransaction;
use App\Models\SalonService;
use App\Models\ServiceCategory;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ImportHistoricalAppointmentSales extends Command
{
    protected $signature = 'crepe:import-historical-appointment-sales
        {file : Ruta del CSV histórico de ventas}
        {--from=2026-09-24 : Fecha inicial a importar (YYYY-MM-DD)}
        {--to=2026-09-30 : Fecha final a importar (YYYY-MM-DD)}
        {--dry-run : Valida y muestra el resultado sin guardar datos}
        {--force : Confirma la importación de citas y tickets históricos}
        {--allow-unreconciled : Permite importar aun cuando el CSV no cuadre con los ingresos ya registrados}';

    protected $description = 'Importa citas, tickets y comisiones históricas sin crear pagos, movimientos ni afectar saldos financieros';

    /** @var array<string, string> */
    private array $employeeAliases = [
        'ale' => 'ale torres',
        'lupita mex' => 'lupis',
    ];

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path)) {
            $this->error("No se puede leer el archivo: {$path}");

            return self::FAILURE;
        }

        try {
            $from = Carbon::createFromFormat('Y-m-d', (string) $this->option('from'))->startOfDay();
            $to = Carbon::createFromFormat('Y-m-d', (string) $this->option('to'))->endOfDay();
        } catch (\Throwable) {
            $this->error('Las fechas --from y --to deben usar el formato YYYY-MM-DD.');

            return self::FAILURE;
        }

        if ($from->greaterThan($to)) {
            $this->error('La fecha inicial no puede ser posterior a la fecha final.');

            return self::FAILURE;
        }

        $actorId = User::query()->orderBy('id')->value('id');
        if ($actorId === null) {
            $this->error('Se requiere al menos una cuenta de usuario antes de importar.');

            return self::FAILURE;
        }

        try {
            $sales = $this->salesFromCsv($path, $from, $to);
            $employees = $this->employeesForImport();
            $this->validateSourceRecords($sales, $employees);
            $reconciliation = $this->reconciliationSummary($sales, $from, $to);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->displaySummary($sales, $reconciliation, $from, $to);

        if ($this->option('dry-run')) {
            $this->info('Validación terminada: no se guardó ningún dato.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->error('Usa --force después de revisar la validación para importar las citas y tickets históricos.');

            return self::FAILURE;
        }

        if (! $reconciliation['matches'] && ! $this->option('allow-unreconciled')) {
            $this->error('El CSV no cuadra con los ingresos registrados. No se importó nada; revisa el origen o usa --allow-unreconciled con autorización expresa.');

            return self::FAILURE;
        }

        try {
            $summary = DB::transaction(function () use ($sales, $employees, $actorId): array {
                $this->ensureNoTicketCodesExist($sales);

                $historicalCategory = ServiceCategory::query()->firstOrCreate(
                    ['name' => 'Histórico importado'],
                    ['color' => '#94a3b8', 'sort_order' => 999, 'is_active' => false],
                );
                $services = $this->historicalServices($sales, $historicalCategory);

                return $this->persistSales($sales, $employees, $services, $actorId);
            }, attempts: 3);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Importación completada: {$summary['appointments']} citas, {$summary['product_sales']} ventas de mostrador, {$summary['items']} partidas y {$summary['commissions']} comisiones.");
        $this->warn('No se crearon pagos, movimientos financieros, sesiones de caja ni ajustes de inventario. Los saldos financieros quedaron intactos.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array{number:string,reception:string,date:Carbon,customer:string|null,lines:Collection<int, array<string, mixed>>}>
     */
    private function salesFromCsv(string $path, Carbon $from, Carbon $to): Collection
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir el archivo: {$path}");
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '\\');
            if ($header === false) {
                throw new RuntimeException('El CSV está vacío.');
            }

            $columns = $this->sourceColumns($header);
            $sales = collect();
            $lineNumber = 1;

            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $lineNumber++;
                $saleNumber = trim((string) ($row[$columns['sale_number']] ?? ''));
                if ($saleNumber === '' || ! ctype_digit($saleNumber)) {
                    continue;
                }

                $dateValue = trim((string) ($row[$columns['date']] ?? ''));
                try {
                    $saleDate = Carbon::createFromFormat('!n/j/Y', $dateValue)->startOfDay();
                } catch (\Throwable) {
                    throw new RuntimeException("La fecha de la fila {$lineNumber} no es válida: {$dateValue}");
                }

                if ($saleDate->lt($from) || $saleDate->gt($to)) {
                    continue;
                }

                $type = mb_strtolower(trim((string) ($row[$columns['type']] ?? '')));
                if (! in_array($type, ['servicio', 'producto'], true)) {
                    throw new RuntimeException("El tipo de la fila {$lineNumber} debe ser SERVICIO o PRODUCTO.");
                }

                $concept = trim((string) ($row[$columns['concept']] ?? ''));
                if ($concept === '') {
                    throw new RuntimeException("La fila {$lineNumber} no tiene concepto.");
                }

                $line = [
                    'line_number' => $lineNumber,
                    'type' => $type,
                    'concept' => $concept,
                    'cost_type' => mb_strtolower(trim((string) ($row[$columns['cost_type']] ?? ''))),
                    'price' => $this->money((string) ($row[$columns['price']] ?? '')),
                    'cost' => $this->money((string) ($row[$columns['cost']] ?? '')),
                    'employee_one' => trim((string) ($row[$columns['employee_one']] ?? '')),
                    'commission_one' => $this->money((string) ($row[$columns['commission_one']] ?? '')),
                    'employee_two' => trim((string) ($row[$columns['employee_two']] ?? '')),
                    'commission_two' => $this->money((string) ($row[$columns['commission_two']] ?? '')),
                ];
                $customer = trim((string) ($row[$columns['customer']] ?? ''));
                $reception = trim((string) ($row[$columns['reception']] ?? ''));

                $existing = $sales->get($saleNumber);
                if ($existing === null) {
                    $sales->put($saleNumber, [
                        'number' => $saleNumber,
                        'reception' => $reception,
                        'date' => $saleDate,
                        'customer' => $customer === '' ? null : $customer,
                        'lines' => collect([$line]),
                    ]);

                    continue;
                }

                if ($existing['date']->ne($saleDate) || $existing['reception'] !== $reception || $existing['customer'] !== ($customer === '' ? null : $customer)) {
                    throw new RuntimeException("La venta {$saleNumber} tiene datos de encabezado inconsistentes en la fila {$lineNumber}.");
                }

                $existing['lines']->push($line);
                $sales->put($saleNumber, $existing);
            }
        } finally {
            fclose($handle);
        }

        if ($sales->isEmpty()) {
            throw new RuntimeException('No se encontraron ventas en el rango solicitado.');
        }

        return $sales->sortBy(fn (array $sale): string => $sale['date']->format('Y-m-d').str_pad($sale['number'], 12, '0', STR_PAD_LEFT))->values();
    }

    /**
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    private function sourceColumns(array $header): array
    {
        $header = array_map(fn (string $value): string => $this->normalize($value), $header);
        $required = [
            'sale_number' => 'no venta',
            'reception' => 'recepcion',
            'date' => 'columna1',
            'customer' => 'cliente',
            'type' => 'producto servicio',
            'concept' => 'concepto',
            'cost_type' => 'tipo costo',
            'price' => 'precio',
            'employee_one' => 'crepera 1',
            'commission_one' => 'comision 1',
            'employee_two' => 'crepera 2',
            'cost' => 'costo sin comision',
        ];
        $columns = [];

        foreach ($required as $key => $name) {
            $index = array_search($name, $header, true);
            if ($index === false) {
                throw new RuntimeException("Falta la columna requerida '{$name}' en el CSV.");
            }
            $columns[$key] = $index;
        }

        $commissionTwoIndex = array_search('', $header, true);
        if ($commissionTwoIndex === false) {
            throw new RuntimeException('Falta la columna de comisión de la segunda crepera en el CSV.');
        }
        $columns['commission_two'] = $commissionTwoIndex;

        return $columns;
    }

    /** @return Collection<string, Employee> */
    private function employeesForImport(): Collection
    {
        $employees = Employee::query()->get(['id', 'first_name', 'last_name']);
        $mapped = collect();

        $employees->each(function (Employee $employee) use ($mapped): void {
            $mapped->put($this->normalize($employee->first_name.' '.$employee->last_name), $employee);
        });

        $employees
            ->groupBy(fn (Employee $employee): string => $this->normalize($employee->first_name))
            ->filter(fn (Collection $matches): bool => $matches->count() === 1)
            ->each(function (Collection $matches, string $firstName) use ($mapped): void {
                $mapped->put($firstName, $matches->first());
            });

        return $mapped;
    }

    /**
     * @param  Collection<int, array{number:string,reception:string,date:Carbon,customer:string|null,lines:Collection<int, array<string, mixed>>}>  $sales
     * @param  Collection<string, Employee>  $employees
     */
    private function validateSourceRecords(Collection $sales, Collection $employees): void
    {
        $customers = Customer::query()->get(['id', 'first_name', 'last_name'])
            ->keyBy(fn (Customer $customer): string => $this->normalize($customer->first_name.' '.$customer->last_name));
        $missingCustomers = [];
        $missingEmployees = [];

        foreach ($sales as $sale) {
            $hasService = $sale['lines']->contains(fn (array $line): bool => $line['type'] === 'servicio');
            if ($hasService && $sale['customer'] === null) {
                $missingCustomers[] = "venta {$sale['number']} sin clienta";
            }
            if ($sale['customer'] !== null && ! $customers->has($this->normalize($sale['customer']))) {
                $missingCustomers[] = "{$sale['customer']} (venta {$sale['number']})";
            }

            foreach ($sale['lines'] as $line) {
                foreach (['employee_one', 'employee_two'] as $column) {
                    $name = (string) $line[$column];
                    if ($name !== '' && $this->employeeForSourceName($name, $employees) === null) {
                        $missingEmployees[] = "{$name} (fila {$line['line_number']})";
                    }
                }
            }
        }

        if ($missingCustomers !== []) {
            throw new RuntimeException('No se encontraron estas clientas: '.implode(', ', array_unique($missingCustomers)).'.');
        }
        if ($missingEmployees !== []) {
            throw new RuntimeException('No se encontraron estas colaboradoras: '.implode(', ', array_unique($missingEmployees)).'.');
        }
    }

    /** @param Collection<string, Employee> $employees */
    private function employeeForSourceName(string $name, Collection $employees): ?Employee
    {
        $normalized = $this->normalize($name);
        $normalized = $this->employeeAliases[$normalized] ?? $normalized;

        return $employees->get($normalized);
    }

    /**
     * @param  Collection<int, array{number:string,reception:string,date:Carbon,customer:string|null,lines:Collection<int, array<string, mixed>>}>  $sales
     * @return array{csv_total:float,financial_income:float,cash_cut_income:float,matches:bool}
     */
    private function reconciliationSummary(Collection $sales, Carbon $from, Carbon $to): array
    {
        $csvTotal = round((float) $sales->sum(fn (array $sale): float => (float) $sale['lines']->sum('price')), 2);
        $financialIncome = round((float) FinanceTransaction::query()
            ->where('type', 'income')
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
            ->sum('amount'), 2);
        $cashCutIncome = round((float) CashSession::query()
            ->whereBetween('opened_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->sum(DB::raw('expected_cash + expected_card + expected_transfer')), 2);

        return [
            'csv_total' => $csvTotal,
            'financial_income' => $financialIncome,
            'cash_cut_income' => $cashCutIncome,
            'matches' => abs($csvTotal - $financialIncome) < .01 && abs($csvTotal - $cashCutIncome) < .01,
        ];
    }

    /**
     * @param  Collection<int, array{number:string,reception:string,date:Carbon,customer:string|null,lines:Collection<int, array<string, mixed>>}>  $sales
     * @param  array{csv_total:float,financial_income:float,cash_cut_income:float,matches:bool}  $reconciliation
     */
    private function displaySummary(Collection $sales, array $reconciliation, Carbon $from, Carbon $to): void
    {
        $lines = $sales->flatMap(fn (array $sale): Collection => $sale['lines']);
        $this->table(
            ['Concepto', 'Resultado'],
            [
                ['Periodo', $from->format('d/m/Y').' al '.$to->format('d/m/Y')],
                ['Ventas', (string) $sales->count()],
                ['Citas con servicios', (string) $sales->filter(fn (array $sale): bool => $sale['lines']->contains(fn (array $line): bool => $line['type'] === 'servicio'))->count()],
                ['Partidas', (string) $lines->count()],
                ['Total del CSV', '$'.number_format($reconciliation['csv_total'], 2)],
                ['Comisiones a registrar', '$'.number_format((float) $lines->sum(fn (array $line): float => (float) $line['commission_one'] + (float) $line['commission_two']), 2)],
                ['Ingresos en Finanzas', '$'.number_format($reconciliation['financial_income'], 2)],
                ['Ingresos en cortes', '$'.number_format($reconciliation['cash_cut_income'], 2)],
            ],
        );

        if ($reconciliation['matches']) {
            $this->info('Conciliación correcta: el CSV coincide con Finanzas y con los cortes.');
        } else {
            $this->warn('Conciliación pendiente: el CSV no coincide con los ingresos ya cargados. La importación no modifica dinero ni se ejecuta sin --allow-unreconciled.');
        }
    }

    /** @param Collection<int, array{number:string}> $sales */
    private function ensureNoTicketCodesExist(Collection $sales): void
    {
        $codes = $sales->map(fn (array $sale): string => '#'.$sale['number']);
        $existing = Ticket::query()->whereIn('code', $codes)->pluck('code')->all();
        if ($existing !== []) {
            throw new RuntimeException('Ya existen tickets para esta importación: '.implode(', ', $existing).'.');
        }
    }

    /**
     * @param  Collection<int, array{number:string,reception:string,date:Carbon,customer:string|null,lines:Collection<int, array<string, mixed>>}>  $sales
     * @return Collection<string, SalonService>
     */
    private function historicalServices(Collection $sales, ServiceCategory $category): Collection
    {
        $services = collect();
        $sales->flatMap(fn (array $sale): Collection => $sale['lines'])
            ->filter(fn (array $line): bool => $line['type'] === 'servicio')
            ->each(function (array $line) use ($services, $category): void {
                $key = $this->normalize($line['concept']);
                if ($services->has($key)) {
                    return;
                }
                $services->put($key, SalonService::query()->firstOrCreate(
                    ['service_category_id' => $category->id, 'name' => $line['concept']],
                    [
                        'description' => 'Servicio histórico importado de ventas del 24 al 30 de septiembre de 2026.',
                        'base_price' => $line['price'],
                        'base_cost' => $line['cost'],
                        'estimated_duration_minutes' => 30,
                        'commission_rate' => null,
                        'commission_type' => 'percentage',
                        'commission_fixed_amount' => null,
                        'price_type' => $line['cost_type'] === 'variable' ? 'variable' : 'fixed',
                        'requires_color_bar' => false,
                        'status' => 'inactive',
                    ],
                ));
            });

        return $services;
    }

    /**
     * @param  Collection<int, array{number:string,reception:string,date:Carbon,customer:string|null,lines:Collection<int, array<string, mixed>>}>  $sales
     * @param  Collection<string, Employee>  $employees
     * @param  Collection<string, SalonService>  $services
     * @return array{appointments:int,product_sales:int,items:int,commissions:int}
     */
    private function persistSales(Collection $sales, Collection $employees, Collection $services, int $actorId): array
    {
        $customers = Customer::query()->get(['id', 'first_name', 'last_name'])
            ->keyBy(fn (Customer $customer): string => $this->normalize($customer->first_name.' '.$customer->last_name));
        $summary = ['appointments' => 0, 'product_sales' => 0, 'items' => 0, 'commissions' => 0];

        foreach ($sales as $sale) {
            $serviceLines = $sale['lines']->filter(fn (array $line): bool => $line['type'] === 'servicio');
            $staff = $serviceLines
                ->flatMap(fn (array $line): array => [$line['employee_one'], $line['employee_two']])
                ->filter()
                ->map(fn (string $name): ?Employee => $this->employeeForSourceName($name, $employees))
                ->filter()
                ->unique('id')
                ->values();
            $customer = $sale['customer'] === null ? null : $customers->get($this->normalize($sale['customer']));
            $appointment = null;

            if ($serviceLines->isNotEmpty()) {
                $appointment = Appointment::query()->create([
                    'customer_id' => $customer->id,
                    'primary_employee_id' => $staff->first()?->id,
                    'secondary_employee_id' => $staff->get(1)?->id,
                    'starts_at' => $sale['date']->copy()->setTime(12, 0),
                    'ends_at' => $sale['date']->copy()->setTime(12, 30),
                    'status' => 'completed',
                    'estimated_total' => $sale['lines']->sum('price'),
                    'notes' => "Importación histórica · Venta #{$sale['number']} · Recepción {$sale['reception']} · El origen no incluye horario.",
                    'created_by' => $actorId,
                ]);
                $summary['appointments']++;
            } else {
                $summary['product_sales']++;
            }

            $ticket = Ticket::query()->create([
                'code' => '#'.$sale['number'],
                'customer_id' => $customer?->id,
                'appointment_id' => $appointment?->id,
                'ticket_type' => $appointment === null ? 'product_sale' : 'appointment',
                'status' => 'paid',
                'estimated_total' => $sale['lines']->sum('price'),
                'opened_at' => $sale['date']->copy()->setTime(12, 0),
                'paid_at' => $sale['date']->copy()->setTime(20, 0),
            ]);

            foreach ($sale['lines'] as $line) {
                $primaryEmployee = $this->employeeForSourceName($line['employee_one'], $employees);
                $ticketItem = TicketItem::query()->create([
                    'ticket_id' => $ticket->id,
                    'type' => $line['type'],
                    'name_snapshot' => $line['concept'],
                    'quantity' => 1,
                    'unit' => $line['type'] === 'servicio' ? 'servicio' : 'unidad',
                    'unit_price' => $line['price'],
                    'line_total' => $line['price'],
                    'cost_snapshot' => $line['cost'],
                    'status' => 'active',
                    'metadata' => [
                        'historical_import' => true,
                        'source_sale_number' => $sale['number'],
                        'source_reception' => $sale['reception'],
                        'source_cost_type' => $line['cost_type'],
                        'employee_id' => $primaryEmployee?->id,
                        'source_employee' => $line['employee_one'] ?: null,
                    ],
                    'added_by' => $actorId,
                ]);
                $summary['items']++;

                if ($appointment !== null && $line['type'] === 'servicio') {
                    $service = $services->get($this->normalize($line['concept']));
                    AppointmentService::query()->create([
                        'appointment_id' => $appointment->id,
                        'salon_service_id' => $service->id,
                        'employee_id' => $primaryEmployee?->id,
                        'name_snapshot' => $line['concept'],
                        'estimated_price' => $line['price'],
                        'estimated_duration_minutes' => 30,
                    ]);
                }

                $summary['commissions'] += $this->persistCommissions($ticket, $ticketItem, $line, $employees);
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  Collection<string, Employee>  $employees
     */
    private function persistCommissions(Ticket $ticket, TicketItem $ticketItem, array $line, Collection $employees): int
    {
        $created = 0;
        foreach ([['employee_one', 'commission_one'], ['employee_two', 'commission_two']] as [$employeeColumn, $commissionColumn]) {
            $amount = (float) $line[$commissionColumn];
            if ($amount <= 0) {
                continue;
            }

            $employee = $this->employeeForSourceName((string) $line[$employeeColumn], $employees);
            if ($employee === null) {
                throw new RuntimeException("No se encontró la colaboradora de la fila {$line['line_number']}.");
            }
            $baseAmount = (float) $line['price'];
            CommissionEntry::query()->create([
                'ticket_id' => $ticket->id,
                'ticket_item_id' => $ticketItem->id,
                'employee_id' => $employee->id,
                'type' => 'service',
                'base_amount' => $baseAmount,
                'rate_snapshot' => $baseAmount > 0 ? round(($amount / $baseAmount) * 100, 2) : 0,
                'amount' => $amount,
                'status' => 'pending',
            ]);
            $created++;
        }

        return $created;
    }

    private function money(string $value): float
    {
        $normalized = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $value)) ?? '';

        return $normalized === '' || $normalized === '-' ? 0.0 : (float) $normalized;
    }

    private function normalize(string $value): string
    {
        $value = Str::ascii(mb_strtolower(trim($value)));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }
}
