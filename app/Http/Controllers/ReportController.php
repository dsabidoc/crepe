<?php

namespace App\Http\Controllers;

use App\Models\CommissionEntry;
use App\Models\InventoryBalance;
use App\Models\Payment;
use App\Models\Ticket;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(): View
    {
        return view('reports.index', ['tickets' => Ticket::count(), 'sales' => Payment::query()->where('status', 'registered')->sum('amount'), 'commissions' => CommissionEntry::sum('amount'), 'lowStock' => InventoryBalance::query()->with('variant')->get()->filter(fn ($balance) => $balance->available_quantity <= $balance->variant->minimum_stock)->count()]);
    }
}
