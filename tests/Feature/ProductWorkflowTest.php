<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ProductSearch;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CrepeSeeder::class);
    }

    public function test_restricted_receptionist_can_change_only_reception_price_despite_legacy_invalid_limits(): void
    {
        $user = User::factory()->create(['name' => 'Lupis']);
        $user->assignRole('Recepción');
        AppSetting::put('products.reception_price_only_user_ids', json_encode([$user->id]));
        $variant = ProductVariant::query()->where('sku', 'SH-REST-STD')->firstOrFail();
        $variant->update(['minimum_stock' => 3, 'maximum_stock' => 0]);
        $before = $variant->fresh()->toArray();
        $moves = InventoryMovement::count();
        $this->actingAs($user)->withSession(['crepe.mode' => 'almacen'])->get(route('products.edit', $variant->product))->assertOk()->assertSee('Guardar precio de Recepción')->assertDontSee('name="cost"', false);
        $this->put(route('products.reception-price.update', $variant->product), ['sale_price' => 600, 'previous_price' => $variant->sale_price, 'cost' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('600.00', $variant->fresh()->sale_price);
        foreach (['cost', 'minimum_stock', 'maximum_stock', 'color_bar_sale_price', 'base_unit', 'content_quantity'] as $field) {
            $this->assertSame($before[$field], $variant->fresh()->$field);
        }
        $this->put(route('products.update', $variant->product), ['name' => 'Hacked'])->assertForbidden();
        $this->delete(route('products.destroy', $variant->product))->assertForbidden();
        $this->get(route('inventory.import.create'))->assertForbidden();
        $this->get(route('products.import.create'))->assertForbidden();
        $this->assertSame($moves, InventoryMovement::count());
        $this->put(route('products.reception-price.update', $variant->product), ['sale_price' => 700, 'previous_price' => $before['sale_price']])->assertSessionHasErrors('sale_price');
    }

    public function test_word_order_accents_and_brand_search_are_shared_by_catalog_and_matrix(): void
    {
        $admin = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $variant = ProductVariant::query()->firstOrFail();
        $variant->product->update(['name' => 'NUTRICIÓN ACEITE 30ML', 'brand' => 'TRUSS']);
        $this->actingAs($admin)->get(route('products.index', ['search' => 'truss aceite nutricion']))->assertOk()->assertSee('NUTRICIÓN ACEITE 30ML');
        $this->withSession(['crepe.mode' => 'almacen'])->get(route('inventory.index', ['search' => '30ml truss nutricion']))->assertOk()->assertSee('NUTRICIÓN ACEITE 30ML');
        $query = ProductSearch::products(Product::query(), 'truss inexistente');
        $this->assertSame(0, $query->count(), $query->toSql().' '.json_encode($query->getBindings()));
        $this->get(route('products.index', ['search' => 'truss inexistente']))->assertDontSee('NUTRICIÓN ACEITE 30ML');
    }

    public function test_supplier_filter_is_enforced_for_creation_and_draft_additions(): void
    {
        $admin = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $variant = ProductVariant::query()->with('product')->firstOrFail();
        $supplier = Supplier::query()->create(['name' => 'Otro proveedor']);
        $this->actingAs($admin)->post(route('purchase-orders.store'), ['supplier_id' => $supplier->id, 'items' => [['product_variant_id' => $variant->id, 'ordered_quantity' => 1]]])->assertSessionHasErrors('items.0.product_variant_id');
        $this->assertDatabaseCount('purchase_orders', 0);
        $variant->product->update(['preferred_supplier_id' => $supplier->id]);
        $this->post(route('purchase-orders.store'), ['supplier_id' => $supplier->id, 'items' => [['product_variant_id' => $variant->id, 'ordered_quantity' => 1]]])->assertSessionHasNoErrors();
        $order = PurchaseOrder::firstOrFail();
        $other = ProductVariant::query()->whereKeyNot($variant->id)->firstOrFail();
        $this->post(route('purchase-orders.items.store', $order), ['product_variant_id' => $other->id, 'ordered_quantity' => 1])->assertSessionHasErrors('product_variant_id');
        $this->assertSame(1, $order->items()->count());
    }

    public function test_product_creation_initializes_three_zero_balances_without_movements(): void
    {
        $admin = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $moves = InventoryMovement::count();
        $this->actingAs($admin)->post(route('products.store'), ['name' => 'Nuevo aceite', 'sku' => 'NUEVO-ACEITE', 'product_category_id' => ProductCategory::firstOrFail()->id, 'status' => 'active', 'is_reception_usable' => 1, 'variant_name' => 'Unidad', 'cost' => '10.00', 'sale_price' => '20.00', 'minimum_stock' => 2, 'maximum_stock' => 5])->assertSessionHasNoErrors();
        $product = Product::where('sku', 'NUEVO-ACEITE')->firstOrFail();
        $this->assertCount(3, $product->variants->first()->balances);
        $this->assertSame(0.0, (float) $product->variants->first()->balances->sum('available_quantity'));
        $this->assertSame($moves, InventoryMovement::count());
        $this->get(route('inventory.index', ['location' => 'ALM', 'search' => 'aceite nuevo']))->assertOk()->assertSee('Nuevo aceite');
    }
}
