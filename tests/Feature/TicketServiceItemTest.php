<?php

namespace Tests\Feature;

use App\Models\SalonService;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketServiceItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_can_add_a_service_without_changing_its_catalog_price(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->where('status', 'open')->firstOrFail();
        $service = SalonService::query()->where('name', 'Tinte')->firstOrFail();

        $this->actingAs($user)
            ->from(route('tickets.show', $ticket))
            ->post(route('tickets.services.store', $ticket), [
                'salon_service_id' => $service->id,
                'unit_price' => 1890,
            ])
            ->assertRedirect(route('tickets.show', $ticket))
            ->assertSessionHas('success', 'Servicio agregado al ticket.');

        $this->assertDatabaseHas('ticket_items', [
            'ticket_id' => $ticket->id,
            'type' => 'service',
            'name_snapshot' => 'Tinte',
            'unit_price' => 1890,
            'line_total' => 1890,
        ]);
        $this->assertSame('1500.00', $service->fresh()->base_price);
    }

    public function test_fixed_service_uses_the_selected_price_tier_instead_of_a_submitted_amount(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->where('status', 'open')->firstOrFail();
        $service = SalonService::query()->where('name', 'Corte')->firstOrFail();
        $service->prices()->where('tier', 'B')->update(['cost' => 220, 'sale_price' => 850]);

        $this->actingAs($user)
            ->post(route('tickets.services.store', $ticket), [
                'salon_service_id' => $service->id,
                'price_tier' => 'B',
                'unit_price' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ticket_items', [
            'ticket_id' => $ticket->id,
            'type' => 'service',
            'name_snapshot' => 'Corte',
            'unit_price' => 850,
            'line_total' => 850,
            'cost_snapshot' => 220,
            'metadata->price_tier' => 'B',
        ]);
    }

    public function test_reception_confirms_a_service_variant_from_the_ticket_modal_and_updates_the_line_total(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->where('status', 'open')->firstOrFail();
        $service = SalonService::query()->where('name', 'Corte')->firstOrFail();

        $this->actingAs($user)->from(route('tickets.show', $ticket))->post(route('tickets.services.store', $ticket), [
            'salon_service_id' => $service->id, 'price_tier' => 'A', 'unit_price' => 1,
        ])->assertRedirect(route('tickets.show', $ticket));
        $item = $ticket->fresh()->items()->where('name_snapshot', 'Corte')->latest('id')->firstOrFail();

        $this->actingAs($user)->get(route('tickets.show', $ticket))
            ->assertOk()->assertSee('! Confirmar tipo de precio')->assertSee('CONFIRMAR SERVICIO');
        $this->actingAs($user)->post(route('tickets.services.confirm', [$ticket, $item]), ['price_tier' => 'B'])
            ->assertRedirect(route('tickets.show', $ticket));

        $this->assertDatabaseHas('ticket_items', ['id' => $item->id, 'line_total' => 500, 'metadata->price_tier' => 'B', 'metadata->price_confirmed' => true]);
    }

    public function test_variable_service_requires_a_final_price_that_is_not_lower_than_its_configured_minimum(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->where('status', 'open')->firstOrFail();
        $service = SalonService::query()->where('name', 'Tinte')->firstOrFail();

        $this->actingAs($user)->from(route('tickets.show', $ticket))->post(route('tickets.services.store', $ticket), [
            'salon_service_id' => $service->id,
            'unit_price' => 1800,
        ])->assertRedirect(route('tickets.show', $ticket));
        $item = $ticket->fresh()->items()->where('name_snapshot', 'Tinte')->latest('id')->firstOrFail();

        $this->actingAs($user)->post(route('tickets.services.confirm', [$ticket, $item]), [
            'unit_price' => 1400,
        ])->assertSessionHasErrors('unit_price');

        $this->actingAs($user)->post(route('tickets.services.confirm', [$ticket, $item]), [
            'unit_price' => 2100,
        ])->assertRedirect(route('tickets.show', $ticket));

        $this->assertDatabaseHas('ticket_items', ['id' => $item->id, 'unit_price' => 2100, 'line_total' => 2100, 'metadata->price_confirmed' => true]);
    }
}
