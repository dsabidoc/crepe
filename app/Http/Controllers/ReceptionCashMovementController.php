<?php

namespace App\Http\Controllers;

use App\Services\CashCutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class ReceptionCashMovementController extends Controller
{
    public function store(Request $request, CashCutService $cashCuts): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            'finance_expense_category_id' => ['nullable', Rule::exists('finance_expense_categories', 'id')->where('is_active', true)],
            'concept' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'occurred_on' => ['required', 'date', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'evidence' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        if ($data['type'] === 'expense' && empty($data['finance_expense_category_id'])) {
            return back()->withErrors(['finance_expense_category_id' => 'Selecciona una categoría de gasto.'])->withInput();
        }

        $evidencePath = $request->file('evidence')?->store('cash-movements', 'public');
        try {
            $cashCuts->recordReceptionMovement($request->user()->id, [
                'type' => $data['type'],
                'finance_expense_category_id' => $data['finance_expense_category_id'] ?? null,
                'concept' => $data['concept'],
                'amount' => (float) $data['amount'],
                'occurred_on' => $data['occurred_on'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], $evidencePath);
        } catch (Throwable $exception) {
            if ($evidencePath !== null) {
                Storage::disk('public')->delete($evidencePath);
            }

            throw $exception;
        }

        return redirect()->route('workspace', 'recepcion')->with('success', $data['type'] === 'income'
            ? 'Ingreso registrado en tu caja.'
            : 'Gasto registrado y descontado de tu caja.');
    }
}
