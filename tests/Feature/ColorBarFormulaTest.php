<?php

namespace Tests\Feature;

use App\Models\ColorFormula;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ColorBarFormulaTest extends TestCase
{
    use RefreshDatabase;

    public function test_color_bar_confirms_a_cart_of_products_and_deducts_only_its_location(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->whereHas('customer', fn ($query) => $query->where('email', 'fernanda.ruiz@example.com'))->firstOrFail();
        $variant = ProductVariant::query()->whereHas('product', fn ($query) => $query->where('is_color_bar_usable', true))->firstOrFail();
        $balance = InventoryBalance::query()->where('product_variant_id', $variant->id)->whereHas('location', fn ($query) => $query->where('code', 'CB'))->firstOrFail();
        $startingQuantity = (float) $balance->available_quantity;

        $this->actingAs($user)->post(route('color-bar.store'), [
            'ticket_id' => $ticket->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 35, 'unit' => 'g', 'notes' => 'Aplicación de raíz']],
            'notes' => 'Formula de prueba',
        ])->assertRedirect(route('color-bar.index', ['ticket' => $ticket->id]));

        $formula = ColorFormula::query()->where('ticket_id', $ticket->id)->latest()->firstOrFail();
        $this->assertDatabaseHas('color_formulas', ['id' => $formula->id, 'status' => 'confirmed', 'notes' => 'Formula de prueba']);
        $this->assertDatabaseHas('color_formula_items', ['color_formula_id' => $formula->id, 'product_variant_id' => $variant->id, 'notes' => 'Aplicación de raíz']);
        $this->assertDatabaseHas('inventory_movements', ['product_variant_id' => $variant->id, 'type' => 'color_consumption', 'reference_id' => $formula->id]);
        $this->assertSame($startingQuantity - 35, (float) $balance->fresh()->available_quantity);
    }

    public function test_correcting_a_formula_creates_a_reversal_and_a_replacement(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->whereHas('customer', fn ($query) => $query->where('email', 'fernanda.ruiz@example.com'))->firstOrFail();
        $variant = ProductVariant::query()->whereHas('product', fn ($query) => $query->where('is_color_bar_usable', true))->firstOrFail();
        $balance = InventoryBalance::query()->where('product_variant_id', $variant->id)->whereHas('location', fn ($query) => $query->where('code', 'CB'))->firstOrFail();
        $startingQuantity = (float) $balance->available_quantity;

        $this->actingAs($user)->post(route('color-bar.store'), [
            'ticket_id' => $ticket->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 35, 'unit' => 'g']],
        ]);
        $originalFormula = ColorFormula::query()->where('ticket_id', $ticket->id)->latest()->firstOrFail();
        $originalTicketItem = TicketItem::query()->where('metadata->color_formula_id', $originalFormula->id)->firstOrFail();

        $this->actingAs($user)->post(route('color-bar.update', $originalFormula), [
            'ticket_id' => $ticket->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 40, 'unit' => 'g']],
        ])->assertRedirect(route('color-bar.index', ['ticket' => $ticket->id]));

        $replacementFormula = ColorFormula::query()->where('supersedes_color_formula_id', $originalFormula->id)->firstOrFail();
        $this->assertDatabaseHas('color_formulas', ['id' => $originalFormula->id, 'status' => 'corrected']);
        $this->assertDatabaseHas('color_formulas', ['id' => $replacementFormula->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('inventory_movements', ['product_variant_id' => $variant->id, 'type' => 'color_correction_reversal', 'reference_id' => $originalFormula->id]);
        $this->assertSame('reversed', $originalTicketItem->fresh()->status);
        $this->assertSame($startingQuantity - 40, (float) InventoryBalance::query()->where('product_variant_id', $variant->id)->whereHas('location', fn ($query) => $query->where('code', 'CB'))->firstOrFail()->available_quantity);
        $this->assertSame(3, InventoryMovement::query()->whereIn('reference_id', [$originalFormula->id, $replacementFormula->id])->whereIn('type', ['color_consumption', 'color_correction_reversal'])->count());
    }

    public function test_reception_cannot_display_or_sell_color_bar_products(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->whereHas('customer', fn ($query) => $query->where('email', 'fernanda.ruiz@example.com'))->firstOrFail();
        $variant = ProductVariant::query()->whereHas('product', fn ($query) => $query->where('is_color_bar_usable', true))->firstOrFail();

        $this->actingAs($user)->get(route('tickets.show', $ticket))->assertDontSee($variant->product->name);
        $this->actingAs($user)->post(route('tickets.products.store', $ticket), ['product_variant_id' => $variant->id, 'quantity' => 1])->assertUnprocessable();
    }

    public function test_closed_ticket_rejects_new_color_bar_consumptions(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->whereHas('customer', fn ($query) => $query->where('email', 'fernanda.ruiz@example.com'))->firstOrFail();
        $ticket->update(['status' => 'paid']);
        $variant = ProductVariant::query()->whereHas('product', fn ($query) => $query->where('is_color_bar_usable', true))->firstOrFail();

        $this->actingAs($user)->from(route('color-bar.index', ['ticket' => $ticket->id]))->post(route('color-bar.store'), [
            'ticket_id' => $ticket->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 35, 'unit' => 'g']],
        ])->assertRedirect(route('color-bar.index', ['ticket' => $ticket->id]))->assertSessionHasErrors('ticket');
    }

    public function test_color_bar_role_cannot_close_a_ticket(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $user->syncRoles(['Color Bar']);
        $ticket = Ticket::query()->whereHas('customer', fn ($query) => $query->where('email', 'fernanda.ruiz@example.com'))->firstOrFail();

        $this->actingAs($user)->post(route('tickets.close', $ticket))->assertForbidden();
    }
}
