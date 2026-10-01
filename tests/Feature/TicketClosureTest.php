<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\CommissionEntry;
use App\Models\SalonService;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_liquidated_ticket_closes_its_appointment(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->with('appointment')->where('status', 'open')->firstOrFail();
        $cashRegister = CashRegister::query()->where('code', 'REC-01')->firstOrFail();

        $this->actingAs($user)->post(route('tickets.payments.store', $ticket), [
            'amount' => $ticket->balance,
            'method' => 'card',
            'cash_register_id' => $cashRegister->id,
        ]);
        $this->actingAs($user)->post(route('tickets.close', $ticket))
            ->assertRedirect(route('tickets.show', $ticket))
            ->assertSessionHas('success', 'Ticket cerrado y cita completada.');

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'paid']);
        $this->assertDatabaseHas('appointments', ['id' => $ticket->appointment->id, 'status' => 'completed']);
    }

    public function test_ticket_with_an_outstanding_balance_cannot_close_its_appointment(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->with('appointment')->where('status', 'open')->firstOrFail();

        $this->actingAs($user)->from(route('tickets.show', $ticket))
            ->post(route('tickets.close', $ticket))
            ->assertRedirect(route('tickets.show', $ticket))
            ->assertSessionHasErrors(['ticket' => 'El ticket debe estar liquidado antes de cerrarlo.']);

        $this->assertDatabaseHas('appointments', ['id' => $ticket->appointment->id, 'status' => $ticket->appointment->status]);
    }

    public function test_administrator_can_reopen_a_closed_ticket_without_removing_its_payment_audit(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->with('appointment')->where('status', 'open')->firstOrFail();
        $cashRegister = CashRegister::query()->where('code', 'REC-01')->firstOrFail();

        $this->actingAs($administrator)->post(route('tickets.payments.store', $ticket), [
            'amount' => $ticket->balance,
            'method' => 'card',
            'cash_register_id' => $cashRegister->id,
        ]);
        $this->actingAs($administrator)->post(route('tickets.close', $ticket));

        $this->actingAs($administrator)->post(route('tickets.reopen', $ticket))
            ->assertRedirect(route('tickets.show', $ticket));

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'open', 'paid_at' => null]);
        $this->assertDatabaseHas('payments', ['ticket_id' => $ticket->id, 'status' => 'registered']);
        $this->assertDatabaseHas('appointments', ['id' => $ticket->appointment->id, 'status' => 'confirmed']);

        $this->actingAs($administrator)->post(route('tickets.close', $ticket))
            ->assertRedirect(route('tickets.show', $ticket))
            ->assertSessionHas('success', 'Ticket cerrado y cita completada.');
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'paid']);
    }

    public function test_ticket_with_registered_payment_cannot_be_cancelled(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->where('status', 'open')->firstOrFail();
        $cashRegister = CashRegister::query()->where('code', 'REC-01')->firstOrFail();

        $this->actingAs($administrator)->post(route('tickets.payments.store', $ticket), [
            'amount' => min(100, $ticket->balance),
            'method' => 'cash',
            'cash_register_id' => $cashRegister->id,
        ]);

        $this->actingAs($administrator)->from(route('tickets.show', $ticket))
            ->post(route('tickets.cancel', $ticket))
            ->assertRedirect(route('tickets.show', $ticket))
            ->assertSessionHasErrors('ticket');

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'open']);
    }

    public function test_reclosing_an_unsettled_ticket_refreshes_its_service_commission(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->with('items')->where('status', 'open')->firstOrFail();
        $cashRegister = CashRegister::query()->where('code', 'REC-01')->firstOrFail();

        $this->actingAs($administrator)->post(route('tickets.payments.store', $ticket), [
            'amount' => $ticket->balance,
            'method' => 'card',
            'cash_register_id' => $cashRegister->id,
        ]);
        $this->actingAs($administrator)->post(route('tickets.close', $ticket));

        $item = $ticket->fresh('items')->items->firstWhere('type', 'service');
        $metadata = $item->metadata;
        $metadata['employee_commission_rate'] = 35;
        $item->update(['metadata' => $metadata]);
        $serviceRate = (float) SalonService::query()->findOrFail($metadata['salon_service_id'])->commission_rate;

        $this->actingAs($administrator)->post(route('tickets.reopen', $ticket));
        $this->actingAs($administrator)->post(route('tickets.close', $ticket));

        $commission = CommissionEntry::query()
            ->where('ticket_id', $ticket->id)
            ->where('ticket_item_id', $item->id)
            ->firstOrFail();
        $this->assertSame(number_format(35 + $serviceRate, 2, '.', ''), $commission->rate_snapshot);
        $this->assertSame(number_format((float) $item->line_total * ((35 + $serviceRate) / 100), 2, '.', ''), $commission->amount);
    }
}
