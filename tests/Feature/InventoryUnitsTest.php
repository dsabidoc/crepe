<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryRequest;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\InventorySpreadsheetService;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class InventoryUnitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reading_inventory_and_history_uses_units_without_rewriting_saved_amounts(): void
    {
        [$user, $variant] = $this->inventory();
        $beforeBalances = InventoryBalance::query()->orderBy('id')->get()->toArray();
        $beforeMovements = InventoryMovement::query()->orderBy('id')->get()->toArray();

        foreach (['ALM', 'REC', 'CB'] as $location) {
            $this->actingAs($user)->get(route('inventory.index', ['location' => $location]))
                ->assertOk()->assertSee('Unidades')->assertDontSee(' g por envase');
        }
        $this->get(route('activity.index', ['location' => 'ALM']))->assertOk()->assertSee('unidades');

        $this->assertSame($beforeBalances, InventoryBalance::query()->orderBy('id')->get()->toArray());
        $this->assertSame($beforeMovements, InventoryMovement::query()->orderBy('id')->get()->toArray());
        $this->assertSame('g', $variant->fresh()->base_unit);
        $this->assertSame('1000.000', $variant->fresh()->content_quantity);
    }

    public function test_two_requested_products_move_two_units_regardless_of_legacy_content(): void
    {
        [$user, $variant, $warehouse, $colorBar] = $this->inventory();
        $beforeMovements = InventoryMovement::query()->orderBy('id')->get()->toArray();
        $this->actingAs($user)->withSession(['crepe.mode' => 'color-bar'])
            ->post(route('inventory.requests.store'), ['location' => 'CB', 'items' => [['product_variant_id' => $variant->id, 'requested_units' => 2]]])
            ->assertSessionHasNoErrors();
        $request = InventoryRequest::query()->with('items')->sole();
        $item = $request->items->sole();
        $this->assertSame('2.000', $item->requested_quantity);
        $this->assertSame('unidad', $item->unit);

        $this->get(route('inventory.requests.edit', $request))->assertOk()->assertSee('Disponible: 8 unidades');
        $this->post(route('inventory.requests.close', $request), ['items' => [$item->id => 2]])->assertSessionHasNoErrors();

        $this->assertSame('6.000', $warehouse->fresh()->available_quantity);
        $this->assertSame('5.000', $colorBar->fresh()->available_quantity);
        $this->assertDatabaseHas('inventory_request_items', ['id' => $item->id, 'delivered_units' => 2, 'delivered_quantity' => 2]);
        $this->assertDatabaseHas('inventory_movements', ['reference_type' => InventoryRequest::class, 'reference_id' => $request->id, 'type' => 'transfer_out', 'quantity' => -2]);
        $this->assertSame($beforeMovements, InventoryMovement::query()->whereIn('id', array_column($beforeMovements, 'id'))->orderBy('id')->get()->toArray());
    }

    public function test_depleting_two_envases_subtracts_two_inventory_units(): void
    {
        [$user, , , $colorBar] = $this->inventory();
        $this->actingAs($user)->post(route('inventory.color-bar.deplete', $colorBar), ['units' => 2])->assertSessionHasNoErrors();

        $this->assertSame('1.000', $colorBar->fresh()->available_quantity);
        $this->assertDatabaseHas('inventory_movements', ['inventory_location_id' => $colorBar->inventory_location_id, 'product_variant_id' => $colorBar->product_variant_id, 'type' => 'color_bar_depletion', 'quantity' => -2]);
    }

    #[TestWith(['requested_units'])]
    #[TestWith(['requested_quantity'])]
    public function test_fractional_inventory_requests_are_rejected(string $field): void
    {
        [$user, $variant] = $this->inventory();
        $this->actingAs($user)->withSession(['crepe.mode' => 'color-bar'])
            ->post(route('inventory.requests.store'), ['location' => 'CB', 'items' => [['product_variant_id' => $variant->id, $field => 1.5]]])
            ->assertSessionHasErrors('items.0.'.$field);
        $this->assertDatabaseCount('inventory_requests', 0);
    }

    #[TestWith(['adjustment'])]
    #[TestWith(['color_consumption'])]
    public function test_fractional_inventory_movements_are_rejected_without_recording_a_movement(string $type): void
    {
        [$user, $variant, $warehouse] = $this->inventory();
        $before = InventoryMovement::query()->count();
        try {
            app(InventoryService::class)->move($variant->id, $warehouse->inventory_location_id, -.5, $type, $user->id);
            $this->fail('A fractional inventory adjustment was accepted.');
        } catch (ValidationException $error) {
            $this->assertSame(['El inventario se registra en unidades enteras. Los gramos se capturan únicamente en las fórmulas.'], $error->errors()['quantity']);
        }
        $this->assertSame('8.000', $warehouse->fresh()->available_quantity);
        $this->assertDatabaseCount('inventory_movements', $before);
    }

    public function test_inventory_exports_units_and_keeps_the_saved_stock_quantity(): void
    {
        [, $variant] = $this->inventory();
        $csv = app(InventorySpreadsheetService::class)->export('csv', 'ALM');
        $rows = array_map(fn (string $line): array => str_getcsv($line, escape: ''), array_filter(explode("\n", $csv)));
        $row = collect($rows)->first(fn (array $row): bool => ($row[1] ?? null) === $variant->sku);

        $this->assertSame('unidad', $row[7]);
        $this->assertSame('8', $row[8]);
        $this->assertSame('8', $row[9]);
    }

    public function test_product_updates_preserve_legacy_metadata_and_keep_formula_pricing_independent(): void
    {
        [$user, $variant] = $this->inventory();
        $product = $variant->product;
        $beforeBalances = InventoryBalance::query()->orderBy('id')->get()->toArray();
        $beforeMovements = InventoryMovement::query()->orderBy('id')->get()->toArray();
        $this->actingAs($user)->get(route('products.edit', $product))->assertOk()->assertSee('Unidad de inventario')->assertDontSee('name="base_unit"', false);
        $this->put(route('products.update', $product), [
            'product_category_id' => $product->product_category_id, 'name' => $product->name, 'sku' => $product->sku, 'status' => 'active',
            'variant_name' => $variant->name, 'variant_sku' => $variant->sku, 'cost' => $variant->cost,
            'base_unit' => 'unidad', 'content_quantity' => 1,
            'minimum_stock' => 2, 'maximum_stock' => 8, 'is_color_bar_usable' => true, 'is_color_bar_supply' => true,
            'color_bar_pricing_type' => 'both', 'color_bar_sale_price' => 125,
        ])->assertSessionHasNoErrors();

        $this->assertSame('g', $variant->fresh()->base_unit);
        $this->assertSame('1000.000', $variant->fresh()->content_quantity);
        $this->assertSame('both', $variant->fresh()->color_bar_pricing_type);
        $this->assertSame($beforeBalances, InventoryBalance::query()->orderBy('id')->get()->toArray());
        $this->assertSame($beforeMovements, InventoryMovement::query()->orderBy('id')->get()->toArray());
    }

    public function test_fractional_delivery_keeps_the_request_pending_and_stock_unchanged(): void
    {
        [$user, $variant, $warehouse] = $this->inventory();
        $this->actingAs($user)->withSession(['crepe.mode' => 'color-bar'])
            ->post(route('inventory.requests.store'), ['location' => 'CB', 'items' => [['product_variant_id' => $variant->id, 'requested_units' => 2]]])->assertSessionHasNoErrors();
        $request = InventoryRequest::query()->with('items')->sole();
        $item = $request->items->sole();

        $this->post(route('inventory.requests.close', $request), ['items' => [$item->id => 1.5]])->assertSessionHasErrors('items.'.$item->id);

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('8.000', $warehouse->fresh()->available_quantity);
    }

    public function test_purchase_receipts_require_whole_units_and_keep_money_decimals(): void
    {
        [$user, $variant, $warehouse] = $this->inventory();
        $supplier = Supplier::factory()->create(['name' => 'Proveedor de unidades']);
        $order = PurchaseOrder::query()->create(['code' => 'OC-UNITS', 'supplier_id' => $supplier->id]);
        $item = $order->items()->create(['product_variant_id' => $variant->id, 'ordered_quantity' => 2, 'cost_snapshot' => 10]);
        $this->actingAs($user)->post(route('purchase-orders.receive', $order), ['amount' => 22.35, 'items' => [$item->id => 1.5]])->assertSessionHasErrors('items.'.$item->id);
        $this->assertSame('8.000', $warehouse->fresh()->available_quantity);

        $this->post(route('purchase-orders.receive', $order), ['amount' => 22.35, 'items' => [$item->id => 2]])->assertSessionHasNoErrors();

        $this->assertSame('10.000', $warehouse->fresh()->available_quantity);
        $this->assertDatabaseHas('payable_invoices', ['purchase_order_id' => $order->id, 'amount' => 22.35]);
    }

    public function test_automatic_replenishment_orders_at_least_one_whole_unit(): void
    {
        [$user, $variant, $warehouse] = $this->inventory();
        $supplier = Supplier::factory()->create(['name' => 'Proveedor de unidades']);
        $variant->product->update(['preferred_supplier_id' => $supplier->id]);
        $variant->update(['minimum_stock' => 0, 'maximum_stock' => 0]);
        $warehouse->update(['available_quantity' => 2]);

        app(InventoryService::class)->move($variant->id, $warehouse->inventory_location_id, -2, 'sale', $user->id);

        $order = PurchaseOrder::query()->where('supplier_id', $supplier->id)->sole();
        $this->assertSame('1.000', $order->items()->sole()->ordered_quantity);
    }

    /** @return array{User, ProductVariant, InventoryBalance, InventoryBalance} */
    private function inventory(): array
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $variant = ProductVariant::query()->with('product')->where('sku', 'KOL-71-STD')->firstOrFail();
        $variant->update(['content_quantity' => 1000]);
        $variant->product->category->update(['color_bar_multiplier' => 3.7]);
        $warehouse = $variant->balances()->whereHas('location', fn ($query) => $query->where('code', 'ALM'))->firstOrFail();
        $colorBar = $variant->balances()->whereHas('location', fn ($query) => $query->where('code', 'CB'))->firstOrFail();
        $warehouse->update(['available_quantity' => 8]);
        $colorBar->update(['available_quantity' => 3]);

        return [$user, $variant, $warehouse, $colorBar];
    }
}
