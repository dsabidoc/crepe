<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AttendanceIncident;
use App\Models\AuditLog;
use App\Models\InventoryLocation;
use App\Models\InventoryRequest;
use App\Models\User;
use App\Services\OperationalNotificationService;
use App\Services\RoleModuleAccessService;
use App\Services\WorkflowNotificationService;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_access_settings_preserves_all_current_role_permissions_and_data(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = $this->userWithRole('Administrador');
        $roles = Role::query()->with('permissions')->get();
        $before = $roles->mapWithKeys(fn (Role $role): array => [$role->id => $role->permissions->modelKeys()])->all();
        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)->get(route('settings.role-modules.index'))
            ->assertOk()->assertSee('Accesos por rol')->assertSee('Revisar cambios')
            ->assertSee('role-module-option', false)->assertSee('Recepción')->assertSee('Almacén');

        $this->assertSame($before, Role::query()->with('permissions')->get()->mapWithKeys(fn (Role $role): array => [$role->id => $role->permissions->modelKeys()])->all());
        $this->assertSame(0, AppSetting::query()->where('key', 'like', 'access.role.%')->count());
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    #[TestWith(['Recepción', ['recepcion', 'color-bar', 'almacen', 'checks']])]
    #[TestWith(['Color Bar', ['color-bar']])]
    #[TestWith(['Almacén', ['almacen']])]
    #[TestWith(['Administrador', ['recepcion', 'color-bar', 'almacen', 'finanzas', 'promos', 'checks']])]
    public function test_the_initial_selection_matches_current_access(string $roleName, array $expected): void
    {
        $this->seed(CrepeSeeder::class);
        $role = Role::findByName($roleName, 'web');

        $this->actingAs($this->userWithRole('Administrador'))
            ->get(route('settings.role-modules.index', ['role' => $role->id]))
            ->assertViewHas('selectedModules', $expected);
    }

    public function test_reception_can_lose_color_bar_and_warehouse_access_while_keeping_reception(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = $this->userWithRole('Administrador');
        $reception = $this->userWithRole('Recepción');
        $role = Role::findByName('Recepción', 'web');
        $permissions = $role->permissions()->pluck('permissions.id')->all();
        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)->put(route('settings.role-modules.update', $role), $this->payload($role, ['recepcion']))
            ->assertSessionHasNoErrors()->assertRedirect(route('settings.role-modules.index', ['role' => $role->id]));

        $this->assertSame($permissions, $role->permissions()->pluck('permissions.id')->all());
        $this->assertSame(['recepcion'], json_decode(AppSetting::value('access.role.'.$role->id), true));
        $this->assertDatabaseHas('audit_logs', ['action' => 'roles.modules.updated', 'user_id' => $admin->id, 'subject_id' => $role->id]);
        $this->assertSame($auditCount + 1, AuditLog::query()->count());
        $this->actingAs($reception)->withSession(['crepe.mode' => 'recepcion'])->get(route('tickets.index'))->assertOk();
        $this->get(route('color-bar.index'))->assertForbidden();
        $this->get(route('workspace', 'almacen'))->assertForbidden();
        $this->get(route('products.index'))->assertForbidden();
        $this->get(route('purchase-orders.index'))->assertForbidden();
        $this->get(route('inventory.index', ['location' => 'ALM']))->assertForbidden();
        $this->get(route('inventory.index', ['location' => 'CB']))->assertForbidden();
        $this->get(route('inventory.index', ['location' => 'REC']))->assertOk();
        $this->get(route('inventory.index'))->assertViewHas('location', 'REC');
        $this->get(route('attendance.index'))->assertForbidden();
        $this->withSession(['crepe.mode' => 'color-bar'])->get(route('inventory.requests.index'))->assertForbidden();
        $this->get(route('modes.select'))->assertDontSee('name="mode" value="color-bar"', false)->assertDontSee('name="mode" value="almacen"', false);
    }

    public function test_modules_can_be_granted_to_color_bar_without_granting_administration(): void
    {
        $this->seed(CrepeSeeder::class);
        $colorBar = $this->userWithRole('Color Bar');
        $role = Role::findByName('Color Bar', 'web');
        $this->actingAs($this->userWithRole('Administrador'))->put(route('settings.role-modules.update', $role), $this->payload($role, ['color-bar', 'almacen', 'promos', 'checks']))->assertSessionHasNoErrors();

        $this->actingAs($colorBar)->get(route('workspace', 'almacen'))->assertOk();
        $this->get(route('products.index'))->assertOk();
        $this->get(route('promotions.index'))->assertOk();
        $this->get(route('attendance.incidents.index'))->assertOk();
        $this->get(route('settings.edit'))->assertForbidden();
        $this->get(route('settings.role-modules.index'))->assertForbidden();
        $this->get(route('workspace', 'administracion'))->assertForbidden();
        $this->assertFalse($colorBar->can('cash.authorize'));
    }

    public function test_finance_access_does_not_grant_configuration_or_cash_cut_authorization(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = $this->userWithRole('Almacén');
        $role = Role::findByName('Almacén', 'web');
        $this->actingAs($this->userWithRole('Administrador'))->put(route('settings.role-modules.update', $role), $this->payload($role, ['finanzas']))->assertSessionHasNoErrors();

        $this->actingAs($user)->get(route('finance.index'))->assertOk();
        $this->get(route('products.index'))->assertForbidden();
        $this->get(route('settings.edit'))->assertForbidden();
        $this->assertTrue($user->can('finance.manage'));
        $this->assertFalse($user->can('cash.authorize'));
    }

    public function test_a_protected_administrator_role_cannot_be_edited(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = $this->userWithRole('Administrador');
        $role = Role::findByName('Administrador', 'web');

        $this->actingAs($admin)->get(route('settings.role-modules.index', ['role' => $role->id]))->assertSee('Administrador es un rol protegido.')->assertDontSee('data-access-review', false);
        $this->put(route('settings.role-modules.update', $role), $this->payload($role, ['color-bar']))->assertForbidden();

        $this->assertDatabaseMissing('app_settings', ['key' => 'access.role.'.$role->id]);
    }

    #[TestWith(['Recepción'])]
    #[TestWith(['Color Bar'])]
    #[TestWith(['Almacén'])]
    public function test_only_administrators_can_read_or_change_role_access(string $roleName): void
    {
        $this->seed(CrepeSeeder::class);
        $role = Role::findByName('Recepción', 'web');

        $this->actingAs($this->userWithRole($roleName))->get(route('settings.role-modules.index'))->assertForbidden();
        $this->put(route('settings.role-modules.update', $role), $this->payload($role, ['finanzas']))->assertForbidden();

        $this->assertDatabaseMissing('app_settings', ['key' => 'access.role.'.$role->id]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('settings.role-modules.index'))->assertRedirect(route('login'));
        $this->put(route('settings.role-modules.update', 1), [])->assertRedirect(route('login'));
    }

    #[TestWith([[]])]
    #[TestWith([['unknown']])]
    #[TestWith([['configuracion']])]
    #[TestWith([['color-bar', 'color-bar']])]
    public function test_invalid_module_selections_are_rejected_without_changing_access(array $modules): void
    {
        $this->seed(CrepeSeeder::class);
        $role = Role::findByName('Recepción', 'web');
        $auditCount = AuditLog::query()->count();

        $this->actingAs($this->userWithRole('Administrador'))->putJson(route('settings.role-modules.update', $role), $this->payload($role, $modules))->assertUnprocessable();

        $this->assertDatabaseMissing('app_settings', ['key' => 'access.role.'.$role->id]);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_a_stale_form_cannot_overwrite_another_administrators_changes(): void
    {
        $this->seed(CrepeSeeder::class);
        $role = Role::findByName('Recepción', 'web');
        $stale = $this->payload($role, ['color-bar']);
        $this->actingAs($this->userWithRole('Administrador'))->put(route('settings.role-modules.update', $role), $this->payload($role, ['recepcion']))->assertSessionHasNoErrors();

        $this->putJson(route('settings.role-modules.update', $role), $stale)->assertInvalid(['revision']);

        $this->assertSame(['recepcion'], json_decode(AppSetting::value('access.role.'.$role->id), true));
        $this->assertSame(1, AuditLog::query()->where('action', 'roles.modules.updated')->count());
    }

    public function test_saving_unchanged_access_does_not_write_settings_or_audit_events(): void
    {
        $this->seed(CrepeSeeder::class);
        $role = Role::findByName('Recepción', 'web');
        $modules = app(RoleModuleAccessService::class)->modulesForRole($role);

        $this->actingAs($this->userWithRole('Administrador'))->put(route('settings.role-modules.update', $role), $this->payload($role, $modules))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('app_settings', ['key' => 'access.role.'.$role->id]);
        $this->assertSame(0, AuditLog::query()->where('action', 'roles.modules.updated')->count());
    }

    public function test_roles_are_additive_and_revocation_takes_effect_for_already_signed_in_users(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = $this->userWithRole('Recepción');
        $user->assignRole('Almacén');
        $role = Role::findByName('Recepción', 'web');
        $this->actingAs($user)->get(route('color-bar.index'))->assertOk();
        $this->actingAs($this->userWithRole('Administrador'))->put(route('settings.role-modules.update', $role), $this->payload($role, ['recepcion']))->assertSessionHasNoErrors();

        $this->actingAs($user)->get(route('color-bar.index'))->assertForbidden();
        $this->get(route('workspace', 'almacen'))->assertOk();
        $this->get(route('products.index'))->assertOk();
    }

    public function test_new_warehouse_processors_receive_requests_and_stop_receiving_them_after_revocation(): void
    {
        $this->seed(CrepeSeeder::class);
        $processor = $this->userWithRole('Color Bar');
        $requester = $this->userWithRole('Recepción');
        $role = Role::findByName('Color Bar', 'web');
        $admin = $this->userWithRole('Administrador');
        $this->actingAs($admin)->put(route('settings.role-modules.update', $role), $this->payload($role, ['color-bar', 'almacen']))->assertSessionHasNoErrors();
        $location = InventoryLocation::query()->where('code', 'REC')->firstOrFail();
        $first = InventoryRequest::query()->create(['code' => 'ROLE-001', 'source_location_id' => $location->id, 'requested_by' => $requester->id, 'status' => 'pending']);

        app(WorkflowNotificationService::class)->inventoryRequested($first);

        $this->assertSame(1, $processor->notifications()->where('type', WorkflowNotificationService::RequestPending)->count());
        $this->actingAs($processor)->getJson(route('notifications.index', ['tab' => 'pending']))->assertJsonCount(1, 'items');
        $this->actingAs($admin)->put(route('settings.role-modules.update', $role), $this->payload($role, ['color-bar']))->assertSessionHasNoErrors();
        $second = InventoryRequest::query()->create(['code' => 'ROLE-002', 'source_location_id' => $location->id, 'requested_by' => $requester->id, 'status' => 'pending']);
        app(WorkflowNotificationService::class)->inventoryRequested($second);
        $this->assertSame(1, $processor->notifications()->where('type', WorkflowNotificationService::RequestPending)->count());
        $this->actingAs($processor)->getJson(route('notifications.index', ['tab' => 'pending']))->assertJsonCount(0, 'items');
    }

    public function test_promotions_access_does_not_allow_other_ticket_changes(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = $this->userWithRole('Almacén');
        $role = Role::findByName('Almacén', 'web');
        $this->actingAs($this->userWithRole('Administrador'))->put(route('settings.role-modules.update', $role), $this->payload($role, ['promos']))->assertSessionHasNoErrors();

        $this->actingAs($user)->get(route('promotions.index'))->assertOk();
        $this->assertTrue($user->can('promotions.apply'));
        $this->assertFalse($user->can('tickets.update'));
        $this->assertFalse($user->can('tickets.charge'));
        $this->get(route('settings.edit'))->assertForbidden();
    }

    public function test_a_checks_only_role_can_open_its_incident_notifications_without_reception_access(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = $this->userWithRole('Color Bar');
        $role = Role::findByName('Color Bar', 'web');
        $this->actingAs($this->userWithRole('Administrador'))->put(route('settings.role-modules.update', $role), $this->payload($role, ['checks']))->assertSessionHasNoErrors();
        $date = today()->subDay()->toDateString();
        AttendanceIncident::factory()->create(['work_date' => $date]);
        app(OperationalNotificationService::class)->attendanceChanged($date);
        $notification = $user->notifications()->where('type', OperationalNotificationService::AttendancePending)->sole();

        $this->actingAs($user)->withSession(['crepe.mode' => 'color-bar'])->postJson(route('notifications.open', $notification->id))
            ->assertJsonPath('url', route('attendance.incidents.index', ['from' => $date, 'to' => $date]))
            ->assertSessionHas('crepe.mode', 'checks');
        $this->get(route('attendance.incidents.index'))->assertOk();
        $this->get(route('workspace', 'recepcion'))->assertForbidden();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** @param array<int, string> $modules
     * @return array{modules: array<int, string>, revision: string}
     */
    private function payload(Role $role, array $modules): array
    {
        return ['modules' => $modules, 'revision' => app(RoleModuleAccessService::class)->revision($role)];
    }
}
