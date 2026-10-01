<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryReplenishmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_minimum_creates_one_replenishment_order_for_the_preferred_supplier(): void
    {
        $this->seed(CrepeSeeder::class);
        $supplier = Supplier::query()->create(['name' => 'Proveedor de prueba']);
        $product = Product::query()->where('sku', 'SH-REST')->firstOrFail();
        $product->update(['preferred_supplier_id' => $supplier->id]);
        $variant = ProductVariant::query()->where('sku', 'SH-REST-STD')->firstOrFail();
        $warehouse = InventoryLocation::query()->where('code', 'ALM')->firstOrFail();
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        app(InventoryService::class)->move($variant->id, $warehouse->id, -32, 'adjustment', $administrator->id);
        app(InventoryService::class)->move($variant->id, $warehouse->id, -1, 'adjustment', $administrator->id);

        $order = PurchaseOrder::query()->where('supplier_id', $supplier->id)->firstOrFail();
        $this->assertSame('draft', $order->status);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame('20.000', $order->items()->firstOrFail()->ordered_quantity);
    }
}
