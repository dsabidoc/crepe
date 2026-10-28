<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\PayableInvoice;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarehouseInventoryDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_main_inventory_has_three_location_counts_total_prices_and_warehouse_limits_without_changing_stock(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $variant = ProductVariant::query()->where('sku', 'SH-REST-STD')->firstOrFail();
        $variant->update(['cost' => '123.45', 'sale_price' => '234.56']);
        foreach (['ALM' => [7, 3, 15], 'REC' => [4, 50, 100], 'CB' => [2, 80, 120]] as $code => [$quantity, $minimum, $maximum]) {
            InventoryBalance::query()->updateOrCreate(['product_variant_id' => $variant->id, 'inventory_location_id' => InventoryLocation::where('code', $code)->sole()->id], ['available_quantity' => $quantity, 'minimum_stock' => $minimum, 'maximum_stock' => $maximum, 'limits_configured' => true]);
        }
        $before = DB::table('inventory_balances')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $movements = DB::table('inventory_movements')->count();

        $response = $this->actingAs($admin)->withSession(['crepe.mode' => 'almacen'])->get(route('inventory.index', ['location' => 'ALM', 'search' => $variant->sku]));

        $response->assertOk()->assertSee('Precio Recepción')->assertSee('Costo por unidad')->assertSee('$123.45')->assertSee('$234.56')->assertSee('Mín. Almacén')->assertSee('Máx. Almacén')->assertDontSee('Ver Almacén, Recepción y Color Bar en columnas');
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $row = '//tr[@data-variant-id="'.$variant->id.'"]';
        $this->assertSame('4', trim($xpath->query($row.'/td[@data-stock-location="REC"]/strong')->item(0)->textContent));
        $this->assertSame('2', trim($xpath->query($row.'/td[@data-stock-location="CB"]/strong')->item(0)->textContent));
        $this->assertSame('7', trim($xpath->query($row.'/td[@data-stock-location="ALM"]/strong')->item(0)->textContent));
        $this->assertSame('13', trim($xpath->query($row.'/td[@data-stock-total]')->item(0)->textContent));
        $this->assertSame('3', $xpath->query('//input[starts-with(@aria-label,"Mínimo de Almacén")]')->item(0)->getAttribute('value'));
        $this->assertSame($before, DB::table('inventory_balances')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
        $this->assertSame($movements, DB::table('inventory_movements')->count());
    }

    #[TestWith(['recepcion', 'REC'])]
    #[TestWith(['color-bar', 'CB'])]
    public function test_even_administrators_see_only_the_active_area_and_cannot_open_or_edit_the_joint_view(string $mode, string $code): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($admin)->withSession(['crepe.mode' => $mode])->get(route('inventory.index', ['location' => 'ALM']))
            ->assertOk()->assertViewHas('location', $code)
            ->assertViewHas('balances', fn ($balances) => $balances->every(fn ($balance) => $balance->location->code === $code))
            ->assertDontSee('warehouse-stock-table')->assertDontSee('Ver Almacén, Recepción y Color Bar en columnas');
        $this->get(route('inventory.matrix'))->assertForbidden();
        $this->post(route('inventory.adjustments.review'), [])->assertForbidden();
        $this->post(route('inventory.adjustments.store'), [])->assertForbidden();
    }

    public function test_legacy_joint_link_redirects_to_warehouse_inventory_and_filters_still_work(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Almacén');
        $variant = ProductVariant::query()->where('sku', 'SH-REST-STD')->firstOrFail();
        $variant->product->update(['brand' => 'Marca de prueba']);
        $warehouse = InventoryLocation::where('code', 'ALM')->sole();
        InventoryBalance::query()->where('product_variant_id', $variant->id)->where('inventory_location_id', $warehouse->id)->update(['available_quantity' => 3, 'minimum_stock' => 4, 'maximum_stock' => 10, 'limits_configured' => true]);

        $this->actingAs($user)->withSession(['crepe.mode' => 'almacen'])->get(route('inventory.matrix', ['brand' => 'Marca de prueba']))->assertRedirect(route('inventory.index', ['brand' => 'Marca de prueba', 'location' => 'ALM']));
        $filters = ['brand' => 'Marca de prueba', 'search' => 'restore shampoo', 'low_stock' => 1, 'category' => $variant->product->product_category_id];
        $this->get(route('inventory.index', $filters))->assertOk()->assertViewHas('variants', fn ($variants) => $variants->count() === 1 && $variants->first()->id === $variant->id)->assertDontSee('Ajustar existencias y límites');
        InventoryBalance::query()->where('product_variant_id', $variant->id)->where('inventory_location_id', $warehouse->id)->update(['available_quantity' => 5]);
        $this->get(route('inventory.index', $filters))->assertViewHas('variants', fn ($variants) => $variants->isEmpty());
    }

    public function test_warehouse_inventory_keeps_costs_hidden_without_cost_permission(): void
    {
        $this->seed(CrepeSeeder::class);
        Role::findByName('Almacén')->revokePermissionTo('products.costs.view');
        $user = User::factory()->create();
        $user->assignRole('Almacén');

        $this->actingAs($user)->withSession(['crepe.mode' => 'almacen'])->get(route('inventory.index', ['location' => 'ALM']))->assertOk()->assertDontSee('Costo por unidad')->assertSee('Precio Recepción');
    }

    public function test_purchase_order_list_shows_operational_and_payment_totals_and_searches_invoice_reference(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $supplier = Supplier::factory()->create(['name' => 'Proveedor de resumen']);
        $order = PurchaseOrder::factory()->for($supplier)->create(['code' => 'OC-RESUMEN', 'status' => 'received', 'received_at' => '2026-10-27 12:00:00', 'received_by' => $admin->id]);
        $variants = ProductVariant::query()->take(2)->get();
        PurchaseOrderItem::factory()->for($order)->create(['product_variant_id' => $variants[0]->id, 'ordered_quantity' => 4, 'received_quantity' => 3, 'cost_snapshot' => 25.5]);
        PurchaseOrderItem::factory()->for($order)->create(['product_variant_id' => $variants[1]->id, 'ordered_quantity' => 6, 'received_quantity' => 5, 'cost_snapshot' => 10]);
        PayableInvoice::factory()->for($order)->create(['invoice_reference' => 'FACT-RESUMEN', 'amount' => 200, 'paid_amount' => 75, 'status' => 'partial', 'due_on' => '2026-11-05']);
        $before = DB::table('inventory_balances')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();

        $this->actingAs($admin)->withSession(['crepe.mode' => 'almacen'])->get(route('purchase-orders.index', ['search' => 'FACT-RESUMEN']))
            ->assertOk()->assertSee('OC-RESUMEN')->assertSee('FACT-RESUMEN')->assertSee('$200.00')->assertSee('$75.00')->assertSee('$125.00')->assertSee('Facturado')->assertSee('Abono parcial')->assertSee('05/11/2026')
            ->assertViewHas('summaries', fn ($summaries) => $summaries[$order->id]['products'] === 2 && $summaries[$order->id]['ordered_units'] === 10.0 && $summaries[$order->id]['received_units'] === 8.0);
        $this->assertSame($before, DB::table('inventory_balances')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
    }

    public function test_draft_purchase_order_list_labels_estimate_without_inventing_a_payable_balance(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $supplier = Supplier::factory()->create(['name' => 'Proveedor borrador']);
        $order = PurchaseOrder::factory()->for($supplier)->create(['code' => 'OC-ESTIMADO', 'status' => 'draft']);
        PurchaseOrderItem::factory()->for($order)->create(['product_variant_id' => ProductVariant::firstOrFail()->id, 'ordered_quantity' => 3, 'received_quantity' => 0, 'cost_snapshot' => 12.3456]);

        $this->actingAs($admin)->get(route('purchase-orders.index'))->assertOk()->assertSee('$37.04')->assertSee('Estimado')->assertViewHas('summaries', fn ($summaries) => $summaries[$order->id]['paid'] === null && $summaries[$order->id]['balance'] === null);
    }
}
