<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SpreadsheetImport;
use App\Models\User;
use App\Services\InventoryWorkbook;
use App\Services\ProductSpreadsheetService;
use App\Services\SpreadsheetFile;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductSpreadsheetTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['csv'])]
    #[TestWith(['xlsx'])]
    public function test_partial_file_adds_five_products_only_after_confirmation_and_preserves_stock(string $format): void
    {
        $user = $this->warehouse();
        $productCount = Product::count();
        $balanceCount = InventoryBalance::count();
        $movementCount = InventoryMovement::count();
        $rows = [];
        for ($index = 1; $index <= 5; $index++) {
            $rows[] = $this->newRow(['product_sku' => 'NUEVO-'.$index, 'variant_sku' => 'NUEVO-'.$index.'-STD', 'name' => 'Producto nuevo '.$index]);
        }

        $this->post(route('products.import.preview'), ['file' => $this->file($rows, $format), 'mode' => 'upsert'])
            ->assertRedirect(route('products.import.create'))->assertSessionHasNoErrors();
        $import = SpreadsheetImport::sole();
        $this->assertDatabaseCount('products', $productCount);
        $this->get(route('products.import.create'))->assertSee('5 productos nuevos')->assertSee('Producto nuevo 5');
        $this->post(route('products.import.store'), ['token' => $import->token, 'reason' => 'Nuevos productos'])
            ->assertRedirect(route('products.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('products', $productCount + 5);
        $this->assertDatabaseHas('products', ['sku' => 'SH-REST', 'name' => 'Shampoo Restore', 'status' => 'active']);
        $this->assertDatabaseHas('product_variants', ['sku' => 'NUEVO-5-STD', 'cost' => 10, 'sale_price' => 25]);
        $this->assertDatabaseCount('inventory_balances', $balanceCount);
        $this->assertDatabaseCount('inventory_movements', $movementCount);
        $this->assertDatabaseHas('spreadsheet_imports', ['id' => $import->id, 'status' => 'applied', 'user_id' => $user->id, 'reason' => 'Nuevos productos']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'products.imported', 'user_id' => $user->id]);
    }

    public function test_partial_updates_preserve_blank_fields_apply_zero_and_allow_explicit_clear(): void
    {
        $this->warehouse();
        $variant = ProductVariant::where('sku', 'SH-REST-STD')->firstOrFail();
        $variant->product->update(['description' => 'Conservar descripción']);
        $cost = $variant->cost;
        $minimum = $variant->minimum_stock;

        $this->post(route('products.import.preview'), ['mode' => 'update', 'file' => $this->file([
            ['variant_sku' => 'SH-REST-STD', 'sale_price' => '0', 'description' => '[BORRAR]', 'maximum_stock' => '[BORRAR]'],
        ])])->assertSessionHasNoErrors();
        $import = SpreadsheetImport::sole();
        $this->get(route('products.import.create'))->assertSee('Precio venta')->assertSee('650.00');
        $this->post(route('products.import.store'), ['token' => $import->token, 'reason' => 'Actualización parcial'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'sale_price' => 0, 'cost' => $cost, 'minimum_stock' => $minimum, 'maximum_stock' => null]);
        $this->assertDatabaseHas('products', ['id' => $variant->product_id, 'name' => 'Shampoo Restore', 'description' => null]);
    }

    public function test_add_mode_skips_existing_records_and_update_mode_rejects_missing_records(): void
    {
        $this->warehouse();
        $this->post(route('products.import.preview'), ['mode' => 'add', 'file' => $this->file([
            ['variant_sku' => 'SH-REST-STD', 'sale_price' => '1'], $this->newRow(),
        ])])->assertSessionHasNoErrors();
        $import = SpreadsheetImport::sole();
        $this->assertSame(1, $import->preview['skipped']);
        $this->get(route('products.import.create'))->assertSee('Solo agregar')->assertSee('se omite');
        $this->post(route('products.import.store'), ['token' => $import->token, 'reason' => 'Solo altas'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('product_variants', ['sku' => 'SH-REST-STD', 'sale_price' => 650]);
        $this->post(route('products.import.preview'), ['mode' => 'update', 'file' => $this->file([$this->newRow(['product_sku' => 'FALTANTE', 'variant_sku' => 'FALTANTE-STD'])])])->assertSessionHasNoErrors();
        $this->get(route('products.import.create'))->assertSee('solo actualiza existentes')->assertDontSee('Confirmar importación');
        $this->assertDatabaseMissing('products', ['sku' => 'FALTANTE']);
    }

    #[TestWith(['sale_price', '-1', 'negativo'])]
    #[TestWith(['sale_price', '1.234', 'decimales'])]
    #[TestWith(['content_quantity', '0', 'mayor que cero'])]
    #[TestWith(['category', 'No existe', 'no está registrado'])]
    #[TestWith(['brand', 'Marca desconocida', 'Marca no registrada'])]
    #[TestWith(['supplier', 'Proveedor desconocido', 'Proveedor no registrado'])]
    #[TestWith(['maximum_stock', '1', 'mayor o igual'])]
    #[TestWith(['status', 'borrar', 'no permitido'])]
    #[TestWith(['base_unit', 'kg', 'no permitido'])]
    #[TestWith(['is_reception_usable', 'sí', '1 o 0'])]
    public function test_errors_block_all_rows_and_can_be_downloaded(string $field, string $value, string $message): void
    {
        $this->warehouse();
        $this->post(route('products.import.preview'), ['mode' => 'upsert', 'file' => $this->file([
            $this->newRow(), $this->newRow(['product_sku' => 'INVALIDO', 'variant_sku' => 'INVALIDO-STD', $field => $value]),
        ])])->assertSessionHasNoErrors();
        $import = SpreadsheetImport::sole();
        $this->get(route('products.import.create'))->assertSee('Fila 3:')->assertSee($message)->assertDontSee('Confirmar importación');
        $this->get(route('imports.result', $import))->assertDownload()->assertSee($message);
        $this->from(route('products.import.create'))->post(route('products.import.store'), ['token' => $import->token, 'reason' => 'No aplicar'])->assertInvalid(['file' => 'La revisión ya no está disponible.']);
        $this->assertDatabaseMissing('products', ['sku' => 'NUEVO']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'products.imported']);
    }

    public function test_duplicate_presentations_and_conflicting_shared_product_fields_are_rejected(): void
    {
        $this->warehouse();
        $this->post(route('products.import.preview'), ['mode' => 'upsert', 'file' => $this->file([$this->newRow(), $this->newRow()])])->assertSessionHasNoErrors();
        $this->get(route('products.import.create'))->assertSee('duplicado en el archivo');
        $this->post(route('products.import.preview'), ['mode' => 'upsert', 'file' => $this->file([
            $this->newRow(), $this->newRow(['variant_sku' => 'NUEVO-OTRO', 'name' => 'Nombre diferente']),
        ])])->assertSessionHasNoErrors();
        $this->get(route('products.import.create'))->assertSee('datos compartidos del producto difieren');
        $this->assertDatabaseMissing('products', ['sku' => 'NUEVO']);
    }

    public function test_multiple_presentations_for_one_new_product_create_one_product(): void
    {
        $this->warehouse();
        $this->post(route('products.import.preview'), ['mode' => 'upsert', 'file' => $this->file([
            $this->newRow(), $this->newRow(['variant_sku' => 'NUEVO-OTRO', 'variant_name' => 'Otra presentación']),
        ])])->assertSessionHasNoErrors();
        $import = SpreadsheetImport::sole();
        $this->post(route('products.import.store'), ['token' => $import->token, 'reason' => 'Presentaciones'])->assertSessionHasNoErrors();
        $this->assertSame(1, Product::where('sku', 'NUEVO')->count());
        $this->assertSame(2, Product::where('sku', 'NUEVO')->sole()->variants()->count());
    }

    public function test_changes_after_preview_roll_back_the_entire_import_and_cannot_be_replayed(): void
    {
        $this->warehouse();
        $variant = ProductVariant::where('sku', 'SH-REST-STD')->firstOrFail();
        $this->post(route('products.import.preview'), ['mode' => 'upsert', 'file' => $this->file([
            $this->newRow(), ['variant_sku' => 'SH-REST-STD', 'sale_price' => '700'],
        ])])->assertSessionHasNoErrors();
        $import = SpreadsheetImport::sole();
        $variant->update(['sale_price' => 710]);
        $this->from(route('products.import.create'))->post(route('products.import.store'), ['token' => $import->token, 'reason' => 'Conflicto'])->assertInvalid(['file' => 'cambió después de la revisión']);
        $this->assertDatabaseMissing('products', ['sku' => 'NUEVO']);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'sale_price' => 710]);
    }

    public function test_exported_file_detects_stale_catalog_and_preserves_leading_zero_codes(): void
    {
        $this->warehouse();
        $variant = ProductVariant::where('sku', 'SH-REST-STD')->firstOrFail();
        $variant->update(['sku' => '001234']);
        $export = $this->get(route('products.export', ['format' => 'csv', 'page' => 999, 'search' => 'no existe']))->assertDownload()->getContent();
        $this->assertStringContainsString("'001234", $export);
        $this->post(route('products.import.preview'), ['mode' => 'update', 'file' => UploadedFile::fake()->createWithContent('productos.csv', $export)])->assertSessionHasNoErrors();
        $this->get(route('products.import.create'))->assertSee('no contiene altas ni actualizaciones');
        $variant->update(['sale_price' => 999]);
        $this->post(route('products.import.preview'), ['mode' => 'update', 'file' => UploadedFile::fake()->createWithContent('productos.csv', $export)])->assertSessionHasNoErrors();
        $this->get(route('products.import.create'))->assertSee('cambió desde la descarga');
    }

    public function test_units_with_stock_or_movements_cannot_change_and_name_matches_only_warn(): void
    {
        $this->warehouse();
        $this->post(route('products.import.preview'), ['mode' => 'upsert', 'file' => $this->file([
            ['variant_sku' => 'SH-REST-STD', 'base_unit' => 'ml'],
        ])])->assertSessionHasNoErrors();
        $this->get(route('products.import.create'))->assertSee('con existencias o movimientos');
        $this->post(route('products.import.preview'), ['mode' => 'add', 'file' => $this->file([$this->newRow(['name' => 'Shampoo Restore'])])])->assertSessionHasNoErrors();
        $this->get(route('products.import.create'))->assertSee('otro SKU')->assertSee('Confirmar importación');
        $this->assertSame(1, Product::where('name', 'Shampoo Restore')->count());
    }

    public function test_confirmation_requires_owner_reason_current_permissions_and_a_fresh_unused_review(): void
    {
        $user = $this->warehouse();
        $this->post(route('products.import.preview'), ['mode' => 'add', 'file' => $this->file([$this->newRow()])])->assertSessionHasNoErrors();
        $import = SpreadsheetImport::sole();
        $other = User::factory()->create();
        $other->assignRole('Almacén');
        $this->actingAs($other)->get(route('products.import.create'))->assertDontSee('Confirmar importación');
        $this->from(route('products.import.create'))->post(route('products.import.store'), ['token' => $import->token, 'reason' => 'Otro usuario'])->assertInvalid(['file' => 'La revisión ya no está disponible.']);
        $this->actingAs($user)->post(route('products.import.store'), ['token' => $import->token])->assertInvalid(['reason' => 'Escribe el motivo']);
        $this->post(route('products.import.store'), ['token' => $import->token, 'reason' => 'Alta confirmada'])->assertSessionHasNoErrors();
        $this->post(route('products.import.store'), ['token' => $import->token, 'reason' => 'Repetición'])->assertInvalid(['file' => 'La revisión ya no está disponible.']);
        $this->assertSame(1, Product::where('sku', 'NUEVO')->count());
    }

    public function test_users_without_cost_permission_cannot_see_costs_or_modify_them(): void
    {
        $user = $this->warehouse();
        Role::findByName('Almacén')->revokePermissionTo('products.costs.view');
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $export = $this->get(route('products.export', ['format' => 'csv']))->assertDownload()->getContent();
        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\r\n", trim($export)));
        foreach (array_slice($rows, 1) as $row) {
            $this->assertSame('', $row[11]);
        }
        $this->post(route('products.import.preview'), ['mode' => 'update', 'file' => $this->file([['variant_sku' => 'SH-REST-STD', 'cost' => '1']])])->assertSessionHasNoErrors();
        $this->get(route('products.import.create'))->assertSee('No tienes permiso')->assertDontSee('Confirmar importación');
        $this->assertDatabaseHas('product_variants', ['sku' => 'SH-REST-STD', 'cost' => 210]);
    }

    public function test_export_has_editable_columns_lists_and_a_template_without_catalog_rows(): void
    {
        $this->warehouse();
        $content = $this->get(route('products.export'))->assertDownload()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'products-workbook-');
        file_put_contents($path, $content);
        try {
            $zip = new \ZipArchive;
            $zip->open($path);
            $sheet = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
            $this->assertSame('6', (string) $sheet->dataValidations['count']);
            $this->assertSame('1', (string) $sheet->sheetViews->sheetView->pane['ySplit']);
            $this->assertSame('inlineStr', (string) $sheet->xpath('//*[local-name()="c"][@r="B2"]')[0]['t']);
            $this->assertSame([], $sheet->xpath('//*[local-name()="f"]'));
            $zip->close();
        } finally {
            unlink($path);
        }
        $this->get(route('products.export', ['template' => 1, 'format' => 'csv']))->assertDownload()->assertSee('SKU presentación')->assertDontSee('SH-REST');
    }

    public function test_formula_cells_bad_headers_and_invalid_files_are_saved_as_errors_without_changes(): void
    {
        $this->warehouse();
        $this->post(route('products.import.preview'), ['mode' => 'upsert', 'file' => UploadedFile::fake()->createWithContent('productos.xlsx', 'No es un Excel')])->assertSessionHasNoErrors();
        $this->get(route('products.import.create'))->assertSee('El archivo no es un Excel')->assertDontSee('Confirmar importación');
        $this->post(route('products.import.preview'), ['mode' => 'upsert', 'file' => UploadedFile::fake()->createWithContent('productos.csv', "SKU,Nombre\nX,Producto")])->assertSessionHasNoErrors();
        $this->get(route('products.import.create'))->assertSee('Los encabezados no coinciden');
        $file = $this->file([$this->newRow()], 'xlsx');
        $zip = new \ZipArchive;
        $zip->open($file->getPathname());
        $sheet = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
        $sheet->sheetData->row[1]->c[11]->addChild('f', '1+1');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet->asXML());
        $zip->close();
        $this->post(route('products.import.preview'), ['mode' => 'upsert', 'file' => $file])->assertSessionHasNoErrors();
        $this->get(route('products.import.create'))->assertSee('usa valores, no fórmulas.');
        $this->assertDatabaseMissing('products', ['sku' => 'NUEVO']);
    }

    public function test_losing_price_permission_after_review_blocks_every_change(): void
    {
        $user = $this->warehouse();
        $this->post(route('products.import.preview'), ['mode' => 'update', 'file' => $this->file([['variant_sku' => 'SH-REST-STD', 'sale_price' => '700']])])->assertSessionHasNoErrors();
        $import = SpreadsheetImport::sole();
        Role::findByName('Almacén')->revokePermissionTo('products.prices.manage');
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $this->from(route('products.import.create'))->post(route('products.import.store'), ['token' => $import->token, 'reason' => 'Sin permiso'])->assertInvalid(['file' => 'permisos de costos o precios cambiaron']);
        $this->assertDatabaseHas('product_variants', ['sku' => 'SH-REST-STD', 'sale_price' => 650]);
        $this->assertDatabaseHas('spreadsheet_imports', ['id' => $import->id, 'status' => 'invalid']);
        $this->get(route('imports.show', $import))->assertSee('permisos de costos o precios cambiaron');
    }

    public function test_new_variant_for_existing_product_preserves_shared_data_and_does_not_move_stock(): void
    {
        $this->warehouse();
        $this->post(route('products.import.preview'), ['mode' => 'upsert', 'file' => $this->file([[
            'product_sku' => 'SH-REST', 'variant_sku' => 'SH-REST-NUEVO', 'variant_name' => '500 ml', 'base_unit' => 'unidad', 'content_quantity' => '1', 'cost' => '300',
        ]])])->assertSessionHasNoErrors();
        $import = SpreadsheetImport::sole();
        $this->get(route('products.import.create'))->assertSee('1 presentaciones nuevas');
        $this->post(route('products.import.store'), ['token' => $import->token, 'reason' => 'Otra presentación'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['sku' => 'SH-REST', 'name' => 'Shampoo Restore']);
        $this->assertSame(2, Product::where('sku', 'SH-REST')->sole()->variants()->count());
        $this->assertDatabaseHas('product_variants', ['sku' => 'SH-REST-NUEVO', 'cost' => 300]);
        $this->assertSame(0, InventoryBalance::whereHas('variant', fn ($query) => $query->where('sku', 'SH-REST-NUEVO'))->count());
    }

    public function test_untouched_legacy_rows_do_not_block_a_catalog_round_trip_or_change_their_values(): void
    {
        $this->warehouse();
        $variant = ProductVariant::where('sku', 'SH-REST-STD')->firstOrFail();
        $variant->update(['minimum_stock' => 10, 'maximum_stock' => 1]);
        $variant->product->update(['is_reception_usable' => false, 'is_color_bar_usable' => false, 'is_color_bar_supply' => false]);
        $csv = $this->get(route('products.export', ['format' => 'csv']))->assertDownload()->getContent();

        $this->post(route('products.import.preview'), ['mode' => 'update', 'file' => UploadedFile::fake()->createWithContent('productos.csv', $csv)])->assertSessionHasNoErrors();
        $import = SpreadsheetImport::sole();
        $this->assertSame('reviewed', $import->status);
        $this->assertSame([], $import->preview['changes']);
        $this->assertSame([], $import->preview['errors']);
        $this->get(route('products.import.create'))->assertSee('no contiene altas ni actualizaciones')->assertDontSee('Confirmar importación');
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'minimum_stock' => 10, 'maximum_stock' => 1, 'sale_price' => 650]);
        $this->assertDatabaseHas('products', ['id' => $variant->product_id, 'is_reception_usable' => false, 'is_color_bar_usable' => false, 'is_color_bar_supply' => false]);
    }

    public function test_import_supports_both_color_bar_modes_without_changing_inventory_units_or_stock(): void
    {
        $this->warehouse();
        $variant = ProductVariant::where('sku', 'SH-REST-STD')->firstOrFail();
        $variant->product->update(['is_color_bar_usable' => true, 'is_color_bar_supply' => false]);
        $variant->product->category->update(['color_bar_multiplier' => 8]);
        $balances = InventoryBalance::pluck('available_quantity', 'id')->all();
        $this->post(route('products.import.preview'), ['mode' => 'update', 'file' => $this->file([[
            'variant_sku' => 'SH-REST-STD', 'color_bar_pricing_type' => 'both', 'color_bar_sale_price' => '150',
        ]])])->assertSessionHasNoErrors();
        $import = SpreadsheetImport::sole();
        $this->assertSame('reviewed', $import->status);
        $this->post(route('products.import.store'), ['token' => $import->token, 'reason' => 'Ambos modos de cobro'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'color_bar_pricing_type' => 'both', 'color_bar_sale_price' => 150, 'base_unit' => 'unidad', 'sale_price' => 650]);
        $this->assertSame($balances, InventoryBalance::pluck('available_quantity', 'id')->all());
    }

    public function test_both_color_bar_mode_requires_a_category_multiplier_before_import(): void
    {
        $this->warehouse();
        $this->post(route('products.import.preview'), ['mode' => 'add', 'file' => $this->file([$this->newRow([
            'is_color_bar_usable' => '1', 'color_bar_pricing_type' => 'both',
        ])])])->assertSessionHasNoErrors();
        $this->get(route('products.import.create'))->assertSee('Categoría requiere un multiplicador')->assertDontSee('Confirmar importación');
        $this->assertDatabaseMissing('products', ['sku' => 'NUEVO']);
    }

    private function warehouse(): User
    {
        $this->seed(CrepeSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Almacén');
        $this->actingAs($user)->withSession(['crepe.mode' => 'almacen']);

        return $user;
    }

    private function newRow(array $overrides = []): array
    {
        return array_replace(['product_sku' => 'NUEVO', 'variant_sku' => 'NUEVO-STD', 'name' => 'Producto nuevo', 'variant_name' => 'Estándar', 'category' => 'Retail', 'base_unit' => 'unidad', 'content_quantity' => '1', 'cost' => '10', 'sale_price' => '25', 'minimum_stock' => '2', 'maximum_stock' => '6'], $overrides);
    }

    private function file(array $rows, string $format = 'csv'): UploadedFile
    {
        $values = array_map(fn (array $row): array => array_map(fn (string $key): string => (string) ($row[$key] ?? ''), array_keys(ProductSpreadsheetService::Columns)), $rows);
        $content = $format === 'csv'
            ? app(SpreadsheetFile::class)->writeCsv([array_values(ProductSpreadsheetService::Columns), ...$values])
            : app(InventoryWorkbook::class)->write($values, array_values(ProductSpreadsheetService::Columns), 'Productos', [], [], []);

        return UploadedFile::fake()->createWithContent('productos.'.$format, $content)->mimeType((new \finfo(FILEINFO_MIME_TYPE))->buffer($content));
    }
}
