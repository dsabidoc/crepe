<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_reception_can_create_an_anonymous_product_sale_and_add_a_product(): void
    {
        $this->seed(CrepeSeeder::class);
        $receptionist = User::factory()->create();
        $receptionist->assignRole('Recepción');
        $variant = ProductVariant::query()->where('sku', 'SH-REST-STD')->firstOrFail();

        $this->actingAs($receptionist)
            ->withSession(['crepe.mode' => 'recepcion'])
            ->get(route('workspace', ['mode' => 'recepcion']))
            ->assertOk()
            ->assertSee(route('tickets.product-sales.create'), false);

        $this->actingAs($receptionist)
            ->post(route('tickets.product-sales.store'))
            ->assertRedirect();

        $ticket = Ticket::query()->latest('id')->firstOrFail();
        $this->assertSame('product_sale', $ticket->ticket_type);
        $this->assertNull($ticket->customer_id);

        $this->actingAs($receptionist)
            ->post(route('tickets.products.store', $ticket), [
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ticket_items', [
            'ticket_id' => $ticket->id,
            'type' => 'product',
            'name_snapshot' => 'Shampoo Restore',
        ]);
        $this->actingAs($receptionist)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Venta de mostrador')
            ->assertSee('VENTA DE PRODUCTO');
    }

    public function test_reception_can_create_a_product_sale_for_an_existing_customer(): void
    {
        $this->seed(CrepeSeeder::class);
        $receptionist = User::factory()->create();
        $receptionist->assignRole('Recepción');
        $customer = Customer::query()->where('email', 'maria.lopez@example.com')->firstOrFail();

        $this->actingAs($receptionist)
            ->post(route('tickets.product-sales.store'), [
                'customer_id' => $customer->id,
                'customer_name' => $customer->full_name,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tickets', [
            'customer_id' => $customer->id,
            'ticket_type' => 'product_sale',
            'appointment_id' => null,
        ]);
    }

    public function test_color_bar_cannot_create_a_public_product_sale(): void
    {
        $this->seed(CrepeSeeder::class);
        $receptionist = User::factory()->create();
        $receptionist->assignRole('Recepción');
        $this->actingAs($receptionist)
            ->post(route('tickets.product-sales.store'))
            ->assertRedirect();
        $productSale = Ticket::query()->latest('id')->firstOrFail();
        $colorBar = User::factory()->create();
        $colorBar->assignRole('Color Bar');

        $this->actingAs($colorBar)
            ->get(route('tickets.product-sales.create'))
            ->assertForbidden();

        $this->actingAs($colorBar)
            ->post(route('tickets.product-sales.store'))
            ->assertForbidden();

        $this->actingAs($colorBar)
            ->withSession(['crepe.mode' => 'color-bar'])
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertDontSee($productSale->code);

        $this->actingAs($colorBar)
            ->get(route('tickets.show', $productSale))
            ->assertForbidden();
    }
}
