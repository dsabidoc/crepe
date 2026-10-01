<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\FinanceAccount;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceTransaction;
use App\Models\InventoryRequest;
use App\Models\Payment;
use App\Models\TicketItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;

class FinanceController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search'));
        $view = $request->string('view')->toString() ?: 'dashboard';
        $type = $request->string('type')->toString();
        $accountId = $request->integer('account');
        $from = $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();
        $accounts = FinanceAccount::query()->where('is_active', true)->orderBy('name')->get();
        $categories = FinanceExpenseCategory::query()->where('is_active', true)->orderBy('name')->get();

        if ($view === 'dashboard') {
            return view('finance.dashboard', $this->dashboardData($from, $to));
        }

        if ($view === 'accounts') {
            $this->hydrateAccountBalances($accounts);
            $this->hydrateAccountDailyActivity($accounts);

            return view('finance.accounts', ['accounts' => $accounts]);
        }

        if ($view === 'catalogs') {
            return view('finance.catalogs', ['categories' => FinanceExpenseCategory::query()->where('is_active', true)->orderBy('name')->get()]);
        }

        $transactionQuery = FinanceTransaction::query()
            ->with(['account', 'createdBy'])
            ->when(in_array($type, ['income', 'expense', 'transfer', 'adjustment'], true), fn ($query) => $query->where('type', $type))
            ->when($accountId > 0, fn ($query) => $query->where('finance_account_id', $accountId))
            ->when($from, fn ($query, $from) => $query->whereDate('occurred_on', '>=', $from))
            ->when($to, fn ($query, $to) => $query->whereDate('occurred_on', '<=', $to))
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('concept', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")))
            ->latest('occurred_on')->latest('id');
        $transactions = $transactionQuery->get();
        $paymentQuery = Payment::query()
            ->with(['ticket.customer', 'cashSession.register', 'financeAccount'])
            ->where('status', 'registered')
            ->when($accountId > 0, fn ($query) => $query->where('finance_account_id', $accountId))
            ->when($from, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->when($search, fn ($query) => $query->whereHas('ticket', fn ($tickets) => $tickets
                ->where('code', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($customers) => $customers
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"))))
            ->latest();
        $payments = $type === '' || $type === 'income' ? $paymentQuery->get() : collect();

        $transactionTypeLabels = ['income' => 'Ingreso', 'expense' => 'Gasto', 'transfer' => 'Traspaso', 'adjustment' => 'Ajuste'];
        $movementRows = $transactions->map(function (FinanceTransaction $transaction) use ($transactionTypeLabels): array {
            $isPositive = $transaction->type === 'income'
                || ($transaction->type !== 'expense' && $transaction->direction === 'in');

            return [
                'date' => $transaction->occurred_on?->format('d/m/Y'),
                'sort_key' => $transaction->occurred_on?->timestamp ?? 0,
                'concept' => $transaction->concept,
                'detail' => $transaction->reference,
                'account' => $transaction->account?->name ?? '—',
                'type' => $transactionTypeLabels[$transaction->type] ?? $transaction->type,
                'class' => $isPositive ? 'positive' : 'negative',
                'prefix' => $isPositive ? '+' : '−',
                'amount' => (float) $transaction->amount,
            ];
        })->concat($payments->map(function (Payment $payment): array {
            $isGiftCard = $payment->method === 'gift_card';
            $methodLabel = [
                'cash' => 'Efectivo',
                'card' => 'Tarjeta',
                'transfer' => 'Transferencia',
                'gift_card' => 'Gift Card',
                'other' => 'Otro',
            ][$payment->method] ?? str($payment->method)->headline();

            return [
                'date' => $payment->created_at?->format('d/m/Y H:i'),
                'sort_key' => $payment->created_at?->timestamp ?? 0,
                'concept' => ($isGiftCard ? 'Aplicación Gift Card' : 'Ticket').' '.$payment->ticket?->code,
                'detail' => ($payment->ticket?->customer?->full_name ?? 'Venta de mostrador').' · '.$methodLabel,
                'account' => $payment->financeAccount?->name ?? ($isGiftCard ? 'Gift Card' : 'Sin asignar'),
                'type' => $isGiftCard ? 'Gift Card aplicada' : 'Ingreso de ticket',
                'class' => $isGiftCard ? 'neutral' : 'positive',
                'prefix' => $isGiftCard ? '' : '+',
                'amount' => (float) $payment->amount,
            ];
        }))->sortByDesc('sort_key')->values();

        $summaryQuery = FinanceTransaction::query()
            ->when($accountId > 0, fn ($query) => $query->where('finance_account_id', $accountId))
            ->when($from, fn ($query, $from) => $query->whereDate('occurred_on', '>=', $from))
            ->when($to, fn ($query, $to) => $query->whereDate('occurred_on', '<=', $to));
        $includeIncome = $type === '' || $type === 'income';
        $includeExpenses = $type === '' || $type === 'expense';
        $manualIncome = $includeIncome ? (clone $summaryQuery)->where('type', 'income')->sum('amount') : 0;
        $expenses = $includeExpenses ? (clone $summaryQuery)->where('type', 'expense')->sum('amount') : 0;
        $ticketIncome = $includeIncome
            ? Payment::query()->where('status', 'registered')
                ->where('method', '!=', 'gift_card')
                ->when($accountId > 0, fn ($query) => $query->where('finance_account_id', $accountId))
                ->when($from, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
                ->when($to, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))->sum('amount')
            : 0;
        $paymentSummaryQuery = Payment::query()->where('status', 'registered')
            ->when($accountId > 0, fn ($query) => $query->where('finance_account_id', $accountId))
            ->when($from, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query, $to) => $query->whereDate('created_at', '<=', $to));
        $cashIncome = $includeIncome ? (clone $paymentSummaryQuery)->where('method', 'cash')->sum('amount') : 0;
        $bankIncome = $includeIncome ? (clone $paymentSummaryQuery)->whereIn('method', ['card', 'transfer'])->sum('amount') : 0;
        $filteredSummaryQuery = clone $summaryQuery;
        if (in_array($type, ['income', 'expense', 'transfer', 'adjustment'], true)) {
            $filteredSummaryQuery->where('type', $type);
        }
        $movementCount = (clone $filteredSummaryQuery)->count() + ($includeIncome ? (clone $paymentSummaryQuery)->count() : 0);

        $this->hydrateAccountBalances($accounts, $from, $to);

        return view('finance.index', compact(
            'accounts', 'accountId', 'bankIncome', 'cashIncome', 'expenses', 'from', 'manualIncome', 'movementCount', 'movementRows', 'search', 'ticketIncome',
            'to', 'type', 'view', 'categories',
        ))->with('incomeTotal', (float) $manualIncome + (float) $ticketIncome)
            ->with('netTotal', (float) $manualIncome + (float) $ticketIncome - (float) $expenses);
    }

    public function storeTransaction(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'finance_account_id' => ['required', Rule::exists('finance_accounts', 'id')->where('is_active', true)],
            'type' => ['required', Rule::in(['income', 'expense', 'transfer', 'adjustment'])],
            'direction' => ['nullable', Rule::in(['in', 'out'])],
            'transfer_to_account_id' => ['nullable', Rule::exists('finance_accounts', 'id')->where('is_active', true)],
            'finance_expense_category_id' => ['nullable', Rule::exists('finance_expense_categories', 'id')->where('is_active', true)],
            'concept' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'occurred_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'evidence' => ['nullable', File::types(['jpg', 'jpeg', 'png', 'pdf'])->max('10mb')],
        ]);
        if ($data['type'] === 'transfer' && empty($data['transfer_to_account_id'])) {
            return back()->withErrors(['transfer_to_account_id' => 'Selecciona la cuenta destino para el traspaso.'])->withInput();
        }
        if (in_array($data['type'], ['transfer', 'adjustment'], true) && empty($data['direction'])) {
            return back()->withErrors(['direction' => 'Indica si el movimiento suma o resta.'])->withInput();
        }
        if ($data['type'] === 'expense' && empty($data['finance_expense_category_id'])) {
            return back()->withErrors(['finance_expense_category_id' => 'Selecciona una categoría de gasto.'])->withInput();
        }
        $data['created_by'] = $request->user()->id;
        $data['evidence_path'] = $request->hasFile('evidence')
            ? $request->file('evidence')->store('finance-evidence', 'public')
            : null;
        unset($data['evidence']);
        if ($data['type'] === 'transfer') {
            if ((int) $data['finance_account_id'] === (int) $data['transfer_to_account_id']) {
                return back()->withErrors(['transfer_to_account_id' => 'La cuenta destino debe ser diferente.'])->withInput();
            }
            $destinationId = (int) $data['transfer_to_account_id'];
            $data['direction'] = 'out';
            FinanceTransaction::query()->create($data);
            FinanceTransaction::query()->create(array_merge($data, [
                'finance_account_id' => $destinationId,
                'transfer_to_account_id' => $data['finance_account_id'],
                'direction' => 'in',
            ]));
        } else {
            FinanceTransaction::query()->create($data);
        }

        return back()->with('success', 'Movimiento financiero registrado.');
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:finance_accounts,name'],
            'type' => ['required', Rule::in(['cash', 'bank', 'other'])],
            'initial_balance' => ['required', 'numeric', 'min:0'],
            'is_primary' => ['nullable', 'boolean'],
        ]);
        $data['created_by'] = $request->user()->id;
        $data['is_primary'] = (bool) ($data['is_primary'] ?? false);
        if ($data['is_primary']) {
            FinanceAccount::query()->where('type', $data['type'])->update(['is_primary' => false]);
        }
        FinanceAccount::query()->create($data);

        return back()->with('success', 'Cuenta agregada.');
    }

    public function showAccount(FinanceAccount $financeAccount): View
    {
        abort_unless($financeAccount->is_active, 404);
        $this->hydrateAccountBalances(collect([$financeAccount]));

        $transactions = $financeAccount->transactions()
            ->with(['expenseCategory', 'createdBy'])
            ->latest('occurred_on')
            ->latest('id')
            ->get();
        $payments = $financeAccount->payments()
            ->with(['ticket.customer', 'cashSession.register'])
            ->where('status', 'registered')
            ->latest()
            ->get();
        $today = now()->toDateString();
        $todayIncome = (float) $payments->filter(fn (Payment $payment): bool => $payment->created_at?->isToday() ?? false)->sum('amount')
            + (float) $transactions->filter(fn (FinanceTransaction $transaction): bool => $transaction->occurred_on?->toDateString() === $today && $transaction->type === 'income')->sum('amount');
        $todayExpenses = (float) $transactions->filter(fn (FinanceTransaction $transaction): bool => $transaction->occurred_on?->toDateString() === $today && $transaction->type === 'expense')->sum('amount');
        $movementRows = $transactions->map(function (FinanceTransaction $transaction): array {
            $positive = $transaction->type === 'income' || ($transaction->type !== 'expense' && $transaction->direction === 'in');

            return [
                'date' => $transaction->occurred_on?->format('d/m/Y'),
                'sort_key' => $transaction->occurred_on?->timestamp ?? 0,
                'concept' => $transaction->concept,
                'detail' => $transaction->reference,
                'type' => match ($transaction->type) {
                    'income' => 'Ingreso',
                    'expense' => 'Gasto',
                    'transfer' => 'Traspaso',
                    'adjustment' => 'Ajuste',
                    default => $transaction->type,
                },
                'positive' => $positive,
                'amount' => (float) $transaction->amount,
            ];
        })->concat($payments->map(function (Payment $payment): array {
            return [
                'date' => $payment->created_at?->format('d/m/Y H:i'),
                'sort_key' => $payment->created_at?->timestamp ?? 0,
                'concept' => 'Pago de ticket '.$payment->ticket?->code,
                'detail' => $payment->ticket?->customer?->full_name ?? 'Venta de mostrador',
                'type' => 'Ingreso de ticket',
                'positive' => $payment->method !== 'gift_card',
                'amount' => (float) $payment->amount,
            ];
        }))->sortByDesc('sort_key')->values();

        return view('finance.account-detail', compact('financeAccount', 'movementRows', 'todayExpenses', 'todayIncome'));
    }

    public function destroyAccount(FinanceAccount $financeAccount): RedirectResponse
    {
        abort_if(in_array($financeAccount->name, ['C-Bancomer', 'C-Efectivo', 'C-Recepción 1', 'C-Recepción 2'], true), 422, 'Esta cuenta es necesaria para la operación del sistema.');

        if ($financeAccount->transactions()->exists() || $financeAccount->payments()->exists()) {
            return back()->withErrors(['account' => 'No puedes eliminar una cuenta con movimientos. Conserva su historial y déjala sin usar.']);
        }

        $financeAccount->delete();

        return back()->with('success', 'Cuenta eliminada.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120', 'unique:finance_expense_categories,name']]);
        $data['created_by'] = $request->user()->id;
        FinanceExpenseCategory::query()->create($data);

        return back()->with('success', 'Categoría de gasto agregada.');
    }

    /** @return array<string, mixed> */
    private function dashboardData(string $from, string $to): array
    {
        $items = TicketItem::query()->with(['ticket.appointment.employee'])->where('status', 'active')
            ->whereHas('ticket', fn ($query) => $query->whereBetween('opened_at', [$from.' 00:00:00', $to.' 23:59:59']))
            ->whereIn('type', ['product', 'service'])->get();
        $products = $items->where('type', 'product')->groupBy('name_snapshot')->map(fn ($rows, $name): array => ['name' => $name, 'quantity' => (float) $rows->sum('quantity'), 'amount' => (float) $rows->sum('line_total')])->sortByDesc('amount')->values()->take(10);
        $services = $items->where('type', 'service')->groupBy('name_snapshot')->map(fn ($rows, $name): array => ['name' => $name, 'quantity' => (float) $rows->sum('quantity'), 'amount' => (float) $rows->sum('line_total')])->sortByDesc('amount')->values()->take(10);
        $productRevenue = (float) $items->where('type', 'product')->sum('line_total');
        $serviceRevenue = (float) $items->where('type', 'service')->sum('line_total');
        $totalRevenue = $productRevenue + $serviceRevenue;
        $serviceGroups = $items->where('type', 'service')->groupBy('name_snapshot')->map(fn ($rows, $name): array => ['name' => $name, 'amount' => (float) $rows->sum('line_total')])->sortByDesc('amount')->values();
        $serviceMix = $serviceGroups->take(5)->map(fn (array $service): array => $service + ['percent' => $serviceRevenue > 0 ? round($service['amount'] / $serviceRevenue * 100, 1) : 0]);
        $otherServiceAmount = (float) $serviceGroups->slice(5)->sum('amount');
        if ($otherServiceAmount > 0) {
            $serviceMix->push(['name' => 'Otros', 'amount' => $otherServiceAmount, 'percent' => $serviceRevenue > 0 ? round($otherServiceAmount / $serviceRevenue * 100, 1) : 0]);
        }
        $employeeIds = $items->map(function (TicketItem $item): ?int {
            $employeeId = $item->metadata['employee_id'] ?? $item->ticket?->appointment?->primary_employee_id;

            return $employeeId ? (int) $employeeId : null;
        })->filter()->unique();
        $employeesById = Employee::query()->whereKey($employeeIds)->get()->keyBy('id');
        $employeeNameForItem = function (TicketItem $item) use ($employeesById): string {
            $employeeId = $item->metadata['employee_id'] ?? $item->ticket?->appointment?->primary_employee_id;

            return $employeesById->get($employeeId)?->full_name ?? 'Sin asignar';
        };
        $productSellers = $items->where('type', 'product')->groupBy($employeeNameForItem)->map(fn ($rows, $name): array => ['name' => $name, 'amount' => (float) $rows->sum('line_total')])->sortByDesc('amount')->values()->take(10);
        $serviceSellers = $items->where('type', 'service')->groupBy($employeeNameForItem)->map(fn ($rows, $name): array => ['name' => $name, 'amount' => (float) $rows->sum('line_total')])->sortByDesc('amount')->values()->take(10);
        $expenseRows = FinanceTransaction::query()->with('expenseCategory')->where('type', 'expense')->whereBetween('occurred_on', [$from, $to])->get();
        $expenses = $expenseRows->groupBy(fn ($transaction): string => $transaction->expenseCategory?->name ?? 'Sin categoría')->map(fn ($rows, $name): array => ['name' => $name, 'amount' => (float) $rows->sum('amount')])->sortByDesc('amount')->values()->take(10);
        $inventoryRequests = InventoryRequest::query()->with(['sourceLocation', 'items.variant.product'])->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->latest()->limit(10)->get();
        $payments = Payment::query()->where('status', 'registered')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->get();

        return compact('expenses', 'from', 'inventoryRequests', 'payments', 'productRevenue', 'products', 'productSellers', 'serviceMix', 'serviceRevenue', 'services', 'serviceSellers', 'to', 'totalRevenue') + [
            'fromLabel' => Carbon::parse($from)->format('d/m/Y'),
            'toLabel' => Carbon::parse($to)->format('d/m/Y'),
        ];
    }

    private function hydrateAccountBalances(Collection $accounts, ?string $from = null, ?string $to = null): void
    {
        $accounts->each(function (FinanceAccount $account) use ($from, $to): void {
            $transactions = $account->transactions()
                ->when($from, fn ($query, $from) => $query->whereDate('occurred_on', '>=', $from))
                ->when($to, fn ($query, $to) => $query->whereDate('occurred_on', '<=', $to))
                ->get(['type', 'direction', 'amount']);
            $transactionBalance = $transactions->sum(function (FinanceTransaction $transaction): float {
                return match ($transaction->type) {
                    'income' => (float) $transaction->amount,
                    'expense' => -(float) $transaction->amount,
                    'transfer', 'adjustment' => $transaction->direction === 'in' ? (float) $transaction->amount : -(float) $transaction->amount,
                    default => 0,
                };
            });
            $paymentBalance = $account->payments()->where('status', 'registered')
                ->when($from, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
                ->when($to, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))->sum('amount');
            $account->setAttribute('current_balance', (float) $account->initial_balance + (float) $transactionBalance + (float) $paymentBalance);
        });
    }

    private function hydrateAccountDailyActivity(Collection $accounts): void
    {
        $today = now()->toDateString();

        $accounts->each(function (FinanceAccount $account) use ($today): void {
            $ticketIncome = (float) $account->payments()
                ->where('status', 'registered')
                ->where('method', '!=', 'gift_card')
                ->whereDate('created_at', $today)
                ->sum('amount');
            $manualIncome = (float) $account->transactions()
                ->where('type', 'income')
                ->whereDate('occurred_on', $today)
                ->sum('amount');
            $expenses = (float) $account->transactions()
                ->where('type', 'expense')
                ->whereDate('occurred_on', $today)
                ->sum('amount');

            $account->setAttribute('today_income', $ticketIncome + $manualIncome);
            $account->setAttribute('today_expenses', $expenses);
        });
    }
}
