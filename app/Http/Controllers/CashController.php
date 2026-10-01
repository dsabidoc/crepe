<?php

namespace App\Http\Controllers;

use App\Models\CashRegister;
use App\Models\CashSession;
use App\Services\CashCutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CashController extends Controller
{
    public function index(Request $request, CashCutService $cashCuts): View
    {
        $isAdmin = $request->user()->can('cash.authorize');
        $registerId = $request->integer('register');
        $status = $request->string('status')->toString();
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();
        $sessions = CashSession::query()
            ->with([
                'closedBy',
                'cashTransactions.expenseCategory',
                'openedBy',
                'payments.ticket.appointment.employee',
                'payments.ticket.appointment.secondaryEmployee',
                'payments.ticket.customer',
                'register',
                'verifiedBy',
            ])
            ->orderByDesc('business_date')
            ->orderByDesc('opened_at')
            ->when(! $isAdmin, fn ($query) => $query->where('opened_by', $request->user()->id))
            ->when($registerId, fn ($query) => $query->where('cash_register_id', $registerId))
            ->when(in_array($status, ['open', 'pending_review', 'verified'], true), fn ($query) => $query->where('status', $status))
            ->when($from, fn ($query) => $query->whereDate('business_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('business_date', '<=', $to))
            ->get();
        $registers = CashRegister::query()->where('is_active', true)->orderBy('name')->get();
        $occupiedRegisterIds = CashSession::query()->whereDate('business_date', now()->toDateString())->pluck('cash_register_id')->map(fn ($id): int => (int) $id)->all();
        $todaySessions = $sessions
            ->filter(fn (CashSession $session): bool => $session->business_date?->isToday() ?? false)
            ->keyBy('cash_register_id');
        $pendingCuts = $isAdmin ? $sessions->where('status', 'pending_review')->values() : collect();
        $expectedBySession = $sessions->mapWithKeys(fn (CashSession $session): array => [
            $session->id => $cashCuts->expectedAmounts($session),
        ]);

        return view('cash.index', [
            'canAuthorize' => $request->user()->can('cash.authorize'),
            'pendingCuts' => $pendingCuts,
            'registers' => $registers,
            'sessions' => $sessions,
            'todaySessions' => $todaySessions,
            'todayOpenSessionsCount' => $todaySessions->where('status', 'open')->count(),
            'defaultOpeningFloat' => $cashCuts->defaultOpeningFloat(),
            'expectedBySession' => $expectedBySession,
            'isAdmin' => $isAdmin,
            'occupiedRegisterIds' => $occupiedRegisterIds,
            'registerId' => $registerId,
            'status' => $status,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function openSession(Request $request, CashCutService $cashCuts): RedirectResponse
    {
        $data = $request->validate([
            'cash_register_id' => ['required', Rule::exists('cash_registers', 'id')->where('is_active', true)],
            'opening_float' => ['required', 'numeric', 'min:0'],
        ]);

        $cashCuts->openDailySession((int) $data['cash_register_id'], $request->user()->id, (float) $data['opening_float']);

        return back()->with('success', 'Caja abierta correctamente.');
    }

    public function startDay(Request $request, CashCutService $cashCuts): RedirectResponse
    {
        $data = $request->validate([
            'opening_float' => ['required', 'numeric', 'min:0'],
        ]);

        $cashCuts->openAvailableDailySessionFor($request->user()->id, (float) $data['opening_float']);

        return redirect()->route('workspace', 'recepcion')->with('success', 'Tu caja está abierta. Puedes comenzar a operar.');
    }

    public function generateCut(Request $request, CashSession $cashSession, CashCutService $cashCuts): RedirectResponse
    {
        abort_unless($request->user()->can('cash.authorize') || $cashSession->opened_by === $request->user()->id, 403);
        $data = $request->validate([
            'actual_card' => ['required', 'numeric', 'min:0'],
            'actual_cash' => ['required', 'numeric', 'min:0'],
            'actual_change' => ['required', 'numeric', 'min:0'],
            'actual_gift_card' => ['required', 'numeric', 'min:0'],
            'actual_other' => ['required', 'numeric', 'min:0'],
            'actual_transfer' => ['required', 'numeric', 'min:0'],
            'cashier_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $cashCuts->generateCut(
            $cashSession,
            [
                'actual_card' => (float) $data['actual_card'],
                'actual_cash' => (float) $data['actual_cash'],
                'actual_change' => (float) $data['actual_change'],
                'actual_gift_card' => (float) $data['actual_gift_card'],
                'actual_other' => (float) $data['actual_other'],
                'actual_transfer' => (float) $data['actual_transfer'],
                'cashier_notes' => $data['cashier_notes'] ?? null,
            ],
            $request->user()->id,
        );

        return back()->with('success', 'Corte generado y enviado a revisión de administración.');
    }

    public function confirm(Request $request, CashSession $cashSession, CashCutService $cashCuts): RedirectResponse
    {
        $data = $request->validate([
            'verification_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $cashCuts->confirm($cashSession, $data['verification_notes'] ?? null, $request->user()->id);

        return back()->with('success', 'Corte confirmado por administración.');
    }
}
