<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryRequest;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\WorkflowNotificationService;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ReceptionModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_reception_accounts_can_use_both_modules_without_updating_role_permissions(): void
    {
        $this->seed(CrepeSeeder::class);
        $receptionist = User::factory()->create();
        $receptionist->assignRole('Recepción');
        $role = $receptionist->roles()->sole();
        $role->syncPermissions(['mode.reception.access', 'tickets.view', 'tickets.update', 'inventory.view']);
        $permissionIds = $role->permissions()->pluck('permissions.id')->all();

        $this->actingAs($receptionist)->get(route('modes.select'))
            ->assertSee('Color Bar')->assertSee('Almacén');
        $this->withSession(['crepe.mode' => 'recepcion'])->get(route('tickets.index'))
            ->assertDontSee('href="'.route('workspace', 'almacen').'"', false);
        $this->get(route('color-bar.index'))
            ->assertSessionHas('crepe.mode', 'color-bar')
            ->assertSee('Inventario de insumos')->assertSee('Baja de insumos');
        $this->get(route('inventory.color-bar.depletions.create'))->assertOk();
        $this->get(route('inventory.requests.create', ['location' => 'CB']))->assertOk();
        $this->post(route('modes.store'), ['mode' => 'almacen'])
            ->assertRedirect(route('workspace', 'almacen'));
        $this->get(route('workspace', 'almacen'))->assertSee('Solicitudes');

        foreach (['products.index', 'products.create', 'products.catalogs', 'inventory.import.create', 'purchase-orders.index', 'purchase-orders.create', 'inventory.requests.index', 'activity.index'] as $routeName) {
            $this->get(route($routeName))->assertOk();
        }
        $this->get(route('inventory.index', ['location' => 'ALM']))->assertOk();
        $this->get(route('inventory.export'))->assertDownload();

        $this->assertSame($permissionIds, $role->permissions()->pluck('permissions.id')->all());
        $this->assertSame(['Recepción'], $receptionist->roles()->pluck('name')->all());
    }

    #[TestWith(['REC', 'SH-REST-STD'])]
    #[TestWith(['CB', 'KOL-71-STD'])]
    public function test_reception_receives_and_processes_requests_from_both_locations(string $location, string $sku): void
    {
        $this->seed(CrepeSeeder::class);
        $requester = User::factory()->create();
        $requester->assignRole($location === 'CB' ? 'Color Bar' : 'Recepción');
        $processor = User::factory()->create(['is_active' => true]);
        $processor->assignRole('Recepción');
        $inactive = User::factory()->create(['is_active' => false]);
        $inactive->assignRole('Recepción');
        $variant = ProductVariant::query()->where('sku', $sku)->firstOrFail();
        $warehouse = InventoryBalance::query()->where('product_variant_id', $variant->id)
            ->whereHas('location', fn ($query) => $query->where('code', 'ALM'))->firstOrFail();
        $destination = InventoryBalance::query()->where('product_variant_id', $variant->id)
            ->whereHas('location', fn ($query) => $query->where('code', $location))->firstOrFail();
        $warehouseQuantity = (float) $warehouse->available_quantity;
        $destinationQuantity = (float) $destination->available_quantity;

        $this->actingAs($requester)->withSession(['crepe.mode' => $location === 'CB' ? 'color-bar' : 'recepcion'])
            ->post(route('inventory.requests.store'), [
                'location' => $location,
                'items' => [['product_variant_id' => $variant->id, 'requested_quantity' => 2]],
            ])->assertSessionHasNoErrors();
        $inventoryRequest = InventoryRequest::query()->where('requested_by', $requester->id)->latest('id')->firstOrFail();
        $notification = $processor->notifications()->where('type', WorkflowNotificationService::RequestPending)->sole();
        $this->assertSame(0, $inactive->notifications()->count());

        $this->actingAs($processor)->withSession(['crepe.mode' => 'recepcion'])
            ->postJson(route('notifications.open', $notification->id))
            ->assertOk()->assertJsonPath('url', $location === 'REC' ? route('inventory.requests.show', $inventoryRequest) : route('modes.select'))
            ->assertSessionHas('crepe.mode', 'recepcion');
        $this->post(route('modes.store'), ['mode' => 'almacen'])->assertRedirect(route('workspace', 'almacen'));
        $this->postJson(route('notifications.open', $notification->id))->assertJsonPath('url', route('inventory.requests.edit', $inventoryRequest));
        $this->get(route('inventory.requests.index'))->assertSee($inventoryRequest->code);
        $this->get(route('inventory.requests.edit', $inventoryRequest))->assertSee($inventoryRequest->code);
        $item = $inventoryRequest->items()->sole();
        $this->post(route('inventory.requests.close', $inventoryRequest), ['items' => [$item->id => 2]])
            ->assertSessionHasNoErrors()->assertRedirect(route('inventory.requests.index'));

        $this->assertDatabaseHas('inventory_requests', ['id' => $inventoryRequest->id, 'status' => 'closed', 'processed_by' => $processor->id]);
        $this->assertSame($warehouseQuantity - 2, (float) $warehouse->fresh()->available_quantity);
        $this->assertSame($destinationQuantity + 2, (float) $destination->fresh()->available_quantity);
        $this->assertNotNull($notification->fresh()->resolved_at);
    }

    public function test_reception_does_not_gain_administration_or_finance_permissions(): void
    {
        $this->seed(CrepeSeeder::class);
        $receptionist = User::factory()->create();
        $receptionist->assignRole('Recepción');

        $this->actingAs($receptionist)->get(route('workspace', 'administracion'))->assertForbidden();
        $this->get(route('finance.index'))->assertForbidden();
        $this->get(route('reports.index'))->assertForbidden();
        $this->get(route('settings.edit'))->assertForbidden();
        $this->assertFalse($receptionist->can('cash.authorize'));
    }

    #[TestWith(['Color Bar'])]
    #[TestWith([null])]
    public function test_other_accounts_do_not_gain_warehouse_access(?string $role): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::factory()->create();
        if ($role !== null) {
            $user->assignRole($role);
        }

        $this->actingAs($user)->post(route('modes.store'), ['mode' => 'almacen'])->assertForbidden();
        $this->get(route('products.index'))->assertForbidden();
        $this->get(route('purchase-orders.index'))->assertForbidden();
    }
}
