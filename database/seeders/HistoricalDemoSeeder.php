<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\AuditLog;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CommissionEntry;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FinanceAccount;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceTransaction;
use App\Models\InventoryLocation;
use App\Models\Payment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\ProductCommissionRule;
use App\Models\ProductVariant;
use App\Models\SalonService;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HistoricalDemoSeeder extends Seeder
{
    private const AUDIT_ACTION = 'demo.dataset.historical.v2';

    public function run(): void
    {
        $actor = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        if (AuditLog::query()->where('action', self::AUDIT_ACTION)->exists()) {
            $this->command?->info('La simulación histórica ya está cargada.');

            return;
        }

        $employees = Employee::query()->where('status', 'active')->where('is_bookable', true)->orderBy('id')->get();
        $services = SalonService::query()->where('status', 'active')->orderBy('id')->get();
        $products = ProductVariant::query()->with('product')->whereHas('product', fn ($query) => $query->where('status', 'active')->where('is_color_bar_usable', false))->orderBy('id')->get();
        $registers = CashRegister::query()->whereIn('code', ['REC-01', 'REC-02'])->orderBy('id')->get();
        $accounts = FinanceAccount::query()->whereIn('name', ['C-Efectivo', 'C-Bancomer'])->pluck('id', 'name');

        if ($employees->isEmpty() || $services->isEmpty() || $products->isEmpty() || $registers->count() < 2 || $accounts->count() < 2) {
            throw new \RuntimeException('Ejecuta primero CrepeSeeder: se requieren catálogos, estilistas, productos, cuentas y las dos recepciones existentes.');
        }

        DB::transaction(function () use ($actor, $employees, $services, $products, $registers, $accounts): void {
            $customers = $this->createCustomers();
            $sessions = $this->createHistoricalSessions($actor, $registers);
            $this->ensureRetailInventory($actor, $products);
            $tickets = $this->createAppointmentsAndTickets($actor, $customers, $employees, $services, $products, $sessions, $accounts);
            $this->createStandaloneProductSales($actor, $customers, $employees, $products, $sessions, $accounts);
            $this->reconcileHistoricalSessions($sessions);
            $this->createFinanceHistory($actor, $sessions, $accounts);
            $this->createPayrollHistory($actor, $employees, $accounts);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => self::AUDIT_ACTION,
                'subject_type' => self::class,
                'subject_id' => $actor->id,
                'after' => ['customers' => $customers->count(), 'tickets' => $tickets->count(), 'sessions' => $sessions->count(), 'payroll_runs' => PayrollRun::query()->where('generated_by', $actor->id)->count()],
                'reason' => 'Simulación histórica de dos meses para revisión visual y funcional.',
            ]);
        });

        $this->command?->info('Simulación histórica cargada: clientas, citas, tickets, ventas, pagos, movimientos y nóminas confirmadas.');
    }

    /** @return Collection<int, Customer> */
    private function createCustomers(): Collection
    {
        $firstNames = ['Valentina', 'Renata', 'Camila', 'Regina', 'Montserrat', 'Karla', 'Elena', 'Diana', 'Patricia', 'Marisol', 'Fátima', 'Laura', 'Melissa', 'Araceli', 'Silvia', 'Dulce', 'Verónica', 'Berenice', 'Cecilia', 'Irene', 'Rocío', 'Natalia', 'Paulina', 'Gabriela', 'Jimena', 'Claudia', 'Fernanda', 'Andrea', 'Sofía', 'Alejandra', 'Mía', 'Luciana', 'Ximena', 'Daniela', 'Mariana', 'Isabela', 'Paola', 'Carolina', 'Lucía', 'Ana'];
        $lastNames = ['Sánchez', 'Pérez', 'Castillo', 'Moo', 'Vargas', 'Herrera', 'Barrera', 'Cauich', 'Noh', 'Poot', 'Domínguez', 'Rosado', 'Medina', 'Lara', 'Peña', 'May', 'Carrillo', 'Ek', 'Cabrera', 'Vega'];
        $customers = collect();

        foreach (range(1, 48) as $index) {
            $email = 'historico.clienta'.str_pad((string) $index, 3, '0', STR_PAD_LEFT).'@crepe.mx';
            $customers->push(Customer::query()->firstOrCreate(['email' => $email], [
                'first_name' => $firstNames[($index - 1) % count($firstNames)],
                'last_name' => $lastNames[($index * 3) % count($lastNames)],
                'phone' => '999 '.str_pad((string) (240 + $index), 3, '0', STR_PAD_LEFT).' '.str_pad((string) (4100 + $index * 17), 4, '0', STR_PAD_LEFT),
                'whatsapp' => $index % 4 === 0 ? null : '999 '.str_pad((string) (240 + $index), 3, '0', STR_PAD_LEFT).' '.str_pad((string) (4100 + $index * 17), 4, '0', STR_PAD_LEFT),
                'birthday' => Carbon::today()->subYears(22 + ($index % 20))->subDays($index),
                'notes' => $index % 7 === 0 ? 'Clienta frecuente; prefiere horarios después de las 16:00.' : null,
                'status' => 'active',
            ]));
        }

        return $customers;
    }

    /** @return Collection<int, CashSession> */
    private function createHistoricalSessions(User $actor, Collection $registers): Collection
    {
        $sessions = collect();
        $from = Carbon::today()->subMonths(2)->startOfDay();
        $to = Carbon::yesterday()->startOfDay();

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            if ($date->isSunday() || $date->isMonday()) {
                continue;
            }
            foreach ($registers as $register) {
                $sessions->push(CashSession::query()->firstOrCreate(
                    ['cash_register_id' => $register->id, 'business_date' => $date->toDateString()],
                    ['opened_by' => $actor->id, 'opened_at' => $date->copy()->setTime(8, 45), 'opening_float' => 1250, 'status' => 'closed', 'closed_at' => $date->copy()->setTime(19, 30), 'closed_by' => $actor->id, 'verified_by' => $actor->id, 'verified_at' => $date->copy()->setTime(20, 0), 'expected_cash' => 0, 'actual_cash' => 0, 'difference' => 0, 'expected_card' => 0, 'actual_card' => 0, 'expected_transfer' => 0, 'actual_transfer' => 0, 'expected_gift_card' => 0, 'actual_gift_card' => 0, 'expected_other' => 0, 'actual_other' => 0, 'actual_change' => 1250, 'cashier_notes' => 'Corte histórico simulado.', 'verification_notes' => 'Corte confirmado por administración.'],
                ));
            }
        }

        return $sessions;
    }

    private function ensureRetailInventory(User $actor, Collection $products): void
    {
        $location = InventoryLocation::query()->where('code', 'REC')->firstOrFail();
        $inventory = app(InventoryService::class);

        foreach ($products->take(12) as $index => $product) {
            $inventory->move($product->id, $location->id, 90 + ($index % 5) * 20, 'purchase', $actor->id, self::class, $product->id, 'Recepción histórica simulada para ventas de mostrador.');
        }
    }

    /** @return Collection<int, Ticket> */
    private function createAppointmentsAndTickets(User $actor, Collection $customers, Collection $employees, Collection $services, Collection $products, Collection $sessions, Collection $accounts): Collection
    {
        $ticketService = app(TicketService::class);
        $tickets = collect();
        $retailProducts = $products->take(12)->values();
        $businessDays = collect();
        for ($date = Carbon::today()->subMonths(2)->startOfDay(); $date->lt(Carbon::today()); $date->addDay()) {
            if (! $date->isSunday() && ! $date->isMonday()) {
                $businessDays->push($date->copy());
            }
        }

        foreach ($businessDays as $dayIndex => $date) {
            foreach (range(0, $dayIndex % 2 === 0 ? 1 : 2) as $slotIndex) {
                $customer = $customers[($dayIndex * 2 + $slotIndex) % $customers->count()];
                $employee = $employees[($dayIndex + $slotIndex) % $employees->count()];
                $service = $services[($dayIndex + $slotIndex) % $services->count()];
                $startsAt = $date->copy()->setTime(9 + (($dayIndex + $slotIndex * 2) % 9), 0);
                $endsAt = $startsAt->copy()->addMinutes((int) $service->estimated_duration_minutes);
                $appointment = Appointment::create(['customer_id' => $customer->id, 'primary_employee_id' => $employee->id, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'status' => 'completed', 'estimated_total' => $service->base_price, 'notes' => $dayIndex % 6 === 0 ? 'Seguimiento de visita histórica.' : null, 'created_by' => $actor->id]);
                AppointmentService::create(['appointment_id' => $appointment->id, 'salon_service_id' => $service->id, 'employee_id' => $employee->id, 'name_snapshot' => $service->name, 'estimated_price' => $service->base_price, 'estimated_duration_minutes' => $service->estimated_duration_minutes]);
                $ticket = $ticketService->ensureForAppointment($appointment->load('services.employee'), $actor->id);
                $ticket->items()->where('type', 'service')->get()->each(function (TicketItem $item): void {
                    $metadata = $item->metadata ?? [];
                    $metadata['price_confirmed'] = true;
                    $item->update(['metadata' => $metadata]);
                });
                if (($dayIndex + $slotIndex) % 3 === 0) {
                    $product = $retailProducts[($dayIndex + $slotIndex) % $retailProducts->count()];
                    $quantity = 1 + (($dayIndex + $slotIndex) % 2);
                    $ticket->items()->create(['type' => 'product', 'name_snapshot' => $product->product->name, 'quantity' => $quantity, 'unit' => $product->base_unit, 'unit_price' => $product->sale_price, 'line_total' => $product->sale_price * $quantity, 'cost_snapshot' => $product->cost, 'status' => 'active', 'metadata' => ['product_id' => $product->product_id, 'product_variant_id' => $product->id, 'employee_id' => $employee->id], 'added_by' => $actor->id]);
                }
                $ticket->refresh();
                $session = $this->sessionForDate($sessions, $date);
                $this->payTicket($ticket, $session, $accounts, $actor, $dayIndex + $slotIndex, $endsAt);
                $tickets->push($ticket->fresh());
            }
        }

        return $tickets;
    }

    private function payTicket(Ticket $ticket, CashSession $session, Collection $accounts, User $actor, int $index, Carbon $paidAt): void
    {
        $amount = round($ticket->total, 2);
        $first = round($amount * ($index % 4 === 0 ? .5 : 1), 2);
        $method = $index % 4 === 0 ? 'cash' : ($index % 4 === 1 ? 'card' : ($index % 4 === 2 ? 'transfer' : 'gift_card'));
        $accountName = in_array($method, ['card', 'transfer'], true) ? 'C-Bancomer' : 'C-Efectivo';
        $payment = Payment::create(['ticket_id' => $ticket->id, 'cash_session_id' => $session->id, 'finance_account_id' => $accounts[$accountName], 'type' => 'payment', 'method' => $method, 'amount' => $first, 'status' => 'registered', 'reference' => 'HIST-PAGO-'.$index, 'created_by' => $actor->id]);
        $payment->created_at = $paidAt;
        $payment->updated_at = $paidAt;
        $payment->saveQuietly();
        if ($first < $amount) {
            $payment = Payment::create(['ticket_id' => $ticket->id, 'cash_session_id' => $session->id, 'finance_account_id' => $accounts['C-Bancomer'], 'type' => 'payment', 'method' => 'card', 'amount' => round($amount - $first, 2), 'status' => 'registered', 'reference' => 'HIST-COMBO-'.$index, 'created_by' => $actor->id]);
            $payment->created_at = $paidAt;
            $payment->updated_at = $paidAt;
            $payment->saveQuietly();
        }
        $ticket->update(['status' => 'paid', 'paid_at' => $paidAt, 'updated_at' => $paidAt]);
        foreach ($ticket->items()->where('type', 'service')->get() as $item) {
            $metadata = $item->metadata ?? [];
            CommissionEntry::firstOrCreate(['ticket_id' => $ticket->id, 'ticket_item_id' => $item->id], ['employee_id' => $metadata['employee_id'] ?? $ticket->appointment?->primary_employee_id, 'type' => 'service', 'base_amount' => $item->line_total, 'rate_snapshot' => $metadata['employee_commission_rate'] ?? 0, 'amount' => round((float) $item->line_total * ((float) ($metadata['employee_commission_rate'] ?? 0) / 100), 2), 'status' => 'pending']);
        }
    }

    private function createStandaloneProductSales(User $actor, Collection $customers, Collection $employees, Collection $products, Collection $sessions, Collection $accounts): void
    {
        $retailProducts = $products->take(12)->values();
        foreach (range(0, 23) as $index) {
            $date = Carbon::today()->subDays(2 + ($index * 2));
            while ($date->isSunday() || $date->isMonday()) {
                $date->subDay();
            }
            $ticket = Ticket::create(['code' => 'TMP-HIST-'.$index, 'customer_id' => $customers[$index % $customers->count()]->id, 'ticket_type' => 'product_sale', 'status' => 'open', 'estimated_total' => 0, 'opened_at' => $date->copy()->setTime(11 + ($index % 7), 0)]);
            $ticket->update(['code' => '#'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT)]);
            $product = $retailProducts[$index % $retailProducts->count()];
            $quantity = 1 + ($index % 3);
            $employee = $employees[$index % $employees->count()];
            $item = $ticket->items()->create(['type' => 'product', 'name_snapshot' => $product->product->name, 'quantity' => $quantity, 'unit' => $product->base_unit, 'unit_price' => $product->sale_price, 'line_total' => $product->sale_price * $quantity, 'cost_snapshot' => $product->cost, 'status' => 'active', 'metadata' => ['product_id' => $product->product_id, 'product_variant_id' => $product->id, 'employee_id' => $employee->id], 'added_by' => $actor->id]);
            $ticket->refresh();
            $session = $this->sessionForDate($sessions, $date);
            $amount = round($ticket->total, 2);
            $method = $index % 3 === 0 ? 'gift_card' : ($index % 2 === 0 ? 'cash' : 'card');
            $payment = Payment::create(['ticket_id' => $ticket->id, 'cash_session_id' => $session->id, 'finance_account_id' => $accounts[in_array($method, ['card'], true) ? 'C-Bancomer' : 'C-Efectivo'], 'type' => 'payment', 'method' => $method, 'amount' => $amount, 'status' => 'registered', 'reference' => 'HIST-PROD-'.$index, 'created_by' => $actor->id]);
            $payment->created_at = $date->copy()->setTime(17, 0);
            $payment->updated_at = $date->copy()->setTime(17, 0);
            $payment->saveQuietly();
            $ticket->update(['status' => 'paid', 'paid_at' => $date->copy()->setTime(17, 0)]);
            CommissionEntry::create(['ticket_id' => $ticket->id, 'ticket_item_id' => $item->id, 'employee_id' => $employee->id, 'type' => 'product', 'base_amount' => $item->line_total, 'rate_snapshot' => 5, 'amount' => round((float) $item->line_total * .05, 2), 'status' => 'pending']);
        }
    }

    private function createFinanceHistory(User $actor, Collection $sessions, Collection $accounts): void
    {
        $categories = FinanceExpenseCategory::query()->pluck('id', 'name');
        foreach (range(0, 39) as $index) {
            $date = Carbon::today()->subDays(2 + $index);
            $accountName = $index % 3 === 0 ? 'C-Bancomer' : 'C-Efectivo';
            $type = $index % 4 === 0 ? 'expense' : 'income';
            $session = $date->isSunday() || $date->isMonday() ? null : $this->sessionForDate($sessions, $date);
            FinanceTransaction::create(['finance_account_id' => $accounts[$accountName], 'cash_session_id' => $session?->id, 'finance_expense_category_id' => $type === 'expense' ? $categories['Compras'] : null, 'type' => $type, 'direction' => $type === 'income' ? 'in' : 'out', 'concept' => $type === 'income' ? ($index % 2 === 0 ? 'Ingreso por venta fuera de inventario' : 'Propina recibida') : ($index % 2 === 0 ? 'Compra de papelería' : 'Mantenimiento de equipo'), 'amount' => 180 + ($index * 23.5), 'occurred_on' => $date, 'reference' => 'HIST-MOV-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'notes' => 'Registro histórico simulado para reportes financieros.', 'created_by' => $actor->id]);
        }
    }

    private function reconcileHistoricalSessions(Collection $sessions): void
    {
        foreach ($sessions as $session) {
            $totals = Payment::query()->where('cash_session_id', $session->id)->where('status', 'registered')->get()->groupBy('method')->map(fn (Collection $payments): float => round((float) $payments->sum('amount'), 2));
            $values = [
                'expected_cash' => $totals->get('cash', 0),
                'expected_card' => $totals->get('card', 0),
                'expected_transfer' => $totals->get('transfer', 0),
                'expected_gift_card' => $totals->get('gift_card', 0),
                'expected_other' => $totals->get('other', 0),
            ];
            $session->update([
                ...$values,
                'actual_cash' => $values['expected_cash'],
                'actual_card' => $values['expected_card'],
                'actual_transfer' => $values['expected_transfer'],
                'actual_gift_card' => $values['expected_gift_card'],
                'actual_other' => $values['expected_other'],
                'difference' => 0,
            ]);
        }
    }

    private function createPayrollHistory(User $actor, Collection $employees, Collection $accounts): void
    {
        $rule = ProductCommissionRule::query()->firstOrCreate(['minimum_sales' => 1, 'maximum_sales' => null], ['commission_rate' => 5, 'positions' => $employees->pluck('position')->unique()->values()->all(), 'is_active' => true]);
        foreach (range(0, 7) as $index) {
            $end = Carbon::today()->subDays(2 + ($index * 7))->startOfDay();
            while (! $end->isTuesday()) {
                $end->subDay();
            }
            $start = $end->copy()->subDays(6);
            $run = PayrollRun::query()->firstOrCreate(['period_starts_on' => $start->toDateString(), 'period_ends_on' => $end->toDateString()], ['includes_product_commissions' => true, 'status' => 'paid', 'generated_by' => $actor->id]);
            foreach ($employees as $employee) {
                $serviceTotal = (float) CommissionEntry::query()->where('employee_id', $employee->id)->where('type', 'service')->whereNull('payroll_item_id')->whereHas('ticket', fn ($query) => $query->whereBetween('paid_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()]))->sum('amount');
                $productTotal = (float) CommissionEntry::query()->where('employee_id', $employee->id)->where('type', 'product')->whereNull('payroll_item_id')->whereHas('ticket', fn ($query) => $query->whereBetween('paid_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()]))->sum('amount');
                $base = (float) ($employee->salary ?? 700);
                $item = PayrollItem::query()->firstOrCreate(['payroll_run_id' => $run->id, 'employee_id' => $employee->id], ['employee_name_snapshot' => $employee->full_name, 'base_pay' => $base, 'service_commissions' => $serviceTotal, 'product_commissions' => $productTotal, 'infonavit_deduction' => $index % 4 === 0 ? 120 : 0, 'other_deductions' => $index % 5 === 0 ? 80 : 0, 'tardiness_deduction' => $index % 3 === 0 ? 50 : 0, 'total' => max(0, $base + $serviceTotal + $productTotal - ($index % 4 === 0 ? 120 : 0) - ($index % 5 === 0 ? 80 : 0) - ($index % 3 === 0 ? 50 : 0))]);
                CommissionEntry::query()->where('employee_id', $employee->id)->whereNull('payroll_item_id')->whereHas('ticket', fn ($query) => $query->whereBetween('paid_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()]))->update(['payroll_item_id' => $item->id, 'status' => 'settled']);
            }
            $total = (float) $run->items()->sum('total');
            FinanceTransaction::query()->firstOrCreate(['source_type' => PayrollRun::class, 'source_id' => $run->id], ['finance_account_id' => $accounts[$index % 2 === 0 ? 'C-Bancomer' : 'C-Efectivo'], 'type' => 'expense', 'direction' => 'out', 'concept' => 'Pago de nómina histórica '.$end->format('d/m/Y'), 'amount' => $total, 'occurred_on' => $end, 'reference' => 'HIST-NOM-'.$index, 'notes' => 'Nómina simulada confirmada y pagada.', 'created_by' => $actor->id]);
        }
    }

    private function sessionForDate(Collection $sessions, Carbon $date): CashSession
    {
        return $sessions->first(fn (CashSession $session): bool => $session->business_date?->toDateString() === $date->toDateString()) ?? throw new \RuntimeException('No se encontró una sesión histórica para '.$date->toDateString());
    }
}
