<?php

namespace Tests\Feature;

use App\Models\ColorFormula;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ColorBarInventoryTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['Color Bar'])]
    #[TestWith(['Administrador'])]
    public function test_staff_can_deplete_complete_packages_only_at_color_bar(string $role): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $user->syncRoles([$role]);
        $balance = $this->packagedSupply();
        $otherBalances = InventoryBalance::query()->whereKeyNot($balance->id)->orderBy('id')->get()->toArray();
        $beforeMovements = InventoryMovement::query()->count();

        $this->actingAs($user)->get(route('inventory.index', ['location' => 'CB']))
            ->assertOk()->assertSee('Registrar envases agotados')->assertSee('Cada envase cuenta como una unidad');
        $this->post(route('inventory.color-bar.deplete', $balance), ['units' => 1, 'notes' => 'Se terminó el bote abierto'])
            ->assertRedirect(route('inventory.index', ['location' => 'CB']))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 1]);
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_location_id' => $balance->inventory_location_id,
            'product_variant_id' => $balance->product_variant_id,
            'type' => 'color_bar_depletion', 'quantity' => -1, 'before_quantity' => 2, 'after_quantity' => 1,
            'reference_type' => InventoryBalance::class, 'reference_id' => $balance->id, 'created_by' => $user->id,
            'reason' => '1 envase agotado en Color Bar. Se terminó el bote abierto',
        ]);
        $this->assertDatabaseCount('inventory_movements', $beforeMovements + 1);
        $this->assertSame($otherBalances, InventoryBalance::query()->whereKeyNot($balance->id)->orderBy('id')->get()->toArray());
        $this->withSession(['crepe.mode' => 'color-bar'])->get(route('activity.index', ['location' => 'CB']))
            ->assertOk()->assertSee('Envases agotados · Color Bar')->assertSee('Se terminó el bote abierto');
    }

    #[TestWith([null])]
    #[TestWith([0])]
    #[TestWith([-1])]
    #[TestWith([0.5])]
    #[TestWith([3])]
    public function test_invalid_or_excessive_package_depletion_does_not_change_inventory(?float $units): void
    {
        $this->seed(CrepeSeeder::class);
        $balance = $this->packagedSupply();
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $beforeBalances = InventoryBalance::query()->orderBy('id')->get()->toArray();
        $beforeMovements = InventoryMovement::query()->count();

        $this->actingAs($user)->post(route('inventory.color-bar.deplete', $balance), ['units' => $units])->assertSessionHasErrors('units');

        $this->assertSame($beforeBalances, InventoryBalance::query()->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('inventory_movements', $beforeMovements);
    }

    public function test_depletion_uses_current_stock_and_cannot_deplete_an_already_empty_balance(): void
    {
        $this->seed(CrepeSeeder::class);
        $balance = $this->packagedSupply();
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $this->actingAs($user)->post(route('inventory.color-bar.deplete', $balance), ['units' => 2])->assertSessionHasNoErrors();
        $beforeMovements = InventoryMovement::query()->count();

        $this->post(route('inventory.color-bar.deplete', $balance), ['units' => 1])
            ->assertSessionHasErrors(['units' => 'No hay suficientes envases registrados para esta baja.']);

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 0]);
        $this->assertDatabaseCount('inventory_movements', $beforeMovements);
    }

    public function test_staff_can_mark_a_remaining_partial_balance_as_completely_exhausted(): void
    {
        $this->seed(CrepeSeeder::class);
        $balance = $this->packagedSupply();
        $balance->update(['available_quantity' => 1]);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $beforeMovements = InventoryMovement::query()->count();

        $this->actingAs($user)->post(route('inventory.color-bar.deplete', $balance), ['empty_stock' => 1])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 0]);
        $this->assertDatabaseHas('inventory_movements', ['reference_type' => InventoryBalance::class, 'reference_id' => $balance->id, 'type' => 'color_bar_depletion', 'quantity' => -1, 'before_quantity' => 1, 'after_quantity' => 0, 'created_by' => $user->id]);
        $this->assertDatabaseCount('inventory_movements', $beforeMovements + 1);
    }

    public function test_depletion_requires_color_bar_access_and_rejects_other_locations(): void
    {
        $this->seed(CrepeSeeder::class);
        $balance = $this->packagedSupply();
        $otherLocation = InventoryBalance::query()->whereHas('location', fn ($query) => $query->where('code', 'ALM'))->firstOrFail();
        $warehouse = User::factory()->create();
        $warehouse->assignRole('Almacén');
        $admin = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $beforeBalances = InventoryBalance::query()->orderBy('id')->get()->toArray();
        $beforeMovements = InventoryMovement::query()->count();

        $this->post(route('inventory.color-bar.deplete', $balance), ['units' => 1])->assertRedirect(route('login'));
        $this->actingAs($warehouse)->post(route('inventory.color-bar.deplete', $balance), ['units' => 1])->assertForbidden();
        $this->actingAs($admin)->post(route('inventory.color-bar.deplete', $otherLocation), ['units' => 1])->assertNotFound();

        $this->assertSame($beforeBalances, InventoryBalance::query()->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('inventory_movements', $beforeMovements);
    }

    public function test_depletion_rejects_a_supply_that_is_not_assigned_to_color_bar(): void
    {
        $this->seed(CrepeSeeder::class);
        $balance = $this->packagedSupply();
        $balance->variant->product->update(['is_color_bar_usable' => false]);
        $admin = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $beforeMovements = InventoryMovement::query()->count();

        $this->actingAs($admin)->post(route('inventory.color-bar.deplete', $balance), ['units' => 1])->assertUnprocessable();

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 2]);
        $this->assertDatabaseCount('inventory_movements', $beforeMovements);
    }

    public function test_a_formula_can_use_grams_when_the_supply_is_inventoried_in_another_unit(): void
    {
        $this->seed(CrepeSeeder::class);
        $balance = $this->packagedSupply();
        $balance->variant->update(['base_unit' => 'ml', 'color_bar_pricing_type' => 'multiplier']);
        $balance->variant->product->category->update(['color_bar_multiplier' => 3.7]);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->whereHas('customer', fn ($query) => $query->where('email', 'fernanda.ruiz@example.com'))->firstOrFail();
        $ticket->appointment->update(['starts_at' => now(), 'ends_at' => now()->addHours(2)]);
        $beforeBalances = InventoryBalance::query()->orderBy('id')->get()->toArray();
        $beforeMovements = InventoryMovement::query()->count();

        $this->actingAs($user)->get(route('color-bar.index', ['ticket' => $ticket->id]))
            ->assertOk()
            ->assertViewHas('variants', fn ($variants): bool => $variants->contains('id', $balance->product_variant_id))
            ->assertSee('Las fórmulas no modifican existencias.')->assertDontSee('de saldo registrado');
        $this->post(route('color-bar.store'), ['ticket_id' => $ticket->id, 'items' => [
            ['product_variant_id' => $balance->product_variant_id, 'quantity' => 27.125, 'unit' => 'g'],
        ]])->assertSessionHasNoErrors();

        $formula = ColorFormula::query()->where('ticket_id', $ticket->id)->sole();
        $this->assertDatabaseHas('color_formula_items', ['color_formula_id' => $formula->id, 'quantity' => 27.125, 'unit' => 'g']);
        $this->assertSame($beforeBalances, InventoryBalance::query()->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('inventory_movements', $beforeMovements);
    }

    public function test_correcting_a_legacy_formula_preserves_previous_inventory_movements_and_balances(): void
    {
        $this->seed(CrepeSeeder::class);
        $formula = ColorFormula::query()->with('ticket')->firstOrFail();
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $beforeBalances = InventoryBalance::query()->orderBy('id')->get()->toArray();
        $beforeMovements = InventoryMovement::query()->orderBy('id')->get()->toArray();

        $this->actingAs($user)->post(route('color-bar.update', $formula), ['ticket_id' => $formula->ticket_id, 'items' => []])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('color_formulas', ['id' => $formula->id, 'status' => 'corrected']);
        $this->assertDatabaseHas('color_formulas', ['supersedes_color_formula_id' => $formula->id, 'status' => 'confirmed']);
        $this->assertSame($beforeBalances, InventoryBalance::query()->orderBy('id')->get()->toArray());
        $this->assertSame($beforeMovements, InventoryMovement::query()->orderBy('id')->get()->toArray());
    }

    private function packagedSupply(): InventoryBalance
    {
        $balance = InventoryBalance::query()->with('variant.product')->whereHas('location', fn ($query) => $query->where('code', 'CB'))
            ->whereHas('variant', fn ($query) => $query->where('base_unit', 'g')->whereHas('product', fn ($products) => $products->where('is_color_bar_usable', true)))->firstOrFail();
        $balance->variant->update(['content_quantity' => 1000]);
        $balance->update(['available_quantity' => 2]);

        return $balance;
    }
}
