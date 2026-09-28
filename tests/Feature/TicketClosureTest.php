<?php

namespace Tests\Feature;

use App\Models\CashRegister;
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
}
