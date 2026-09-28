<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashCutService
{
    public function defaultOpeningFloat(): float
    {
        return (float) AppSetting::value('cash.opening_float', 2500);
    }

    public function openDailySession(int $cashRegisterId, int $actorId, ?float $openingFloat = null): CashSession
    {
        return DB::transaction(function () use ($cashRegisterId, $actorId): CashSession {
            $register = CashRegister::query()
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail($cashRegisterId);
            $businessDate = now()->toDateString();
            $session = CashSession::query()
                ->whereBelongsTo($register, 'register')
                ->whereDate('business_date', $businessDate)
                ->lockForUpdate()
                ->first();

            if ($session !== null) {
                if ($session->status !== 'open') {
                    throw ValidationException::withMessages([
                        'cash_register_id' => 'Esta caja ya tiene un corte generado para hoy. Usa la caja que sigue abierta.',
                    ]);
                }

                return $session;
            }

            return CashSession::query()->create([
                'cash_register_id' => $register->id,
                'business_date' => $businessDate,
                'opened_by' => $actorId,
                'opened_at' => now(),
                'opening_float' => $openingFloat ?? $this->defaultOpeningFloat(),
                'status' => 'open',
            ]);
        }, attempts: 3);
    }

    public function sessionForPayment(int $cashRegisterId, int $actorId): CashSession
    {
        $actor = User::query()->findOrFail($actorId);
        $query = CashSession::query()->with('register')
            ->where('status', 'open')->whereDate('business_date', now()->toDateString());

        if ($actor->can('cash.authorize')) {
            return $query->where('cash_register_id', $cashRegisterId)->first() ?? throw ValidationException::withMessages([
                'cash_register_id' => 'La caja seleccionada no está abierta.',
            ]);
        }

        return $query->where('opened_by', $actorId)->first() ?? throw ValidationException::withMessages([
            'cash_register_id' => 'No tienes una caja abierta. Abre tu caja para comenzar a cobrar.',
        ]);
    }

    public function sessionForPaymentPreview(int $actorId): ?CashSession
    {
        return CashSession::query()->with('register')->where('status', 'open')
            ->where('opened_by', $actorId)->whereDate('business_date', now()->toDateString())->first();
    }

    /**
     * @param  array{actual_cash: float, actual_card: float, actual_change: float, actual_other: float, actual_transfer: float, cashier_notes?: string|null}  $countedAmounts
     */
    public function generateCut(CashSession $cashSession, array $countedAmounts, int $actorId): CashSession
    {
        return DB::transaction(function () use ($cashSession, $countedAmounts, $actorId): CashSession {
            $session = CashSession::query()->lockForUpdate()->findOrFail($cashSession->id);

            if ($session->status !== 'open') {
                throw ValidationException::withMessages([
                    'cash_session' => 'Este corte ya fue generado y no puede modificarse.',
                ]);
            }

            $expected = $this->expectedAmounts($session);
            $difference = round(
                $countedAmounts['actual_cash'] + $countedAmounts['actual_card'] + $countedAmounts['actual_change']
                + $countedAmounts['actual_other'] + $countedAmounts['actual_transfer']
                - $expected['cash'] - $expected['card'] - $expected['change'] - $expected['other'] - $expected['transfer'],
                2,
            );

            $session->load('register');
            $session->update([
                'actual_card' => $countedAmounts['actual_card'],
                'actual_cash' => $countedAmounts['actual_cash'],
                'actual_change' => $countedAmounts['actual_change'],
                'actual_other' => $countedAmounts['actual_other'],
                'actual_transfer' => $countedAmounts['actual_transfer'],
                'cashier_notes' => $countedAmounts['cashier_notes'] ?? null,
                'closed_at' => now(),
                'closed_by' => $actorId,
                'difference' => $difference,
                'expected_card' => $expected['card'],
                'expected_cash' => $expected['cash'],
                'expected_other' => $expected['other'],
                'expected_transfer' => $expected['transfer'],
                'status' => 'pending_review',
            ]);

            return $session->fresh(['register', 'payments.ticket.customer']);
        }, attempts: 3);
    }

    public function confirm(CashSession $cashSession, ?string $verificationNotes, int $actorId): CashSession
    {
        return DB::transaction(function () use ($cashSession, $verificationNotes, $actorId): CashSession {
            $session = CashSession::query()->lockForUpdate()->findOrFail($cashSession->id);

            if ($session->status !== 'pending_review') {
                throw ValidationException::withMessages([
                    'cash_session' => 'Sólo se pueden confirmar cortes pendientes de revisión.',
                ]);
            }

            $session->load(['register.financeAccount', 'payments']);
            $sourceAccountId = $session->register?->finance_account_id;
            $payments = $session->payments->where('status', 'registered');
            foreach ($payments as $payment) {
                $destinationType = in_array($payment->method, ['card', 'transfer'], true) ? 'bank' : ($payment->method === 'other' ? 'other' : 'cash');
                $destinationId = FinanceAccount::query()->where('is_active', true)->where('is_primary', true)->where('type', $destinationType)->orderBy('id')->value('id');
                $originId = $payment->finance_account_id ?? $sourceAccountId;

                if (! $originId || ! $destinationId || $originId === $destinationId) {
                    continue;
                }

                $concept = "Traspaso por corte {$session->register?->name} {$session->business_date?->format('d/m/Y')}";
                FinanceTransaction::query()->create([
                    'finance_account_id' => $originId,
                    'transfer_to_account_id' => $destinationId,
                    'type' => 'transfer',
                    'direction' => 'out',
                    'concept' => $concept,
                    'amount' => $payment->amount,
                    'occurred_on' => $session->business_date,
                    'reference' => $session->register?->code,
                    'source_type' => CashSession::class,
                    'source_id' => $session->id,
                    'created_by' => $actorId,
                ]);
                FinanceTransaction::query()->create([
                    'finance_account_id' => $destinationId,
                    'transfer_to_account_id' => $originId,
                    'type' => 'transfer',
                    'direction' => 'in',
                    'concept' => $concept,
                    'amount' => $payment->amount,
                    'occurred_on' => $session->business_date,
                    'reference' => $session->register?->code,
                    'source_type' => CashSession::class,
                    'source_id' => $session->id,
                    'created_by' => $actorId,
                ]);
            }

            $session->update([
                'status' => 'verified',
                'verified_at' => now(),
                'verified_by' => $actorId,
                'verification_notes' => $verificationNotes,
            ]);

            return $session->fresh(['register', 'payments.ticket.customer']);
        }, attempts: 3);
    }

    /**
     * @return array{card: float, cash: float, change: float, other: float, transfer: float}
     */
    public function expectedAmounts(CashSession $cashSession): array
    {
        $totals = Payment::query()
            ->whereBelongsTo($cashSession, 'cashSession')
            ->where('status', 'registered')
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        return [
            'card' => (float) ($totals->get('card') ?? 0),
            'cash' => (float) ($totals->get('cash') ?? 0),
            'change' => (float) $cashSession->opening_float,
            'other' => (float) ($totals->get('other') ?? 0),
            'transfer' => (float) ($totals->get('transfer') ?? 0),
        ];
    }
}
