<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryRequest;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class InventoryModuleContextTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['recepcion', 'REC', 'CB', 'SH-REST-STD'])]
    #[TestWith(['color-bar', 'CB', 'REC', 'KOL-71-STD'])]
    public function test_administrators_request_for_the_active_area_without_moving_stock(string $mode, string $code, string $other, string $sku): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::query()->where('email', 'hi@davidsabido.com')->sole();
        $variant = ProductVariant::where('sku', $sku)->sole();
        $before = InventoryBalance::orderBy('id')->get()->toArray();
        $movements = DB::table('inventory_movements')->count();
        $foreign = InventoryRequest::create(['code' => 'SOL-OTRA', 'source_location_id' => InventoryLocation::where('code', $other)->sole()->id, 'requested_by' => $admin->id, 'status' => 'pending']);

        $this->actingAs($admin)->withSession(['crepe.mode' => $mode])->get(route('inventory.requests.create'))
            ->assertSee('Solicitar productos a Almacén')->assertViewHas('source', fn ($source) => $source->code === $code);
        $this->get(route('inventory.requests.index', ['source' => $other]))->assertDontSee('SOL-OTRA')->assertViewHas('isWarehouse', false);
        $this->get(route('inventory.requests.show', $foreign))->assertForbidden();
        $this->post(route('inventory.requests.store'), ['items' => [['product_variant_id' => $variant->id, 'requested_units' => 2]]])->assertSessionHasNoErrors()->assertRedirect(route('inventory.requests.index', ['location' => $code]));
        $own = InventoryRequest::where('id', '!=', $foreign->id)->sole();
        $this->assertSame($code, $own->sourceLocation->code);
        $this->get(route('inventory.requests.show', $own))->assertSee($own->code)->assertDontSee('Atender solicitud');
        $this->post(route('inventory.requests.store'), ['location' => $other, 'items' => [['product_variant_id' => $variant->id, 'requested_units' => 2]]])->assertForbidden();
        $this->get(route('inventory.requests.edit', $own))->assertForbidden();
        $this->post(route('inventory.requests.close', $own), ['items' => [$own->items()->sole()->id => 2]])->assertForbidden();
        $this->post(route('inventory.requests.reverse', $own), [])->assertForbidden();
        $this->assertDatabaseCount('inventory_requests', 2);
        $this->assertSame($before, InventoryBalance::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('inventory_movements', $movements);
    }

    #[TestWith(['recepcion', 'REC'])]
    #[TestWith(['color-bar', 'CB'])]
    public function test_own_area_logs_and_exports_stay_in_the_module_even_for_administrators(string $mode, string $code): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::query()->where('email', 'hi@davidsabido.com')->sole();

        $this->actingAs($admin)->withSession(['crepe.mode' => $mode])->get(route('activity.index'))
            ->assertViewHas('location', $code)->assertViewHas('isAdministrator', false)
            ->assertViewHas('movements', fn ($rows) => $rows->every(fn ($row) => $row->location->code === $code))
            ->assertViewHas('audits', fn ($rows) => $rows->isEmpty());
        $this->get(route('activity.index', ['location' => 'ALM']))->assertForbidden();
        $export = $this->get(route('inventory.export', ['format' => 'csv', 'location_code' => 'ALM']));
        $export->assertOk();
        $this->assertStringContainsString($code === 'REC' ? 'recepcion' : 'color-bar', $export->headers->get('Content-Disposition'));
        $this->get(route('purchase-orders.index'))->assertForbidden();
        $this->get(route('inventory.import.create'))->assertForbidden();
        $this->assertSame($mode, session('crepe.mode'));
    }

    public function test_reception_navigation_has_no_cross_module_shortcuts_but_keeps_the_mode_selector(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::query()->where('email', 'hi@davidsabido.com')->sole();
        $response = $this->actingAs($admin)->withSession(['crepe.mode' => 'recepcion'])->get(route('inventory.index'));
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $nav = '//nav[@aria-label="Navegación principal"]';

        $this->assertSame(0, $xpath->query($nav.'//a[@href="'.route('workspace', 'almacen').'"]')->length);
        $this->assertSame(0, $xpath->query($nav.'//a[@href="'.route('color-bar.index').'"]')->length);
        $this->assertSame(1, $xpath->query($nav.'//a[@href="'.route('inventory.index', ['location' => 'REC']).'"]')->length);
        $response->assertSee('mode-switcher')->assertSee('Solicitar inventario')->assertDontSee('Historial de importaciones');
        $this->post(route('modes.store'), ['mode' => 'almacen'])->assertRedirect(route('workspace', 'almacen'));
        $this->get(route('inventory.index'))->assertSee('warehouse-stock-table');
    }

    public function test_reception_inventory_hides_unassigned_empty_products_but_preserves_existing_stock(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::query()->where('email', 'hi@davidsabido.com')->sole();
        $variant = ProductVariant::where('sku', 'KOL-71-STD')->sole();
        $variant->product->update(['is_reception_usable' => false]);
        $balance = InventoryBalance::firstOrCreate(['product_variant_id' => $variant->id, 'inventory_location_id' => InventoryLocation::where('code', 'REC')->sole()->id], ['available_quantity' => 0]);
        $balance->update(['available_quantity' => 0]);

        $this->actingAs($admin)->withSession(['crepe.mode' => 'recepcion'])->get(route('inventory.index', ['search' => $variant->sku]))->assertViewHas('balances', fn ($rows) => $rows->isEmpty());
        $balance->update(['available_quantity' => 1]);
        $this->get(route('inventory.index', ['search' => $variant->sku]))->assertViewHas('balances', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $balance->id);
        $this->assertSame('1.000', $balance->fresh()->available_quantity);
    }
}
