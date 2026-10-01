<?php

namespace App\Http\Controllers;

use App\Models\CommissionEntry;
use App\Models\Employee;
use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\ProductCommissionRule;
use App\Models\TicketItem;
use App\Services\PayrollReceiptPdfService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PayrollController extends Controller
{
    public function index(Request $request): View
    {
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();
        $productCommissions = $request->string('product_commissions')->toString();
        $payrollRuns = PayrollRun::query()
            ->withCount('items')
            ->when($from, fn ($query, $from) => $query->whereDate('period_ends_on', '>=', $from))
            ->when($to, fn ($query, $to) => $query->whereDate('period_starts_on', '<=', $to))
            ->when(in_array($productCommissions, ['included', 'excluded'], true), fn ($query) => $query->where('includes_product_commissions', $productCommissions === 'included'))
            ->latest('period_ends_on')
            ->paginate(15)
            ->withQueryString();

        return view('payroll.index', compact('from', 'payrollRuns', 'productCommissions', 'to'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['period_starts_on' => ['required', 'date'], 'period_ends_on' => ['required', 'date', 'after_or_equal:period_starts_on'], 'includes_product_commissions' => ['nullable', 'boolean']]);
        $hasOverlappingPayroll = PayrollRun::query()
            ->whereDate('period_starts_on', '<=', $data['period_ends_on'])
            ->whereDate('period_ends_on', '>=', $data['period_starts_on'])
            ->exists();
        if ($hasOverlappingPayroll) {
            throw ValidationException::withMessages([
                'period_starts_on' => 'Ya existe una nómina que se cruza con este periodo.',
            ]);
        }
        $payrollRun = DB::transaction(function () use ($data, $request): PayrollRun {
            $payrollRun = PayrollRun::query()->create([...$data, 'includes_product_commissions' => (bool) ($data['includes_product_commissions'] ?? false), 'generated_by' => $request->user()->id]);
            $employees = Employee::query()->where('status', 'active')->where(fn ($query) => $query->whereNotNull('salary')->orWhereNotNull('commission_rate'))->orderBy('first_name')->orderBy('last_name')->get();

            foreach ($employees as $employee) {
                $serviceEntries = $this->commissionEntriesFor($employee, $payrollRun, 'service')->lockForUpdate()->get();
                if ($payrollRun->includes_product_commissions) {
                    $this->registerProductCommissions($employee, $payrollRun);
                }
                $productEntries = $payrollRun->includes_product_commissions ? $this->commissionEntriesFor($employee, $payrollRun, 'product')->lockForUpdate()->get() : collect();
                $basePay = $this->weeklySalary($employee);
                $payrollItem = $payrollRun->items()->create(['employee_id' => $employee->id, 'employee_name_snapshot' => $employee->full_name, 'base_pay' => $basePay, 'service_commissions' => $serviceEntries->sum('amount'), 'product_commissions' => $productEntries->sum('amount'), 'total' => $basePay + $serviceEntries->sum('amount') + $productEntries->sum('amount')]);
                CommissionEntry::query()->whereKey($serviceEntries->pluck('id')->merge($productEntries->pluck('id')))->update(['payroll_item_id' => $payrollItem->id, 'status' => 'settled']);
            }

            return $payrollRun;
        });

        return redirect()->route('payroll.show', $payrollRun)->with('success', 'Nómina generada. Captura descuentos antes de descargar los recibos.');
    }

    public function show(PayrollRun $payrollRun): View
    {
        $payrollRun->load(['items.employee', 'generatedBy']);
        $withdrawals = FinanceTransaction::query()
            ->with('account')
            ->where('source_type', PayrollRun::class)
            ->where('source_id', $payrollRun->id)
            ->where('type', 'expense')
            ->latest('occurred_on')
            ->latest('id')
            ->get();
        $payrollTotal = (float) $payrollRun->items->sum('total');
        $withdrawnTotal = (float) $withdrawals->sum('amount');

        return view('payroll.show', [
            'accounts' => FinanceAccount::query()->where('is_active', true)->orderBy('name')->get(),
            'payrollRun' => $payrollRun,
            'payrollTotal' => $payrollTotal,
            'withdrawals' => $withdrawals,
            'withdrawnTotal' => $withdrawnTotal,
            'withdrawalRemaining' => max(0, round($payrollTotal - $withdrawnTotal, 2)),
        ]);
    }

    public function withdraw(Request $request, PayrollRun $payrollRun): RedirectResponse
    {
        $data = $request->validate(['finance_account_id' => ['required', Rule::exists('finance_accounts', 'id')->where('is_active', true)], 'amount' => ['required', 'numeric', 'min:.01'], 'occurred_on' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:120']]);
        DB::transaction(function () use ($data, $payrollRun, $request): void {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($payrollRun->id);
            $expected = (float) $run->items()->sum('total');
            $alreadyWithdrawn = (float) FinanceTransaction::query()
                ->where('source_type', PayrollRun::class)
                ->where('source_id', $run->id)
                ->where('type', 'expense')
                ->sum('amount');
            $remaining = max(0, $expected - $alreadyWithdrawn);

            if ((float) $data['amount'] > $remaining + .01) {
                throw ValidationException::withMessages([
                    'amount' => 'El retiro no puede exceder el saldo pendiente de $'.number_format($remaining, 2).'.',
                ]);
            }

            FinanceTransaction::query()->create(['finance_account_id' => $data['finance_account_id'], 'type' => 'expense', 'direction' => 'out', 'concept' => 'Retiro de nómina '.$run->period_ends_on->format('d/m/Y'), 'amount' => $data['amount'], 'occurred_on' => $data['occurred_on'], 'reference' => $data['reference'] ?? null, 'source_type' => PayrollRun::class, 'source_id' => $run->id, 'created_by' => $request->user()->id]);
        });

        return back()->with('success', 'Retiro de nómina registrado en Finanzas.');
    }

    public function updateItem(Request $request, PayrollRun $payrollRun, PayrollItem $payrollItem): RedirectResponse
    {
        abort_unless($payrollItem->payroll_run_id === $payrollRun->id, 404);
        $data = $request->validate(['infonavit_deduction' => ['nullable', 'numeric', 'min:0'], 'other_deductions' => ['nullable', 'numeric', 'min:0'], 'tardiness_deduction' => ['nullable', 'numeric', 'min:0']]);
        $data = array_map(static fn ($value): float => (float) ($value ?? 0), $data);
        $total = (float) $payrollItem->base_pay + (float) $payrollItem->service_commissions + (float) $payrollItem->product_commissions - $data['infonavit_deduction'] - $data['other_deductions'] - $data['tardiness_deduction'];
        if ($total < 0) {
            throw ValidationException::withMessages([
                'other_deductions' => 'Los descuentos no pueden exceder el total de la nómina.',
            ]);
        }
        $payrollItem->update([...$data, 'total' => $total]);

        return back()->with('success', 'Descuentos de '.$payrollItem->employee_name_snapshot.' actualizados.');
    }

    public function downloadReceipt(PayrollRun $payrollRun, PayrollItem $payrollItem, PayrollReceiptPdfService $receipts): Response
    {
        abort_unless($payrollItem->payroll_run_id === $payrollRun->id, 404);
        $filename = 'recibo-nomina-'.str($payrollItem->employee_name_snapshot)->slug().'-'.$payrollRun->period_ends_on->format('Ymd').'.pdf';

        return response($receipts->render($payrollItem), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"']);
    }

    private function weeklySalary(Employee $employee): float
    {
        $salary = (float) ($employee->salary ?? 0);

        return $employee->salary_type === 'monthly' ? round($salary / 4.3333, 2) : $salary;
    }

    private function commissionEntriesFor(Employee $employee, PayrollRun $payrollRun, string $type): Builder
    {
        return CommissionEntry::query()->whereBelongsTo($employee)->where('type', $type)->whereNull('payroll_item_id')->whereHas('ticket', fn ($query) => $query->whereBetween('paid_at', [$payrollRun->period_starts_on->copy()->startOfDay(), $payrollRun->period_ends_on->copy()->endOfDay()]));
    }

    private function registerProductCommissions(Employee $employee, PayrollRun $payrollRun): void
    {
        $items = TicketItem::query()->with('ticket')->where('type', 'product')->where('status', 'active')->whereJsonContains('metadata->employee_id', $employee->id)->whereHas('ticket', fn ($query) => $query->whereBetween('paid_at', [$payrollRun->period_starts_on->copy()->startOfDay(), $payrollRun->period_ends_on->copy()->endOfDay()]))->get();
        $quantity = (int) $items->sum('quantity');
        $rule = ProductCommissionRule::query()->where('is_active', true)->where('minimum_sales', '<=', $quantity)->where(fn ($query) => $query->whereNull('maximum_sales')->orWhere('maximum_sales', '>=', $quantity))->orderByDesc('minimum_sales')->first();
        if ($rule === null || ! in_array($employee->position, $rule->positions ?? [], true)) {
            return;
        }

        foreach ($items as $item) {
            CommissionEntry::query()->firstOrCreate(['ticket_id' => $item->ticket_id, 'ticket_item_id' => $item->id], ['employee_id' => $employee->id, 'type' => 'product', 'base_amount' => $item->line_total, 'rate_snapshot' => $rule->commission_rate, 'amount' => round((float) $item->line_total * ((float) $rule->commission_rate / 100), 2), 'status' => 'pending']);
        }
    }
}
