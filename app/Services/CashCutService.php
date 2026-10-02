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
        return (float) AppSetting::value('cash.opening_float', 1250);
    }

    public function openAvailableDailySessionFor(int $actorId, float $openingFloat): CashSession
    {
        return DB::transaction(function () use ($actorId, $openingFloat): CashSession {
            $businessDate = now()->toDateString();
            $existingSession = CashSession::query()
                ->where('opened_by', $actorId)
                ->where('status', 'open')
                ->whereDate('business_date', $businessDate)
                ->lockForUpdate()
                ->first();

            if ($existingSession !== null) {
                return $existingSession;
            }

            $registers = CashRegister::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->lockForUpdate()
                ->get();
            $occupiedRegisterIds = CashSession::query()
                ->whereDate('business_date', $businessDate)
                ->lockForUpdate()
                ->pluck('cash_register_id')
                ->all();
            $register = $registers->first(fn (CashRegister $register): bool => ! in_array($register->id, $occupiedRegisterIds, true));

            if ($register === null) {
                throw ValidationException::withMessages([
                    'cash_register_id' => 'No hay una caja disponible para iniciar tu día. Solicita apoyo a administración.',
                ]);
            }

            $session = CashSession::query()->create([
                'cash_register_id' => $register->id,
                'business_date' => $businessDate,
                'opened_by' => $actorId,
                'opened_at' => now(),
                'opening_float' => $openingFloat,
                'status' => 'open',
            ]);

            $this->recordOpeningFloatTransfer($session, $register, $actorId);

            return $session;
        }, attempts: 3);
    }

    public function openDailySession(int $cashRegisterId, int $actorId, ?float $openingFloat = null): CashSession
    {
        return DB::transaction(function () use ($cashRegisterId, $actorId, $openingFloat): CashSession {
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

            $session = CashSession::query()->create([
                'cash_register_id' => $register->id,
                'business_date' => $businessDate,
                'opened_by' => $actorId,
                'opened_at' => now(),
                'opening_float' => $openingFloat ?? $this->defaultOpeningFloat(),
                'status' => 'open',
            ]);

            $this->recordOpeningFloatTransfer($session, $register, $actorId);

            return $session;
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
     * @param  array{type: 'income'|'expense', finance_expense_category_id?: int|null, concept: string, amount: float, occurred_on: string, reference?: string|null, notes?: string|null}  $data
     */
    public function recordReceptionMovement(int $actorId, array $data, ?string $evidencePath = null): FinanceTransaction
    {
        return DB::transaction(function () use ($actorId, $data, $evidencePath): FinanceTransaction {
            $session = CashSession::query()
                ->with('register.financeAccount')
                ->where('opened_by', $actorId)
                ->where('status', 'open')
                ->whereDate('business_date', now()->toDateString())
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                throw ValidationException::withMessages([
                    'cash_session' => 'Abre tu caja antes de registrar un ingreso o gasto.',
                ]);
            }

            $accountId = $session->register?->finance_account_id;
            if ($accountId === null) {
                throw ValidationException::withMessages([
                    'cash_session' => 'Tu caja no tiene una cuenta financiera asignada. Solicita apoyo a administración.',
                ]);
            }

            if ($data['type'] === 'expense') {
                $cashPayments = Payment::query()
                    ->whereBelongsTo($session, 'cashSession')
                    ->where('status', 'registered')
                    ->where('method', 'cash')
                    ->sum('amount');
                $manualTotals = FinanceTransaction::query()
                    ->whereBelongsTo($session, 'cashSession')
                    ->whereIn('type', ['income', 'expense'])
                    ->selectRaw('type, SUM(amount) as total')
                    ->groupBy('type')
                    ->pluck('total', 'type');
                $availableCash = (float) $session->opening_float
                    + (float) $cashPayments
                    + (float) ($manualTotals->get('income') ?? 0)
                    - (float) ($manualTotals->get('expense') ?? 0);

                if ((float) $data['amount'] > $availableCash + 0.01) {
                    throw ValidationException::withMessages([
                        'amount' => 'El gasto es mayor al efectivo disponible en tu caja.',
                    ]);
                }
            }

            return FinanceTransaction::query()->create([
                'finance_account_id' => $accountId,
                'cash_session_id' => $session->id,
                'finance_expense_category_id' => $data['finance_expense_category_id'] ?? null,
                'type' => $data['type'],
                'direction' => $data['type'] === 'income' ? 'in' : 'out',
                'concept' => $data['concept'],
                'amount' => $data['amount'],
                'occurred_on' => $data['occurred_on'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'evidence_path' => $evidencePath,
                'source_type' => CashSession::class,
                'source_id' => $session->id,
                'created_by' => $actorId,
            ]);
        }, attempts: 3);
    }

    /**
     * @param  array{actual_cash: float, actual_card: float, actual_change: float, actual_gift_card: float, actual_other: float, actual_transfer: float, cashier_notes?: string|null}  $countedAmounts
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
                + $countedAmounts['actual_gift_card'] + $countedAmounts['actual_other'] + $countedAmounts['actual_transfer']
                - $expected['cash'] - $expected['card'] - $expected['change'] - $expected['gift_card'] - $expected['other'] - $expected['transfer'],
                2,
            );

            $session->load('register');
            $session->update([
                'actual_card' => $countedAmounts['actual_card'],
                'actual_cash' => $countedAmounts['actual_cash'],
                'actual_change' => $countedAmounts['actual_change'],
                'actual_gift_card' => $countedAmounts['actual_gift_card'],
                'actual_other' => $countedAmounts['actual_other'],
                'actual_transfer' => $countedAmounts['actual_transfer'],
                'cashier_notes' => $countedAmounts['cashier_notes'] ?? null,
                'closed_at' => now(),
                'closed_by' => $actorId,
                'difference' => $difference,
                'expected_card' => $expected['card'],
                'expected_cash' => $expected['cash'],
                'expected_gift_card' => $expected['gift_card'],
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
                if (in_array($payment->method, ['cash', 'gift_card'], true)) {
                    continue;
                }

                $destinationType = in_array($payment->method, ['card', 'transfer'], true) ? 'bank' : ($payment->method === 'other' ? 'other' : 'cash');
                $destinationId = FinanceAccount::query()->where('is_active', true)->where('is_primary', true)->where('type', $destinationType)->orderBy('id')->value('id');
                $originId = $payment->finance_account_id ?? $sourceAccountId;

                if (! $originId || ! $destinationId || $originId === $destinationId) {
                    continue;
                }

                $this->recordTransfer(
                    $originId,
                    $destinationId,
                    (float) $payment->amount,
                    "Traspaso por corte {$session->register?->name} {$session->business_date?->format('d/m/Y')}",
                    $session->business_date->toDateString(),
                    $session->register?->code,
                    $session,
                    $actorId,
                );
            }

            $centralCashAccountId = FinanceAccount::query()
                ->where('is_active', true)
                ->where('is_primary', true)
                ->where('type', 'cash')
                ->orderBy('id')
                ->value('id');
            if ($sourceAccountId && $centralCashAccountId && $sourceAccountId !== $centralCashAccountId) {
                $expected = $this->expectedAmounts($session);
                $expectedCashOnHand = $expected['cash'] + $expected['change'];
                $deliveredCash = (float) $session->actual_cash + (float) $session->actual_change;
                $difference = round($deliveredCash - $expectedCashOnHand, 2);
                if (abs($difference) >= .01) {
                    FinanceTransaction::query()->create([
                        'finance_account_id' => $sourceAccountId,
                        'type' => 'adjustment',
                        'direction' => $difference > 0 ? 'in' : 'out',
                        'concept' => 'Ajuste por diferencia de corte '.$session->register?->name,
                        'amount' => abs($difference),
                        'occurred_on' => $session->business_date,
                        'reference' => $session->register?->code,
                        'source_type' => CashSession::class,
                        'source_id' => $session->id,
                        'created_by' => $actorId,
                    ]);
                }
                $this->recordTransfer(
                    $sourceAccountId,
                    $centralCashAccountId,
                    $deliveredCash,
                    "Entrega de efectivo por corte {$session->register?->name} {$session->business_date?->format('d/m/Y')}",
                    $session->business_date->toDateString(),
                    $session->register?->code,
                    $session,
                    $actorId,
                );
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
     * @return array{card: float, cash: float, change: float, gift_card: float, other: float, transfer: float}
     */
    public function expectedAmounts(CashSession $cashSession): array
    {
        $hasImportedSnapshot = $cashSession->status === 'verified'
            && ! $cashSession->payments()->exists()
            && ! $cashSession->cashTransactions()->exists()
            && ((float) $cashSession->expected_cash > 0
                || (float) $cashSession->expected_card > 0
                || (float) $cashSession->expected_transfer > 0);

        if ($hasImportedSnapshot) {
            return [
                'card' => (float) $cashSession->expected_card,
                'cash' => (float) $cashSession->expected_cash,
                'change' => (float) $cashSession->opening_float,
                'gift_card' => (float) $cashSession->expected_gift_card,
                'other' => (float) $cashSession->expected_other,
                'transfer' => (float) $cashSession->expected_transfer,
            ];
        }

        $totals = Payment::query()
            ->whereBelongsTo($cashSession, 'cashSession')
            ->where('status', 'registered')
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method');
        $manualCashTotals = FinanceTransaction::query()
            ->whereBelongsTo($cashSession, 'cashSession')
            ->whereIn('type', ['income', 'expense'])
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            'card' => (float) ($totals->get('card') ?? 0),
            'cash' => (float) ($totals->get('cash') ?? 0),
            'change' => (float) $cashSession->opening_float
                + (float) ($manualCashTotals->get('income') ?? 0)
                - (float) ($manualCashTotals->get('expense') ?? 0),
            'gift_card' => (float) ($totals->get('gift_card') ?? 0),
            'other' => (float) ($totals->get('other') ?? 0),
            'transfer' => (float) ($totals->get('transfer') ?? 0),
        ];
    }

    private function recordOpeningFloatTransfer(CashSession $session, CashRegister $register, int $actorId): void
    {
        if ((float) $session->opening_float <= 0 || $register->finance_account_id === null) {
            return;
        }

        $centralCashAccountId = FinanceAccount::query()
            ->where('is_active', true)
            ->where('is_primary', true)
            ->where('type', 'cash')
            ->orderBy('id')
            ->value('id');
        if ($centralCashAccountId === null) {
            throw ValidationException::withMessages([
                'cash_register_id' => 'No hay una caja central de efectivo configurada para entregar el cambio inicial.',
            ]);
        }

        $this->recordTransfer(
            $centralCashAccountId,
            $register->finance_account_id,
            (float) $session->opening_float,
            "Entrega de cambio inicial {$register->name}",
            $session->business_date->toDateString(),
            $register->code,
            $session,
            $actorId,
        );
    }

    private function recordTransfer(int $originId, int $destinationId, float $amount, string $concept, string $occurredOn, ?string $reference, CashSession $session, int $actorId): void
    {
        if ($amount <= 0 || $originId === $destinationId) {
            return;
        }

        FinanceTransaction::query()->create([
            'finance_account_id' => $originId,
            'transfer_to_account_id' => $destinationId,
            'type' => 'transfer',
            'direction' => 'out',
            'concept' => $concept,
            'amount' => $amount,
            'occurred_on' => $occurredOn,
            'reference' => $reference,
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
            'amount' => $amount,
            'occurred_on' => $occurredOn,
            'reference' => $reference,
            'source_type' => CashSession::class,
            'source_id' => $session->id,
            'created_by' => $actorId,
        ]);
    }
}
