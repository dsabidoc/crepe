<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\InventoryBalance;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, string $mode): View
    {
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
        $salesToday = Ticket::query()->whereDate('opened_at', $today)->get()->sum(fn (Ticket $ticket) => $ticket->total);
        $lowStock = InventoryBalance::query()
            ->select('inventory_balances.*')
            ->join('product_variants', 'product_variants.id', '=', 'inventory_balances.product_variant_id')
            ->with(['variant.product', 'location'])
            ->whereColumn('inventory_balances.available_quantity', '<=', 'product_variants.minimum_stock')
            ->orderBy('inventory_balances.available_quantity')
            ->get();

        return view('workspace.dashboard', compact('mode', 'employees', 'appointments', 'tickets', 'salesToday', 'lowStock'));
    }
}
