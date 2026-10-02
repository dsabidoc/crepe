<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Employee;
use App\Models\FinanceExpenseCategory;
use App\Models\InventoryBalance;
use App\Models\Ticket;
use App\Services\CashCutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, string $mode, CashCutService $cashCuts): View|RedirectResponse
    {
        if ($mode === 'finanzas') {
            return redirect()->route('finance.index');
        }
        if ($mode === 'promos') {
            return redirect()->route('promotions.index');
        }
        if ($mode === 'configuracion') {
            return redirect()->route('settings.edit');
        }

        $employees = Employee::query()->where('is_bookable', true)->where('status', 'active')->orderBy('first_name')->get();
        $today = now()->startOfDay();
        $appointments = Appointment::query()->with(['customer', 'employee', 'services', 'ticket'])
            ->whereDate('starts_at', $today)->orderBy('starts_at')->get();
        $ticketQuery = Ticket::query()->with(['customer', 'appointment.employee'])
            ->whereIn('status', ['open', 'in_service']);
        if ($mode === 'color-bar') {
            $ticketQuery->where(function ($query): void {
                $query->whereHas('appointment.services.service', fn ($services) => $services->where('requires_color_bar', true))
                    ->orWhereHas('items', fn ($items) => $items->where('type', 'color_bar')->where('status', 'active'));
            });
        }
        $tickets = $ticketQuery->latest('opened_at')->get();
        $salesToday = (float) Ticket::query()
            ->where('status', 'paid')
            ->whereHas('payments', fn ($query) => $query->where('status', 'registered'))
            ->whereDate('paid_at', $today)
            ->sum('charged_total');
        $lowStock = InventoryBalance::query()
            ->select('inventory_balances.*')
            ->join('product_variants', 'product_variants.id', '=', 'inventory_balances.product_variant_id')
            ->with(['variant.product', 'location'])
            ->whereColumn('inventory_balances.available_quantity', '<=', 'product_variants.minimum_stock')
            ->orderBy('inventory_balances.available_quantity')
            ->get();

        $cashSession = null;
        $expenseCategories = collect();
        $availableCashRegister = null;
        $defaultOpeningFloat = $cashCuts->defaultOpeningFloat();
        $shouldStartReceptionDay = false;
        if ($mode === 'recepcion' && ! $request->user()->can('cash.authorize')) {
            $cashSession = $cashCuts->sessionForPaymentPreview($request->user()->id);
            $expenseCategories = FinanceExpenseCategory::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
            $shouldStartReceptionDay = $cashSession === null;
            if ($shouldStartReceptionDay) {
                $occupiedRegisterIds = CashSession::query()
                    ->whereDate('business_date', now()->toDateString())
                    ->pluck('cash_register_id');
                $availableCashRegister = CashRegister::query()
                    ->where('is_active', true)
                    ->whereNotIn('id', $occupiedRegisterIds)
                    ->orderBy('name')
                    ->first();
            }
        }

        return view('workspace.dashboard', compact(
            'availableCashRegister',
            'cashSession',
            'defaultOpeningFloat',
            'employees',
            'expenseCategories',
            'appointments',
            'lowStock',
            'mode',
            'salesToday',
            'shouldStartReceptionDay',
            'tickets',
        ));
    }
}
