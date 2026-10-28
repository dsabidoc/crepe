<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryRequest;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryRequestService;
use App\Services\WorkflowNotificationService;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class InventoryRequestCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_cancels_a_pending_request_once_preserving_every_quantity_and_item_and_resolving_notifications(): void
    {
        $this->seed(CrepeSeeder::class);
        $requester = $this->staff('Recepción');
        $warehouse = $this->staff('Almacén');
        $request = $this->requestFor($requester, 'REC');
        $before = InventoryBalance::orderBy('id')->get()->toArray();
        $movements = InventoryMovement::count();
        $items = $request->items()->get()->toArray();
        $auditCount = AuditLog::count();
        $notifications = $warehouse->notifications()->where('type', WorkflowNotificationService::RequestPending)->get();

        $this->actingAs($warehouse)->withSession(['crepe.mode' => 'almacen'])->get(route('inventory.requests.index'))->assertSee('Eliminar '.$request->code);
        $this->get(route('inventory.requests.cancel', $request))->assertSee('Confirmar eliminación');
        $this->delete(route('inventory.requests.destroy', $request), ['reason' => 'Solicitud duplicada por error'])->assertRedirect(route('inventory.requests.index'))->assertSessionHasNoErrors();
        $this->delete(route('inventory.requests.destroy', $request), ['reason' => 'Solicitud duplicada por error'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventory_requests', ['id' => $request->id, 'status' => 'cancelled', 'processed_by' => $warehouse->id, 'closure_notes' => 'Solicitud duplicada por error']);
        $this->assertSame($items, $request->items()->get()->toArray());
        $this->assertSame($before, InventoryBalance::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('inventory_movements', $movements);
        $this->assertDatabaseCount('audit_logs', $auditCount + 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.request.cancelled', 'subject_id' => $request->id, 'user_id' => $warehouse->id]);
        $this->assertNotNull($notifications->sole()->fresh()->resolved_at);
        $this->get(route('inventory.requests.index'))->assertViewHas('requests', fn ($requests) => $requests->isEmpty());
        $this->get(route('inventory.requests.index', ['history' => 1]))->assertSee($request->code)->assertSee('Cancelada · ver motivo');
        $this->get(route('inventory.requests.show', $request))->assertSee('Solicitud cancelada')->assertSee('Solicitud duplicada por error')->assertDontSee('Atender solicitud');
        $this->get(route('inventory.requests.edit', $request))->assertUnprocessable();
        $this->get(route('inventory.requests.download', $request))->assertUnprocessable();
        $this->post(route('inventory.requests.close', $request), ['items' => []])->assertUnprocessable();
    }

    #[TestWith(['recepcion', 'REC', 'Recepción'])]
    #[TestWith(['color-bar', 'CB', 'Color Bar'])]
    public function test_requesters_can_cancel_their_own_request_only_in_the_corresponding_module(string $mode, string $code, string $role): void
    {
        $this->seed(CrepeSeeder::class);
        $owner = $this->staff($role);
        $colleague = $this->staff($role);
        $request = $this->requestFor($owner, $code);
        $movements = InventoryMovement::count();

        $this->actingAs($colleague)->withSession(['crepe.mode' => $mode])->get(route('inventory.requests.cancel', $request))->assertForbidden();
        $this->delete(route('inventory.requests.destroy', $request), ['reason' => 'Solicitud por error'])->assertForbidden();
        $this->actingAs($owner)->withSession(['crepe.mode' => $mode === 'recepcion' ? 'color-bar' : 'recepcion'])->delete(route('inventory.requests.destroy', $request), ['reason' => 'Solicitud por error'])->assertForbidden();
        $this->withSession(['crepe.mode' => $mode])->delete(route('inventory.requests.destroy', $request), ['reason' => 'Solicitud por error'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventory_requests', ['id' => $request->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('inventory_movements', $movements);
    }

    #[TestWith(['closed'])]
    #[TestWith(['pending'])]
    public function test_delivered_requests_cannot_be_deleted_or_change_stock(string $status): void
    {
        $this->seed(CrepeSeeder::class);
        $warehouse = $this->staff('Almacén');
        $request = $this->requestFor($this->staff('Recepción'), 'REC');
        $request->update(['status' => $status]);
        $request->items()->firstOrFail()->update(['delivered_quantity' => 1, 'delivered_units' => 1]);
        $before = InventoryBalance::orderBy('id')->get()->toArray();
        $auditCount = AuditLog::count();

        $this->actingAs($warehouse)->withSession(['crepe.mode' => 'almacen'])->delete(route('inventory.requests.destroy', $request), ['reason' => 'Error al solicitar'])->assertUnprocessable();

        $this->assertSame($status, $request->fresh()->status);
        $this->assertSame($before, InventoryBalance::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('audit_logs', $auditCount);
    }

    public function test_a_legacy_pending_request_with_a_movement_is_preserved(): void
    {
        $this->seed(CrepeSeeder::class);
        $warehouse = $this->staff('Almacén');
        $request = $this->requestFor($this->staff('Recepción'), 'REC');
        $original = InventoryMovement::firstOrFail();
        $movement = $original->replicate(['uuid']);
        $movement->fill(['uuid' => (string) str()->uuid(), 'reference_type' => InventoryRequest::class, 'reference_id' => $request->id]);
        $movement->save();
        $count = InventoryMovement::count();
        $before = InventoryBalance::orderBy('id')->get()->toArray();

        $this->actingAs($warehouse)->withSession(['crepe.mode' => 'almacen'])->delete(route('inventory.requests.destroy', $request), ['reason' => 'Revisar solicitud antigua'])->assertUnprocessable();

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertDatabaseCount('inventory_movements', $count);
        $this->assertSame($before, InventoryBalance::orderBy('id')->get()->toArray());
    }

    public function test_reason_is_required_and_warehouse_permission_alone_does_not_allow_cancellation_in_reception(): void
    {
        $this->seed(CrepeSeeder::class);
        $request = $this->requestFor($this->staff('Recepción'), 'REC');
        $admin = $this->staff('Administrador');
        $this->actingAs($admin)->withSession(['crepe.mode' => 'recepcion'])->delete(route('inventory.requests.destroy', $request), ['reason' => 'Solicitud por error'])->assertForbidden();
        $this->withSession(['crepe.mode' => 'almacen'])->delete(route('inventory.requests.destroy', $request), [])->assertSessionHasErrors(['reason' => 'Escribe el motivo para eliminar la solicitud.']);
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_guests_cannot_open_confirmation_or_cancel(): void
    {
        $this->get(route('inventory.requests.cancel', 99))->assertRedirect(route('login'));
        $this->delete(route('inventory.requests.destroy', 99))->assertRedirect(route('login'));
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function requestFor(User $requester, string $code): InventoryRequest
    {
        $variant = ProductVariant::where('sku', $code === 'REC' ? 'SH-REST-STD' : 'KOL-71-STD')->sole();

        return app(InventoryRequestService::class)->create(InventoryLocation::where('code', $code)->sole(), $requester, collect([$variant->id => $variant]), [['product_variant_id' => $variant->id, 'requested_units' => 2, 'requested_quantity' => null]], null);
    }
}
