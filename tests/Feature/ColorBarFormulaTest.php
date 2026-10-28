<?php

namespace Tests\Feature;

use App\Models\ColorFormula;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\ProductCategory;
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

    public function test_color_bar_confirms_a_cart_of_products_without_changing_inventory(): void
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
        $this->assertDatabaseMissing('inventory_movements', ['product_variant_id' => $variant->id, 'type' => 'color_consumption', 'reference_id' => $formula->id]);
        $this->assertSame($startingQuantity, (float) $balance->fresh()->available_quantity);
    }

    public function test_correcting_a_formula_replaces_its_charges_without_changing_inventory(): void
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
        $this->assertDatabaseMissing('inventory_movements', ['product_variant_id' => $variant->id, 'type' => 'color_correction_reversal', 'reference_id' => $originalFormula->id]);
        $this->assertSame('reversed', $originalTicketItem->fresh()->status);
        $this->assertSame($startingQuantity, (float) InventoryBalance::query()->where('product_variant_id', $variant->id)->whereHas('location', fn ($query) => $query->where('code', 'CB'))->firstOrFail()->available_quantity);
        $this->assertSame(0, InventoryMovement::query()->whereIn('reference_id', [$originalFormula->id, $replacementFormula->id])->whereIn('type', ['color_consumption', 'color_correction_reversal'])->count());
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

    public function test_formula_uses_separate_rate_group_and_preserves_inventory_when_catalog_category_changes(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::where('email', 'hi@davidsabido.com')->firstOrFail();
        $variant = ProductVariant::where('sku', 'KOL-71-STD')->firstOrFail();
        $variant->update(['color_bar_pricing_type' => 'multiplier']);
        $rateCategory = $variant->product->category;
        $rateCategory->update(['color_bar_multiplier' => 4]);
        $catalogCategory = ProductCategory::where('name', 'Retail')->firstOrFail();
        $variant->product->update(['product_category_id' => $catalogCategory->id, 'color_bar_rate_category_id' => $rateCategory->id]);
        $before = InventoryBalance::pluck('available_quantity', 'id')->all();
        $ticket = $this->ticketForToday();
        $this->actingAs($user)->get(route('color-bar.index', ['ticket' => $ticket->id]))->assertOk()->assertSee('data-rate="4.0000"', false)->assertViewHas('variants', fn ($variants) => $variants->contains('id', $variant->id));
        $this->post(route('color-bar.store'), ['ticket_id' => $ticket->id, 'items' => [['product_variant_id' => $variant->id, 'quantity' => 10, 'unit' => 'g']]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('color_formula_items', ['product_variant_id' => $variant->id, 'quantity' => 10, 'sale_price' => 4]);
        $this->assertSame($before, InventoryBalance::pluck('available_quantity', 'id')->all());
    }

    private function ticketForToday(): Ticket
    {
        $ticket = Ticket::query()
            ->whereHas('customer', fn ($query) => $query->where('email', 'fernanda.ruiz@example.com'))
            ->with('appointment')
            ->firstOrFail();
        $ticket->appointment->update([
            'starts_at' => today()->setTime(14, 0),
            'ends_at' => today()->setTime(16, 0),
        ]);

        return $ticket->fresh('appointment');
    }
}
