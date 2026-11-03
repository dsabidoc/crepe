<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventorySpreadsheetService;
use App\Services\SpreadsheetFile;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilteredInventoryReplenishmentTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['csv'])]
    #[TestWith(['xlsx'])]
    public function test_export_matches_search_brand_category_supplier_and_minimum_on_all_pages_and_can_be_uploaded_unchanged(string $format): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::where('email', 'hi@davidsabido.com')->sole();
        $category = ProductCategory::where('name', 'Retail')->sole();
        $supplier = Supplier::factory()->create(['name' => 'Proveedor filtro']);
        $expected = [];
        foreach (range(1, 35) as $number) {
            $variant = $this->variant('Repuesto '.$number, $supplier, 1, 2, 8);
            $expected[] = $variant->sku;
        }
        $above = $this->variant('Repuesto suficiente', $supplier, 9, 2, 8);
        $otherBrand = $this->variant('Repuesto otra marca', $supplier, 1, 2, 8);
        $otherBrand->product->update(['brand' => 'Otra marca']);
        $otherCategory = $this->variant('Repuesto otra categoría', $supplier, 1, 2, 8);
        $otherCategory->product->update(['product_category_id' => ProductCategory::where('name', 'Tinte SCH')->sole()->id]);
        $inactive = $this->variant('Repuesto inactivo', $supplier, 1, 2, 8);
        $inactive->product->update(['status' => 'inactive']);
        $this->variant('Repuesto otro proveedor', Supplier::factory()->create(['name' => 'Otro proveedor']), 1, 2, 8);
        $this->variant('Repuesto sin proveedor', null, 1, 2, 8);
        $filters = ['search' => 'ordenada repuesto', 'brand' => 'Marca ordenada', 'category' => $category->id, 'supplier' => $supplier->id, 'low_stock' => 1];
        $before = InventoryBalance::orderBy('id')->get()->toArray();
        $movements = InventoryMovement::count();

        $page = $this->actingAs($admin)->withSession(['crepe.mode' => 'almacen'])->get(route('inventory.index', $filters));
        $page->assertViewHas('variants', fn ($variants) => $variants->total() === 35 && $variants->count() === 30)->assertSee('Enviar a orden de compra');
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$page->getContent());
        $xpath = new \DOMXPath($dom);
        foreach (['Descargar Excel', 'Descargar CSV'] as $label) {
            $href = $xpath->query('//a[normalize-space(.)="'.$label.'"]')->item(0)->getAttribute('href');
            parse_str(parse_url($href, PHP_URL_QUERY), $query);
            $this->assertSame('Marca ordenada', $query['brand']);
            $this->assertSame('1', $query['low_stock']);
            $this->assertSame((string) $supplier->id, $query['supplier']);
            $this->assertArrayNotHasKey('page', $query);
        }
        $nextPage = $xpath->query('//a[@aria-label="Go to page 2"]')->item(0)->getAttribute('href');
        parse_str(parse_url($nextPage, PHP_URL_QUERY), $nextQuery);
        $this->assertSame((string) $supplier->id, $nextQuery['supplier']);
        $response = $this->get(route('inventory.export', $filters + ['format' => $format, 'location_code' => 'ALM', 'page' => 2]));
        $response->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'filtered-inventory-');
        file_put_contents($path, $response->getContent());
        try {
            $rows = app(SpreadsheetFile::class)->read($path, $format, 'Inventario', 16);
            unset($rows[1]);
            $actual = array_column($rows, 'B');
            sort($expected);
            sort($actual);
            $this->assertSame($expected, $actual);
            $preview = app(InventorySpreadsheetService::class)->preview($path, $format, 'ALM');
            $this->assertSame(35, $preview['unchanged']);
            $this->assertSame([], $preview['changes']);
        } finally {
            unlink($path);
        }
        $this->assertSame($before, InventoryBalance::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('inventory_movements', $movements);
    }

    public function test_supplier_filter_lists_all_its_stock_and_only_sends_its_low_stock_to_a_draft(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::where('email', 'hi@davidsabido.com')->sole();
        $supplier = Supplier::factory()->create(['name' => 'Proveedor elegido']);
        $otherSupplier = Supplier::factory()->create(['name' => 'Proveedor distinto']);
        $low = $this->variant('Repuesto por pedir', $supplier, 1, 2, 8);
        $enough = $this->variant('Repuesto suficiente', $supplier, 9, 2, 8);
        $this->variant('Repuesto otro proveedor', $otherSupplier, 1, 2, 8);
        $this->variant('Repuesto sin proveedor', null, 1, 2, 8);
        $before = InventoryBalance::orderBy('id')->get()->toArray();
        $movements = InventoryMovement::count();
        $filters = ['supplier' => $supplier->id];

        $page = $this->actingAs($admin)->withSession(['crepe.mode' => 'almacen'])->get(route('inventory.index', $filters));

        $page->assertViewHas('variants', fn ($variants) => $variants->total() === 2)
            ->assertSee($low->product->name)->assertSee($enough->product->name)->assertSee('Filtrar por proveedor');
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$page->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame('Proveedor elegido', $xpath->query('//select[@name="supplier"]/option[@selected]')->item(0)->textContent);
        $this->get(route('inventory.matrix', $filters))->assertRedirect(route('inventory.index', $filters + ['location' => 'ALM']));
        $this->get(route('inventory.index', ['supplier' => 999999999]))->assertViewHas('variants', fn ($variants) => $variants->total() === 0);
        $payload = $filters + ['key' => (string) str()->uuid(), 'low_stock' => 1];
        $this->post(route('inventory.purchase-orders.store'), $payload)->assertSessionHas('inventory_replenishment.added', 1);
        $this->post(route('inventory.purchase-orders.store'), $payload)->assertSessionHas('inventory_replenishment.added', 1);
        $this->postJson(route('inventory.purchase-orders.store'), [...$payload, 'supplier' => $otherSupplier->id])->assertConflict();
        $draft = PurchaseOrder::sole();
        $this->assertSame($supplier->id, $draft->supplier_id);
        $this->assertSame($low->id, $draft->items()->sole()->product_variant_id);
        $this->assertSame('7.000', $draft->items()->sole()->ordered_quantity);
        $this->assertSame($before, InventoryBalance::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('inventory_movements', $movements);
    }

    public function test_send_creates_supplier_drafts_for_all_filtered_rows_without_duplicates_or_changing_user_edited_quantities(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::where('email', 'hi@davidsabido.com')->sole();
        $firstSupplier = Supplier::factory()->create(['name' => 'Proveedor uno']);
        $secondSupplier = Supplier::factory()->create(['name' => 'Proveedor dos']);
        $variants = collect();
        foreach (range(1, 35) as $number) {
            $variants->push($this->variant('Repuesto '.$number, $number <= 20 ? $firstSupplier : $secondSupplier, 1, 2, 8));
        }
        $missing = $this->variant('Repuesto sin proveedor', null, 0, 2, 8);
        $excluded = $this->variant('Repuesto suficiente', $firstSupplier, 9, 2, 8);
        $order = PurchaseOrder::factory()->for($firstSupplier)->create(['code' => 'OC-EXISTENTE', 'status' => 'draft', 'notes' => 'Cantidad revisada por compras']);
        $item = $order->items()->create(['product_variant_id' => $variants->first()->id, 'ordered_quantity' => 99, 'cost_snapshot' => 45]);
        $before = InventoryBalance::orderBy('id')->get()->toArray();
        $movements = InventoryMovement::count();
        $payload = ['key' => (string) str()->uuid(), 'brand' => 'Marca ordenada', 'search' => 'repuesto', 'low_stock' => 1];

        $response = $this->actingAs($admin)->withSession(['crepe.mode' => 'almacen'])->post(route('inventory.purchase-orders.store'), $payload);
        $response->assertRedirect(route('purchase-orders.index', ['status' => 'draft']))->assertSessionHasNoErrors()
            ->assertSessionHas('inventory_replenishment.added', 34)->assertSessionHas('inventory_replenishment.existing', 1);
        $this->post(route('inventory.purchase-orders.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('inventory.purchase-orders.store'), [...$payload, 'key' => (string) str()->uuid()])->assertSessionHas('inventory_replenishment.added', 0)->assertSessionHas('inventory_replenishment.existing', 35);

        $this->assertDatabaseCount('purchase_orders', 2);
        $this->assertDatabaseCount('purchase_order_items', 35);
        $this->assertSame('99.000', $item->fresh()->ordered_quantity);
        $this->assertSame('45.0000', $item->fresh()->cost_snapshot);
        $this->assertSame('Cantidad revisada por compras', $order->fresh()->notes);
        $this->assertDatabaseHas('purchase_order_items', ['product_variant_id' => $variants->last()->id, 'ordered_quantity' => 7, 'cost_snapshot' => 12.3456]);
        $this->assertDatabaseMissing('purchase_order_items', ['product_variant_id' => $excluded->id]);
        $this->assertDatabaseMissing('purchase_order_items', ['product_variant_id' => $missing->id]);
        $this->assertSame($before, InventoryBalance::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('inventory_movements', $movements);
        $this->assertSame(2, AuditLog::where('action', 'inventory.replenishment.sent')->count());
        $this->get(route('purchase-orders.index', ['status' => 'draft']))->assertSee('Repuesto sin proveedor')->assertSee('requieren proveedor activo');
    }

    public function test_fallback_limits_and_zero_maximum_produce_editable_drafts_and_received_orders_are_untouched(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::where('email', 'hi@davidsabido.com')->sole();
        $supplier = Supplier::factory()->create(['name' => 'Proveedor borrador']);
        $variant = $this->variant('Repuesto sin máximo', $supplier, 2, 2, null);
        $variant->update(['minimum_stock' => 4, 'maximum_stock' => 10]);
        $balance = $variant->balances()->whereHas('location', fn ($query) => $query->where('code', 'ALM'))->sole();
        $balance->update(['limits_configured' => false]);
        $received = PurchaseOrder::factory()->for($supplier)->create(['code' => 'OC-RECIBIDA', 'status' => 'received']);
        $receivedItem = $received->items()->create(['product_variant_id' => $variant->id, 'ordered_quantity' => 25, 'received_quantity' => 25, 'cost_snapshot' => 3]);

        $this->actingAs($admin)->withSession(['crepe.mode' => 'almacen'])->post(route('inventory.purchase-orders.store'), ['key' => (string) str()->uuid(), 'brand' => 'Marca ordenada', 'low_stock' => 1])->assertSessionHasNoErrors();

        $draft = PurchaseOrder::where('status', 'draft')->sole();
        $this->assertSame('8.000', $draft->items()->sole()->ordered_quantity);
        $this->get(route('purchase-orders.show', $draft))->assertSee('Quitar')->assertSee('Agregar producto');
        $this->put(route('purchase-orders.items.update', [$draft, $draft->items()->sole()]), ['ordered_quantity' => 6])->assertSessionHasNoErrors();
        $this->assertSame('6.000', $draft->items()->sole()->ordered_quantity);
        $this->assertSame('25.000', $receivedItem->fresh()->ordered_quantity);
        $this->assertSame('received', $received->fresh()->status);
    }

    public function test_equal_minimum_orders_one_unit_inactive_suppliers_are_reported_and_a_key_cannot_be_reused_with_different_filters(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::where('email', 'hi@davidsabido.com')->sole();
        $supplier = Supplier::factory()->create(['name' => 'Proveedor activo']);
        $inactive = Supplier::factory()->create(['name' => 'Proveedor inactivo', 'is_active' => false]);
        $variant = $this->variant('Repuesto al mínimo', $supplier, 2, 2, null);
        $skipped = $this->variant('Repuesto inactivo proveedor', $inactive, 0, 2, 8);
        $payload = ['key' => (string) str()->uuid(), 'low_stock' => 1, 'brand' => 'Marca ordenada'];

        $this->actingAs($admin)->withSession(['crepe.mode' => 'almacen'])->post(route('inventory.purchase-orders.store'), $payload)
            ->assertSessionHas('inventory_replenishment.added', 1)
            ->assertSessionHas('inventory_replenishment.skipped', [['name' => $skipped->product->name, 'sku' => $skipped->sku]]);
        $this->postJson(route('inventory.purchase-orders.store'), [...$payload, 'search' => 'otro filtro'])->assertConflict();

        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseHas('purchase_order_items', ['product_variant_id' => $variant->id, 'ordered_quantity' => 1]);
        $this->assertDatabaseMissing('purchase_order_items', ['product_variant_id' => $skipped->id]);
    }

    public function test_mode_permission_low_stock_and_filter_validation_protect_generation(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::where('email', 'hi@davidsabido.com')->sole();
        $movements = InventoryMovement::count();
        $route = route('inventory.purchase-orders.store');
        $payload = ['key' => (string) str()->uuid(), 'low_stock' => 1];
        $this->post($route, $payload)->assertRedirect(route('login'));
        $this->actingAs($admin)->withSession(['crepe.mode' => 'recepcion'])->post($route, $payload)->assertForbidden();
        $this->withSession(['crepe.mode' => 'color-bar'])->post($route, $payload)->assertForbidden();
        $this->withSession(['crepe.mode' => 'almacen'])->post($route, ['key' => (string) str()->uuid()])->assertSessionHasErrors('replenishment');
        $this->postJson($route, [...$payload, 'brand' => ['invalid']])->assertUnprocessable();
        $this->postJson($route, [...$payload, 'supplier' => ['invalid']])->assertJsonValidationErrors('supplier');
        $this->getJson(route('inventory.index', ['supplier' => -1]))->assertJsonValidationErrors('supplier');
        $this->getJson(route('inventory.export', ['supplier' => 'invalid']))->assertJsonValidationErrors('supplier');
        $this->post($route, [...$payload, 'search' => 'no existe este producto'])->assertSessionHasErrors('replenishment');
        $warehouse = User::factory()->create();
        $warehouse->assignRole('Almacén');
        Role::findByName('Almacén')->revokePermissionTo('inventory.requests.manage');
        $this->actingAs($warehouse)->post($route, $payload)->assertForbidden();
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('inventory_movements', $movements);
    }

    private function variant(string $name, ?Supplier $supplier, int $quantity, int $minimum, ?int $maximum): ProductVariant
    {
        $product = Product::create(['name' => $name, 'sku' => 'PROD-'.str()->uuid(), 'brand' => 'Marca ordenada', 'product_category_id' => ProductCategory::where('name', 'Retail')->sole()->id, 'preferred_supplier_id' => $supplier?->id, 'status' => 'active', 'is_reception_usable' => true]);
        $variant = $product->variants()->create(['name' => 'Unidad', 'sku' => 'VAR-'.str()->uuid(), 'content_quantity' => 1, 'base_unit' => 'unidad', 'minimum_stock' => 0, 'maximum_stock' => 0, 'cost' => 12.3456]);
        foreach (['ALM' => $quantity, 'REC' => 99, 'CB' => 88] as $code => $stock) {
            InventoryBalance::create(['product_variant_id' => $variant->id, 'inventory_location_id' => InventoryLocation::where('code', $code)->sole()->id, 'available_quantity' => $stock, 'minimum_stock' => $minimum, 'maximum_stock' => $maximum, 'limits_configured' => true]);
        }

        return $variant;
    }
}
