<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CommissionEntry;
use App\Models\InventoryLocation;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\SalonService;
use App\Models\Ticket;
use App\Models\TicketAdjustment;
use App\Models\TicketItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketService
{
    public function __construct(
        private CashCutService $cashCuts,
        private InventoryService $inventory,
    ) {}

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
                $ticket->items()->create(['type' => 'service', 'name_snapshot' => $service->name_snapshot, 'quantity' => 1, 'unit' => 'servicio', 'unit_price' => $firstPrice?->sale_price ?? $service->estimated_price, 'line_total' => $firstPrice?->sale_price ?? $service->estimated_price, 'cost_snapshot' => $firstPrice?->cost ?? $service->service?->base_cost, 'status' => 'active', 'metadata' => ['salon_service_id' => $service->salon_service_id, 'employee_id' => $service->employee_id, 'employee_commission_rate' => optional($service->employee)->commission_rate, 'price_type' => $service->service?->price_type, 'price_tier' => $firstPrice?->tier, 'price_confirmed' => false], 'added_by' => $actorId]);
            }
        }

        $this->syncPricingTotals($ticket);

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

    public function addReceptionProduct(Ticket $ticket, int $variantId, float $quantity, int $actorId, ?int $employeeId = null): TicketItem
    {
        return DB::transaction(function () use ($ticket, $variantId, $quantity, $actorId, $employeeId): TicketItem {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if ($ticket->status === 'paid') {
                throw ValidationException::withMessages(['ticket' => 'No puedes agregar productos a un ticket pagado.']);
            }

            $variant = ProductVariant::query()->with('product')->lockForUpdate()->findOrFail($variantId);
            if ($variant->product->status !== 'active' || $variant->product->is_color_bar_usable || (float) $variant->sale_price <= 0) {
                throw ValidationException::withMessages(['products' => 'Selecciona un producto disponible para venta en Recepción.']);
            }

            $reception = InventoryLocation::query()->where('code', 'REC')->firstOrFail();
            $item = $ticket->items()->create([
                'type' => 'product',
                'name_snapshot' => $variant->product->name,
                'quantity' => $quantity,
                'unit' => $variant->base_unit,
                'unit_price' => $variant->sale_price,
                'line_total' => $variant->sale_price * $quantity,
                'cost_snapshot' => $variant->cost,
                'status' => 'active',
                'metadata' => ['product_id' => $variant->product_id, 'product_variant_id' => $variant->id, 'employee_id' => $employeeId],
                'added_by' => $actorId,
            ]);

            $this->inventory->move(
                $variant->id,
                $reception->id,
                -$quantity,
                'sale',
                $actorId,
                $item::class,
                $item->id,
                "Venta en {$ticket->code}",
            );
            $this->syncPricingTotals($ticket);

            return $item;
        });
    }

    public function registerPayment(Ticket $ticket, float $amount, string $method, int $cashRegisterId, int $actorId, ?int $financeAccountId = null): Payment
    {
        return DB::transaction(function () use ($ticket, $amount, $method, $cashRegisterId, $actorId, $financeAccountId): Payment {
            $ticket = Ticket::query()->with(['items', 'adjustments', 'payments'])->lockForUpdate()->findOrFail($ticket->id);
            $this->ensureServicePricesConfirmed($ticket);
            $this->syncPricingTotals($ticket);
            $ticket->refresh()->load('items', 'adjustments', 'payments');
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
            $this->syncPricingTotals($ticket);
            $ticket->refresh()->load('items');

            if ($ticket->status === 'cancelled' || $ticket->balance > 0.01) {
                throw ValidationException::withMessages([
                    'ticket' => 'El ticket debe estar liquidado antes de cerrarlo.',
                ]);
            }

            if ($ticket->status !== 'paid') {
                $ticket->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'lock_version' => $ticket->lock_version + 1,
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

            $this->registerServiceCommissions($ticket);
        });
    }

    public function reopen(Ticket $ticket): void
    {
        DB::transaction(function () use ($ticket): void {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if ($ticket->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'ticket' => 'No puedes reabrir un ticket cancelado.',
                ]);
            }

            if ($ticket->status !== 'paid') {
                return;
            }

            $ticket->update([
                'status' => 'open',
                'paid_at' => null,
                'lock_version' => $ticket->lock_version + 1,
            ]);

            if ($ticket->appointment_id !== null) {
                $appointment = Appointment::query()->lockForUpdate()->findOrFail($ticket->appointment_id);

                if ($appointment->status === 'completed') {
                    $appointment->update(['status' => 'confirmed']);
                }
            }
        });
    }

    public function cancel(Ticket $ticket): void
    {
        DB::transaction(function () use ($ticket): void {
            $ticket = Ticket::query()->with('payments')->lockForUpdate()->findOrFail($ticket->id);

            if ($ticket->payments->contains(fn (Payment $payment): bool => $payment->status === 'registered')) {
                throw ValidationException::withMessages([
                    'ticket' => 'Este ticket tiene pagos registrados. Reábrelo para corregirlo; una devolución debe registrarse por separado para no alterar el corte de caja.',
                ]);
            }

            $ticket->update([
                'status' => 'cancelled',
                'lock_version' => $ticket->lock_version + 1,
            ]);

            if ($ticket->appointment_id !== null) {
                $appointment = Appointment::query()->lockForUpdate()->findOrFail($ticket->appointment_id);

                if (! in_array($appointment->status, ['cancelled', 'completed'], true)) {
                    $appointment->update(['status' => 'cancelled']);
                }
            }
        });
    }

    public function setManualDiscount(Ticket $ticket, float $amount, ?string $reason, int $actorId): void
    {
        DB::transaction(function () use ($ticket, $amount, $reason, $actorId): void {
            $ticket = Ticket::query()->with(['adjustments', 'payments'])->lockForUpdate()->findOrFail($ticket->id);

            if ($ticket->status === 'paid') {
                throw ValidationException::withMessages(['discount_amount' => 'No puedes modificar descuentos de un ticket pagado.']);
            }

            $manualDiscount = $ticket->adjustments->first(fn (TicketAdjustment $adjustment): bool => (bool) ($adjustment->metadata['manual_discount'] ?? false));
            $otherAdjustments = (float) $ticket->adjustments
                ->reject(fn (TicketAdjustment $adjustment): bool => $manualDiscount?->is($adjustment) ?? false)
                ->sum('amount');
            $paidTotal = (float) $ticket->payments->where('status', 'registered')->sum('amount');
            $maximumDiscount = max(0, $this->listedTotal($ticket) + $otherAdjustments - $paidTotal);

            if ($amount < 0 || $amount > $maximumDiscount + 0.01) {
                throw ValidationException::withMessages(['discount_amount' => 'El descuento no puede superar el saldo disponible del ticket.']);
            }

            if ($amount <= 0.001) {
                $manualDiscount?->delete();
            } elseif ($manualDiscount !== null) {
                $manualDiscount->update([
                    'amount' => -round($amount, 2),
                    'reason' => $reason ?: 'Descuento manual',
                    'created_by' => $actorId,
                ]);
            } else {
                TicketAdjustment::query()->create([
                    'ticket_id' => $ticket->id,
                    'type' => 'discount',
                    'amount' => -round($amount, 2),
                    'reason' => $reason ?: 'Descuento manual',
                    'created_by' => $actorId,
                    'metadata' => ['manual_discount' => true],
                ]);
            }

            $this->syncPricingTotals($ticket);
        });
    }

    public function syncPricingTotals(Ticket $ticket): Ticket
    {
        $listedTotal = $this->listedTotal($ticket);
        $adjustmentTotal = round((float) TicketAdjustment::query()->where('ticket_id', $ticket->id)->sum('amount'), 2);

        $ticket->forceFill([
            'listed_total' => $listedTotal,
            'discount_total' => max(0, -$adjustmentTotal),
            'charged_total' => max(0, $listedTotal + $adjustmentTotal),
        ])->save();

        return $ticket;
    }

    private function listedTotal(Ticket $ticket): float
    {
        return round((float) TicketItem::query()
            ->where('ticket_id', $ticket->id)
            ->where('status', 'active')
            ->sum('line_total'), 2);
    }

    private function ensureServicePricesConfirmed(Ticket $ticket): void
    {
        $hasUnconfirmedService = $ticket->items->contains(fn ($item): bool => $item->type === 'service' && $item->status === 'active' && ($item->metadata['price_confirmed'] ?? false) !== true);

        if ($hasUnconfirmedService) {
            throw ValidationException::withMessages(['ticket' => 'Confirma el tipo de precio de todos los servicios antes de continuar.']);
        }
    }

    private function registerServiceCommissions(Ticket $ticket): void
    {
        $serviceIds = $ticket->items
            ->where('type', 'service')
            ->where('status', 'active')
            ->map(fn (TicketItem $item): ?int => $item->metadata['salon_service_id'] ?? null)
            ->filter()
            ->unique();
        $services = SalonService::query()->whereKey($serviceIds)->get()->keyBy('id');

        foreach ($ticket->items->where('type', 'service')->where('status', 'active') as $item) {
            $metadata = $item->metadata ?? [];
            $employeeId = $metadata['employee_id'] ?? $ticket->appointment?->primary_employee_id;
            if (! $employeeId) {
                continue;
            }

            $employeeRate = (float) ($metadata['employee_commission_rate'] ?? $metadata['commission_rate'] ?? 0);
            $service = $services->get($metadata['salon_service_id'] ?? null);
            $serviceRate = (float) ($service?->commission_rate ?? 0);
            $serviceFixedAmount = $service?->commission_type === 'fixed'
                ? (float) ($service->commission_fixed_amount ?? 0)
                : 0;
            $rate = $employeeRate + ($service?->commission_type === 'percentage' ? $serviceRate : 0);

            $commission = CommissionEntry::query()->firstOrNew([
                'ticket_id' => $ticket->id,
                'ticket_item_id' => $item->id,
            ]);
            if ($commission->exists && $commission->payroll_item_id !== null) {
                continue;
            }

            $commission->fill([
                'employee_id' => $employeeId,
                'type' => 'service',
                'base_amount' => $item->line_total,
                'rate_snapshot' => $rate,
                'amount' => round((float) $item->line_total * ($rate / 100) + $serviceFixedAmount, 2),
                'status' => 'pending',
            ])->save();
        }
    }
}
