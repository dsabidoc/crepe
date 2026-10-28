<?php

namespace App\Http\Controllers;

use App\Models\FinanceTransaction;
use App\Services\FinanceCorrectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinanceCorrectionController extends Controller
{
    public function edit(Request $request, int $transaction): View
    {
        abort_unless($request->user()->hasRole('Administrador'), 403);

        return view('finance.correction', ['transaction' => FinanceTransaction::withoutGlobalScope('effective')->with(['account', 'expenseCategory', 'correction'])->findOrFail($transaction)]);
    }

    public function store(Request $request, int $transaction, FinanceCorrectionService $service): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Administrador'), 403);
        $data = $request->validate(['action' => ['required', Rule::in(['correct', 'void'])], 'amount' => ['nullable', 'required_if:action,correct', 'numeric', 'min:.01', 'max:99999999.99', 'decimal:0,2'], 'previous_amount' => ['required', 'numeric'], 'reason' => ['required', 'string', 'min:5', 'max:255'], 'key' => ['required', 'uuid']]);
        $service->correct($transaction, $request->user(), $data['action'] === 'void' ? null : (string) $data['amount'], $data['reason'], $data['key'], (string) $data['previous_amount']);

        return redirect()->route('finance.index', ['view' => 'movements'])->with('success', 'Corrección registrada. El movimiento original se conserva en el historial.');
    }
}
