<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\InventoryBalance;
use App\Models\SpreadsheetImport;
use App\Models\User;
use App\Services\RoleModuleAccessService;
use App\Services\SpreadsheetFile;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SpreadsheetImportTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['ALM', 'SH-REST-STD', '0'])]
    #[TestWith(['REC', 'SH-REST-STD', '8.125'])]
    #[TestWith(['CB', 'KOL-71-STD', '1250.5'])]
    public function test_partial_csv_updates_only_the_selected_location_and_records_the_result(string $location, string $sku, string $quantity): void
    {
        $user = $this->warehouse();
        $balance = InventoryBalance::whereHas('location', fn ($query) => $query->where('code', $location))->whereHas('variant', fn ($query) => $query->where('sku', $sku))->firstOrFail();
        $original = $balance->available_quantity;
        $otherBalances = InventoryBalance::whereKeyNot($balance->id)->pluck('available_quantity', 'id')->all();
        $file = $this->inventoryFile($location, $balance->id, $quantity);

        $this->post(route('inventory.import.preview'), ['file' => $file, 'location_code' => $location])->assertSessionHasNoErrors();
        $import = SpreadsheetImport::sole();
        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => $original]);
        $this->get(route('inventory.import.create'))->assertSee('1 existencia por actualizar')->assertSee($quantity);
        $this->post(route('inventory.import.store'), ['token' => $import->token, 'reason' => 'Conteo parcial'])
            ->assertRedirect(route('inventory.index', ['location' => $location]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => (float) $quantity]);
        $this->assertSame($otherBalances, InventoryBalance::whereKeyNot($balance->id)->pluck('available_quantity', 'id')->all());
        $this->assertDatabaseHas('spreadsheet_imports', ['id' => $import->id, 'module' => 'inventory', 'location_code' => $location, 'format' => 'csv', 'status' => 'applied', 'user_id' => $user->id]);
        $this->assertDatabaseHas('inventory_movements', ['product_variant_id' => $balance->product_variant_id, 'inventory_location_id' => $balance->inventory_location_id, 'reason' => 'Importación CSV: Conteo parcial']);
    }

    public function test_wrong_location_rejects_the_file_and_persists_downloadable_errors(): void
    {
        $this->warehouse();
        $balance = InventoryBalance::whereHas('location', fn ($query) => $query->where('code', 'ALM'))->firstOrFail();
        $file = $this->inventoryFile('ALM', $balance->id, '1');

        $this->from(route('inventory.import.create'))->post(route('inventory.import.preview'), ['file' => $file, 'location_code' => 'REC'])
            ->assertInvalid(['file' => 'no corresponde a un producto activo de Recepción']);
        $import = SpreadsheetImport::sole();
        $this->assertSame('invalid', $import->status);
        $this->get(route('imports.result', $import))->assertDownload('resultado-importacion-'.$import->id.'.csv')->assertSee('Recepción');
        $this->get(route('inventory.import.create'))->assertSee('No se puede aplicar')->assertDontSee('Aplicar 1 ajuste');
    }

    public function test_history_is_shared_between_warehouse_and_administration_with_filters_and_escaped_content(): void
    {
        $warehouse = $this->warehouse();
        $import = SpreadsheetImport::create([
            'token' => 'f8f4a341-9f3d-41b5-bf05-63b6556c4bab', 'user_id' => $warehouse->id, 'module' => 'products',
            'filename' => '<script>alert(1)</script>.csv', 'format' => 'csv', 'mode' => 'upsert', 'status' => 'invalid',
            'preview' => ['changes' => [], 'total' => 1, 'errors' => ['Fila 2: <script>error</script>']],
        ]);
        $this->get(route('imports.index'))->assertSee('Historial de importaciones')->assertSee('script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $admin = User::factory()->create();
        $admin->assignRole('Administrador');

        $this->actingAs($admin)->withSession(['crepe.mode' => 'administracion'])->get(route('imports.index', ['module' => 'products', 'status' => 'invalid']))->assertSee('script&gt;', false);
        $this->get(route('imports.show', $import))->assertSee($warehouse->name)->assertSee('Fila 2: &lt;script&gt;error', false)->assertDontSee('<script>error</script>', false);
        $this->get(route('imports.index', ['module' => 'inventory']))->assertDontSee('alert(1)');
        $this->get(route('imports.index', ['module' => 'products; DROP TABLE products']))->assertInvalid(['module']);
    }

    #[TestWith(['Recepción'])]
    #[TestWith(['Color Bar'])]
    public function test_unprivileged_roles_cannot_download_import_or_read_shared_history(string $role): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $this->get(route('products.export'))->assertForbidden();
        $this->get(route('products.import.create'))->assertForbidden();
        $this->post(route('products.import.preview'))->assertForbidden();
        $this->post(route('products.import.store'))->assertForbidden();
        $this->get(route('inventory.export'))->assertForbidden();
        $this->get(route('inventory.import.create'))->assertForbidden();
        $this->get(route('imports.index'))->assertForbidden();
    }

    public function test_guests_cannot_access_product_files_or_history(): void
    {
        $this->get(route('products.export'))->assertRedirect(route('login'));
        $this->get(route('products.import.create'))->assertRedirect(route('login'));
        $this->post(route('products.import.preview'))->assertRedirect(route('login'));
        $this->post(route('products.import.store'))->assertRedirect(route('login'));
        $this->get(route('imports.index'))->assertRedirect(route('login'));
    }

    public function test_export_permission_does_not_allow_import_and_confirmation_rechecks_permissions(): void
    {
        $user = $this->warehouse();
        $balance = InventoryBalance::whereHas('location', fn ($query) => $query->where('code', 'ALM'))->firstOrFail();
        $this->post(route('inventory.import.preview'), ['file' => $this->inventoryFile('ALM', $balance->id, '1')])->assertSessionHasNoErrors();
        $import = SpreadsheetImport::sole();
        Role::findByName('Almacén')->revokePermissionTo(['inventory.import', 'products.import']);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $this->get(route('inventory.export'))->assertDownload();
        $this->get(route('products.export'))->assertDownload();
        $this->get(route('inventory.import.create'))->assertForbidden();
        $this->post(route('inventory.import.store'), ['token' => $import->token, 'reason' => 'No autorizado'])->assertForbidden();
        $this->get(route('products.import.create'))->assertForbidden();
        $this->assertDatabaseHas('spreadsheet_imports', ['id' => $import->id, 'status' => 'reviewed']);
    }

    public function test_expired_reviews_cannot_change_stock(): void
    {
        $this->warehouse();
        $this->freezeTime();
        $balance = InventoryBalance::whereHas('location', fn ($query) => $query->where('code', 'ALM'))->firstOrFail();
        $original = $balance->available_quantity;
        $this->post(route('inventory.import.preview'), ['file' => $this->inventoryFile('ALM', $balance->id, '1')])->assertSessionHasNoErrors();
        $import = SpreadsheetImport::sole();
        $this->travel(25)->hours();

        $this->from(route('inventory.import.create'))->post(route('inventory.import.store'), ['token' => $import->token, 'reason' => 'Vencida'])->assertInvalid(['file' => 'La revisión ya no está disponible.']);
        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => $original]);
    }

    public function test_csv_formula_text_is_neutralized_and_round_trips_without_losing_accents_or_codes(): void
    {
        $this->warehouse();
        $files = app(SpreadsheetFile::class);
        $csv = $files->writeCsv([['Título', 'SKU', 'Nombre'], ['=1+1', '001234', "'Nombre"], ['@SUM(1)', '+123', 'Mérida']]);
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString("'001234", $csv);
        $file = UploadedFile::fake()->createWithContent('seguro.csv', $csv);
        $this->assertSame(['A' => '=1+1', 'B' => '001234', 'C' => "'Nombre"], $files->read($file->getPathname(), 'csv', 'Datos', 3)[2]);
        $this->assertSame('Mérida', $files->read($file->getPathname(), 'csv', 'Datos', 3)[3]['C']);
    }

    public function test_result_and_history_hide_cost_changes_from_users_without_cost_permission(): void
    {
        $user = $this->warehouse();
        $import = SpreadsheetImport::create([
            'token' => 'ca3770ea-e041-4cba-95aa-72bd2b00942d', 'user_id' => $user->id, 'module' => 'products', 'filename' => 'precios.csv',
            'format' => 'csv', 'mode' => 'update', 'status' => 'applied', 'preview' => ['total' => 1, 'changes' => [[
                'row' => 2, 'action' => 'updated', 'name' => 'Producto', 'sku' => 'SKU', 'diff' => [
                    ['field' => 'cost', 'label' => 'Costo', 'before' => '123.4567', 'after' => '987.6543'],
                    ['field' => 'sale_price', 'label' => 'Precio venta', 'before' => '100.00', 'after' => '200.00'],
                ],
            ]]],
        ]);
        Role::findByName('Almacén')->revokePermissionTo('products.costs.view');
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $this->get(route('imports.show', $import))->assertSee('Precio venta')->assertDontSee('123.4567')->assertDontSee('987.6543');
        $this->get(route('imports.result', $import))->assertSee('Precio venta')->assertDontSee('123.4567')->assertDontSee('987.6543');
    }

    public function test_admin_can_restrict_spreadsheet_actions_without_changing_other_role_permissions(): void
    {
        $warehouse = $this->warehouse();
        $admin = User::factory()->create();
        $admin->assignRole('Administrador');
        $role = Role::findByName('Almacén');
        $this->actingAs($admin)->get(route('imports.permissions.index'))->assertSee('Permisos de archivos e importaciones');
        $revision = hash('sha256', json_encode($role->permissions->pluck('name')->sort()->values()->all(), JSON_THROW_ON_ERROR));
        $this->put(route('imports.permissions.update', $role), ['permissions' => ['products.export', 'inventory.export', 'imports.view'], 'revision' => $revision])->assertRedirect(route('imports.permissions.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'roles.spreadsheets.updated', 'subject_id' => $role->id, 'user_id' => $admin->id]);
        $warehouse->unsetRelation('roles')->unsetRelation('permissions');
        $this->actingAs($warehouse)->get(route('products.export'))->assertDownload();
        $this->get(route('inventory.export'))->assertDownload();
        $this->get(route('products.import.create'))->assertForbidden();
        $this->get(route('inventory.import.create'))->assertForbidden();
        $this->get(route('imports.index'))->assertOk();
        $this->assertTrue($warehouse->can('inventory.move'));
        $this->assertTrue($warehouse->can('products.manage'));
        $this->get(route('imports.permissions.index'))->assertForbidden();
        $this->put(route('imports.permissions.update', $role), ['permissions' => ['products.import'], 'revision' => $revision])->assertForbidden();
    }

    public function test_spreadsheet_restrictions_remain_effective_when_the_warehouse_module_is_configured(): void
    {
        $user = $this->warehouse();
        $role = Role::findByName('Almacén');
        AppSetting::put('access.role.'.$role->id, json_encode(['almacen']));
        Role::findByName('Almacén')->revokePermissionTo('products.import');
        $user->unsetRelation('roles')->unsetRelation('permissions');
        app(RoleModuleAccessService::class)->forget();

        $this->get(route('products.index'))->assertOk();
        $this->get(route('products.export'))->assertDownload();
        $this->get(route('products.import.create'))->assertForbidden();
        $this->get(route('inventory.import.create'))->assertOk();
    }

    public function test_permissions_protect_admin_reject_stale_revisions_and_disallow_unrelated_abilities(): void
    {
        $this->warehouse();
        $admin = User::factory()->create();
        $admin->assignRole('Administrador');
        $this->actingAs($admin);
        $role = Role::findByName('Almacén');
        $adminRole = Role::findByName('Administrador');

        $this->put(route('imports.permissions.update', $adminRole), [])->assertForbidden();
        $this->from(route('imports.permissions.index'))->put(route('imports.permissions.update', $role), ['revision' => str_repeat('0', 64)])->assertInvalid(['revision' => 'Otra persona actualizó']);
        $this->put(route('imports.permissions.update', $role), ['revision' => str_repeat('0', 64), 'permissions' => ['roles.manage']])->assertInvalid(['permissions.0']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'roles.spreadsheets.updated']);
    }

    public function test_inventory_detects_unit_changes_between_preview_and_confirmation(): void
    {
        $this->warehouse();
        $balance = InventoryBalance::whereHas('location', fn ($query) => $query->where('code', 'ALM'))->firstOrFail();
        $original = $balance->available_quantity;
        $this->post(route('inventory.import.preview'), ['file' => $this->inventoryFile('ALM', $balance->id, '1')])->assertSessionHasNoErrors();
        $import = SpreadsheetImport::sole();
        $balance->variant->update(['content_quantity' => 999]);

        $this->from(route('inventory.import.create'))->post(route('inventory.import.store'), ['token' => $import->token, 'reason' => 'Unidad cambió'])->assertInvalid(['file' => 'No se aplicó ningún ajuste.']);
        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => $original]);
        $this->assertDatabaseHas('spreadsheet_imports', ['id' => $import->id, 'status' => 'invalid']);
        $this->get(route('imports.show', $import))->assertSee('No se aplicó ningún ajuste.');
    }

    private function warehouse(): User
    {
        $this->seed(CrepeSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Almacén');
        $this->actingAs($user)->withSession(['crepe.mode' => 'almacen']);

        return $user;
    }

    private function inventoryFile(string $location, int $balanceId, string $quantity): UploadedFile
    {
        $csv = $this->get(route('inventory.export', ['format' => 'csv', 'location_code' => $location]))->assertDownload()->getContent();
        $lines = explode("\r\n", trim($csv));
        $rows = [str_getcsv(substr(array_shift($lines), 3), ',', '"', '')];
        foreach ($lines as $line) {
            $row = str_getcsv($line, ',', '"', '');
            if (($row[0] ?? '') === (string) $balanceId) {
                $row[9] = $quantity;
                $rows[] = $row;
            }
        }

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '', "\r\n");
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return UploadedFile::fake()->createWithContent('conteo.csv', $content);
    }
}
