<?php

namespace Tests\Feature;

use App\Models\Promotion;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_create_and_apply_an_order_promotion_to_a_ticket(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->where('status', 'open')->firstOrFail();

        $this->actingAs($administrator)->post(route('promotions.store'), [
            'name' => 'Descuento de bienvenida', 'method' => 'automatic', 'type' => 'order',
            'value_type' => 'fixed', 'value' => 100, 'status' => 'active',
        ])->assertRedirect(route('promotions.index'));

        $promotion = Promotion::query()->where('name', 'Descuento de bienvenida')->firstOrFail();
        $this->actingAs($administrator)->post(route('promotions.apply', [$promotion, $ticket]))
            ->assertRedirect()->assertSessionHas('success', 'Promoción aplicada al ticket.');

        $this->assertDatabaseHas('ticket_adjustments', [
            'ticket_id' => $ticket->id, 'type' => 'discount', 'amount' => -100, 'reason' => 'Descuento de bienvenida',
        ]);
        $this->assertSame(1, $promotion->fresh()->usage_count);
    }

    public function test_promotion_catalog_is_restricted_to_administration(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($administrator)->get(route('promotions.index'))
            ->assertOk()->assertSee('Promos')->assertSee('Nueva promo');
    }
}
