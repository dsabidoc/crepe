<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Payment;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketService
{
    public function __construct(private CashCutService $cashCuts) {}

    public function ensureForAppointment(Appointment $appointment, int $actorId): Ticket
    {
        $ticket = Ticket::query()->firstOrCreate(['appointment_id' => $appointment->id], function () use ($appointment) {
            return ['code' => 'TMP-'.uniqid(), 'customer_id' => $appointment->customer_id, 'ticket_type' => 'appointment', 'status' => 'open', 'estimated_total' => $appointment->estimated_total, 'opened_at' => $appointment->starts_at];
        });
        if (str_starts_with($ticket->code, 'TMP-')) {
            $ticket->update(['code' => '#'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT)]);
        }
        if (! $ticket->items()->exists()) {
            foreach ($appointment->services as $service) {
                $firstPrice = $service->service?->price_type === 'fixed' ? $service->service->prices->first() : null;
                $ticket->items()->create(['type' => 'service', 'name_snapshot' => $service->name_snapshot, 'quantity' => 1, 'unit' => 'servicio', 'unit_price' => $firstPrice?->sale_price ?? $service->estimated_price, 'line_total' => $firstPrice?->sale_price ?? $service->estimated_price, 'cost_snapshot' => $firstPrice?->cost ?? $service->service?->base_cost, 'status' => 'active', 'metadata' => ['salon_service_id' => $service->salon_service_id, 'employee_id' => $service->employee_id, 'commission_rate' => optional($service->employee)->commission_rate, 'price_type' => $service->service?->price_type, 'price_tier' => $firstPrice?->tier, 'price_confirmed' => false], 'added_by' => $actorId]);
            }
        }

        return $ticket;
    }

    public function createProductSale(?int $customerId): Ticket
    {
        return DB::transaction(function () use ($customerId): Ticket {
            $ticket = Ticket::create([
                'code' => 'TMP-'.uniqid(),
                'customer_id' => $customerId,
                'appointment_id' => null,
                'ticket_type' => 'product_sale',
                'status' => 'open',
                'estimated_total' => 0,
                'opened_at' => now(),
            ]);
            $ticket->update(['code' => '#'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT)]);

            return $ticket;
        });
    }

    public function registerPayment(Ticket $ticket, float $amount, string $method, int $cashRegisterId, int $actorId, ?int $financeAccountId = null): Payment
    {
        return DB::transaction(function () use ($ticket, $amount, $method, $cashRegisterId, $actorId, $financeAccountId): Payment {
            $ticket = Ticket::query()->with(['items', 'adjustments', 'payments'])->lockForUpdate()->findOrFail($ticket->id);
            $this->ensureServicePricesConfirmed($ticket);
            if ($ticket->status === 'paid' || $amount <= 0 || $amount > $ticket->balance + 0.01) {
                throw ValidationException::withMessages(['amount' => 'El monto no es válido para el saldo actual.']);
            }
            $cashSession = $this->cashCuts->sessionForPayment($cashRegisterId, $actorId);
            $payment = Payment::create(['ticket_id' => $ticket->id, 'cash_session_id' => $cashSession->id, 'finance_account_id' => $financeAccountId, 'method' => $method, 'amount' => $amount, 'status' => 'registered', 'created_by' => $actorId]);
            $ticket->refresh();
            if ($ticket->balance <= 0.01) {
                $ticket->update(['status' => 'paid', 'paid_at' => now(), 'lock_version' => $ticket->lock_version + 1]);
            }

            return $payment;
        });
    }

    public function close(Ticket $ticket): void
    {
        DB::transaction(function () use ($ticket): void {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $this->ensureServicePricesConfirmed($ticket->load('items'));

            if ($ticket->status !== 'paid' || $ticket->balance > 0.01) {
                throw ValidationException::withMessages([
                    'ticket' => 'El ticket debe estar liquidado antes de cerrarlo.',
                ]);
            }

            if ($ticket->appointment_id === null) {
                return;
            }

            $appointment = Appointment::query()->lockForUpdate()->findOrFail($ticket->appointment_id);

            if ($appointment->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'ticket' => 'No puedes cerrar una cita cancelada.',
                ]);
            }

            if ($appointment->status !== 'completed') {
                $appointment->update(['status' => 'completed']);
            }
        });
    }

    private function ensureServicePricesConfirmed(Ticket $ticket): void
    {
        $hasUnconfirmedService = $ticket->items->contains(fn ($item): bool => $item->type === 'service' && $item->status === 'active' && ($item->metadata['price_confirmed'] ?? false) !== true);

        if ($hasUnconfirmedService) {
            throw ValidationException::withMessages(['ticket' => 'Confirma el tipo de precio de todos los servicios antes de continuar.']);
        }
    }
}
