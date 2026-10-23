<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\TestWith;
use SimpleXMLElement;
use Tests\TestCase;
use ZipArchive;

class InventorySpreadsheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_includes_all_active_warehouse_balances_and_preserves_text_as_text(): void
    {
        [$user, $balance] = $this->warehouse();
        $this->freezeTime();
        $balance->variant->product->update(['name' => '=HYPERLINK("https://example.com")']);
        $archived = ProductVariant::query()->where('sku', 'KOL-71-STD')->firstOrFail();
        $archived->product->update(['status' => 'inactive']);
        for ($index = 0; $index < 31; $index++) {
            $variant = $balance->variant->product->variants()->create(['name' => 'Presentación '.$index, 'sku' => 'EXPORT-'.$index, 'base_unit' => 'pieza', 'content_quantity' => 1, 'minimum_stock' => 0]);
            InventoryBalance::query()->create(['product_variant_id' => $variant->id, 'inventory_location_id' => $balance->inventory_location_id, 'available_quantity' => 12.125]);
        }

        $response = $this->actingAs($user)->get(route('inventory.export', ['search' => 'sin coincidencias', 'page' => 2, 'location' => 'REC']));
        $response->assertDownload('inventario-almacen-'.now()->format('Y-m-d-His').'.xlsx');
        $xml = $this->sheet($response->getContent());
        $skus = [];
        foreach ($xml->sheetData->row as $row) {
            foreach ($row->c as $cell) {
                if (str_starts_with((string) $cell['r'], 'B')) {
                    $skus[] = (string) $cell->is->t;
                }
            }
        }
        $this->assertContains('EXPORT-30', $skus);
        $this->assertContains('SH-REST-STD', $skus);
        $this->assertNotContains('KOL-71-STD', $skus);
        $this->assertSame('A1:M'.count($xml->sheetData->row), (string) $xml->autoFilter['ref']);
        $this->assertSame('1', (string) $xml->sheetViews->sheetView->pane['ySplit']);
        $this->assertSame('inlineStr', (string) $xml->xpath('//*[local-name()="c"][@r="C2"]')[0]['t']);
        $this->assertSame([], $xml->xpath('//*[local-name()="f"]'));
    }

    public function test_preview_then_confirmation_updates_only_warehouse_quantities_with_an_audit_trail(): void
    {
        [$user, $balance] = $this->warehouse();
        $otherLocation = InventoryBalance::query()->where('product_variant_id', $balance->product_variant_id)->where('inventory_location_id', '!=', $balance->inventory_location_id)->firstOrFail();
        $otherQuantity = $otherLocation->available_quantity;
        $movements = InventoryMovement::query()->count();
        $upload = $this->editedFile([$balance->id => '12.125']);

        $this->from(route('inventory.import.create'))->post(route('inventory.import.preview'), ['file' => $upload])
            ->assertRedirect(route('inventory.import.create'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 36]);
        $this->assertSame($movements, InventoryMovement::query()->count());
        $this->get(route('inventory.import.create'))->assertSee('12.125')->assertSee('1 existencia por actualizar');

        $this->post(route('inventory.import.store'), ['token' => session('inventory_import.token'), 'reason' => 'Conteo físico'])
            ->assertRedirect(route('inventory.index', ['location' => 'ALM']))->assertSessionHasNoErrors()->assertSessionMissing('inventory_import');

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 12.125]);
        $this->assertDatabaseHas('inventory_balances', ['id' => $otherLocation->id, 'available_quantity' => $otherQuantity]);
        $this->assertDatabaseHas('inventory_movements', ['product_variant_id' => $balance->product_variant_id, 'inventory_location_id' => $balance->inventory_location_id, 'type' => 'adjustment', 'quantity' => -23.875, 'before_quantity' => 36, 'after_quantity' => 12.125, 'created_by' => $user->id, 'reason' => 'Importación Excel: Conteo físico']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.imported', 'user_id' => $user->id, 'reason' => 'Conteo físico']);
        $this->assertSame($movements + 1, InventoryMovement::query()->count());
        $this->get(route('inventory.index', ['location' => 'ALM']))->assertSee('12.125');
        $this->get(route('activity.index', ['location' => 'ALM']))->assertSee('Importación Excel')->assertSee('Conteo físico');
    }

    public function test_zero_is_applied_and_a_confirmation_cannot_be_replayed(): void
    {
        [, $balance] = $this->warehouse();
        $this->post(route('inventory.import.preview'), ['file' => $this->editedFile([$balance->id => '0'])])->assertSessionHasNoErrors();
        $token = session('inventory_import.token');
        $this->post(route('inventory.import.store'), ['token' => $token, 'reason' => 'Sin existencia'])->assertSessionHasNoErrors();
        $count = InventoryMovement::query()->count();

        $this->from(route('inventory.import.create'))->post(route('inventory.import.store'), ['token' => $token, 'reason' => 'Reintento'])
            ->assertInvalid(['file' => 'La revisión ya no está disponible.']);

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 0]);
        $this->assertSame($count, InventoryMovement::query()->count());
    }

    #[TestWith(['-1'])]
    #[TestWith([''])]
    #[TestWith(['texto'])]
    #[TestWith(['1.2345'])]
    #[TestWith(['100000000000'])]
    public function test_invalid_quantities_reject_the_entire_file(string $quantity): void
    {
        [, $balance] = $this->warehouse();
        $upload = $this->editedFile([$balance->id => $quantity]);
        $movements = InventoryMovement::query()->count();

        $this->from(route('inventory.import.create'))->post(route('inventory.import.preview'), ['file' => $upload])
            ->assertInvalid(['file' => 'Existencia nueva debe ser un número entre 0 y 99999999999.999']);

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 36]);
        $this->assertSame($movements, InventoryMovement::query()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'inventory.imported']);
    }

    public function test_unchanged_rows_are_skipped_even_if_their_stock_has_moved_since_export(): void
    {
        [, $balance] = $this->warehouse();
        $upload = $this->editedFile([]);
        $balance->update(['available_quantity' => 35]);

        $this->post(route('inventory.import.preview'), ['file' => $upload])->assertSessionHasNoErrors();
        $this->get(route('inventory.import.create'))->assertSee('El archivo no contiene cambios de existencias.');

        $this->assertSame([], session('inventory_import.changes'));
        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 35]);
    }

    public function test_changed_stock_since_export_rejects_the_preview(): void
    {
        [, $balance] = $this->warehouse();
        $upload = $this->editedFile([$balance->id => '20']);
        $balance->update(['available_quantity' => 35]);

        $this->from(route('inventory.import.create'))->post(route('inventory.import.preview'), ['file' => $upload])
            ->assertInvalid(['file' => 'la existencia cambió desde la descarga.']);

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 35]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'inventory.imported']);
    }

    public function test_a_stock_change_after_preview_blocks_every_adjustment(): void
    {
        [, $balance] = $this->warehouse();
        $second = InventoryBalance::query()->where('inventory_location_id', $balance->inventory_location_id)->whereKeyNot($balance->id)->firstOrFail();
        $originalSecond = $second->available_quantity;
        $this->post(route('inventory.import.preview'), ['file' => $this->editedFile([$balance->id => '20', $second->id => '0'])])->assertSessionHasNoErrors();
        $second->update(['available_quantity' => (float) $originalSecond + 1]);
        $movements = InventoryMovement::query()->count();

        $this->from(route('inventory.import.create'))->post(route('inventory.import.store'), ['token' => session('inventory_import.token'), 'reason' => 'Conteo'])
            ->assertInvalid(['file' => 'No se aplicó ningún ajuste.']);

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 36]);
        $this->assertDatabaseHas('inventory_balances', ['id' => $second->id, 'available_quantity' => (float) $originalSecond + 1]);
        $this->assertSame($movements, InventoryMovement::query()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'inventory.imported']);
    }

    #[TestWith(['I', '35', 'cambió el ID, la existencia original o el control'])]
    #[TestWith(['M', 'control-alterado', 'cambió el ID, la existencia original o el control'])]
    #[TestWith(['A', '99999999', 'el ID no corresponde a un producto activo de Almacén'])]
    public function test_modified_control_data_is_rejected(string $column, string $value, string $message): void
    {
        [, $balance] = $this->warehouse();
        $upload = $this->editedFile([$balance->id => '20'], function (SimpleXMLElement $xml) use ($balance, $column, $value): void {
            foreach ($xml->sheetData->row as $row) {
                if ((string) $row->c[0]->v === (string) $balance->id) {
                    $cell = $row->xpath('*[local-name()="c"][starts-with(@r, "'.$column.'")]')[0];
                    if ($column === 'M') {
                        $cell->is->t = $value;
                    } else {
                        $cell->v = $value;
                    }
                }
            }
        });

        $this->from(route('inventory.import.create'))->post(route('inventory.import.preview'), ['file' => $upload])->assertInvalid(['file' => $message]);

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 36]);
    }

    public function test_duplicate_rows_are_rejected(): void
    {
        [, $balance] = $this->warehouse();
        $upload = $this->editedFile([$balance->id => '20'], function (SimpleXMLElement $xml) use ($balance): void {
            foreach ($xml->sheetData->row as $row) {
                if ((string) $row->c[0]->v === (string) $balance->id) {
                    $copy = dom_import_simplexml($row)->cloneNode(true);
                    $copy->setAttribute('r', '500');
                    foreach ($copy->childNodes as $cell) {
                        if ($cell instanceof \DOMElement) {
                            $cell->setAttribute('r', preg_replace('/[0-9]+/', '500', $cell->getAttribute('r')));
                        }
                    }
                    dom_import_simplexml($xml->sheetData)->appendChild($copy);
                    break;
                }
            }
        });

        $this->from(route('inventory.import.create'))->post(route('inventory.import.preview'), ['file' => $upload])
            ->assertInvalid(['file' => 'el producto está duplicado']);

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 36]);
    }

    public function test_formulas_and_damaged_headers_are_rejected(): void
    {
        [, $balance] = $this->warehouse();
        $upload = $this->editedFile([$balance->id => '20'], function (SimpleXMLElement $xml): void {
            $xml->sheetData->row[1]->c[9]->addChild('f', '1+1');
        });
        $this->from(route('inventory.import.create'))->post(route('inventory.import.preview'), ['file' => $upload])->assertInvalid(['file' => 'usa valores, no fórmulas.']);

        $upload = $this->editedFile([], function (SimpleXMLElement $xml): void {
            $xml->sheetData->row[0]->c[9]->is->t = 'Cantidad';
        });
        $this->from(route('inventory.import.create'))->post(route('inventory.import.preview'), ['file' => $upload])->assertInvalid(['file' => 'Los encabezados no coinciden.']);

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 36]);
    }

    public function test_guests_and_color_bar_users_cannot_access_warehouse_spreadsheets(): void
    {
        $this->get(route('inventory.export'))->assertRedirect(route('login'));
        $this->get(route('inventory.import.create'))->assertRedirect(route('login'));
        $this->post(route('inventory.import.preview'))->assertRedirect(route('login'));
        $this->post(route('inventory.import.store'))->assertRedirect(route('login'));
        $this->seed(CrepeSeeder::class);
        $receptionist = User::factory()->create();
        $receptionist->assignRole('Color Bar');

        $this->actingAs($receptionist)->get(route('inventory.export'))->assertForbidden();
        $this->get(route('inventory.import.create'))->assertForbidden();
        $this->post(route('inventory.import.preview'))->assertForbidden();
        $this->post(route('inventory.import.store'))->assertForbidden();
        $this->get(route('inventory.index', ['location' => 'REC']))->assertDontSee('Importar Excel')->assertDontSee('Descargar Excel');
    }

    public function test_excel_shared_strings_and_partial_reordered_rows_preserve_the_product_identity(): void
    {
        [, $balance] = $this->warehouse();
        $upload = $this->editedFile([$balance->id => '40'], function (SimpleXMLElement $xml, ZipArchive $zip) use ($balance): void {
            foreach (iterator_to_array($xml->sheetData->row, false) as $row) {
                if ((int) $row['r'] === 1) {
                    continue;
                }
                if ((string) $row->c[0]->v !== (string) $balance->id) {
                    $node = dom_import_simplexml($row);
                    $node->parentNode->removeChild($node);

                    continue;
                }
                $row['r'] = '25';
                foreach ($row->c as $cell) {
                    $cell['r'] = preg_replace('/[0-9]+/', '25', (string) $cell['r']);
                }
                unset($row->c[1]->is);
                $row->c[1]['t'] = 's';
                $row->c[1]->addChild('v', '0');
            }
            $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><r><t>SH-REST</t></r><r><t>-STD</t></r></si></sst>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', str_replace('Target="worksheets/sheet1.xml"', 'Target="/xl/worksheets/sheet1.xml"', $zip->getFromName('xl/_rels/workbook.xml.rels')));
        });

        $this->post(route('inventory.import.preview'), ['file' => $upload])->assertSessionHasNoErrors();

        $this->assertSame(1, session('inventory_import.total'));
        $this->assertSame($balance->id, session('inventory_import.changes.0.id'));
        $this->assertSame('40.000', session('inventory_import.changes.0.after'));
        $this->post(route('inventory.import.store'), ['token' => session('inventory_import.token'), 'reason' => 'Reposición contada'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 40]);
        $this->assertDatabaseHas('inventory_movements', ['product_variant_id' => $balance->product_variant_id, 'quantity' => 4, 'after_quantity' => 40]);
    }

    public function test_archiving_a_product_after_preview_blocks_the_import(): void
    {
        [, $balance] = $this->warehouse();
        $this->post(route('inventory.import.preview'), ['file' => $this->editedFile([$balance->id => '20'])])->assertSessionHasNoErrors();
        $balance->variant->product->update(['status' => 'inactive']);

        $this->from(route('inventory.import.create'))->post(route('inventory.import.store'), ['token' => session('inventory_import.token'), 'reason' => 'Conteo'])
            ->assertInvalid(['file' => 'la existencia o el producto cambió después de la revisión.']);

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 36]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'inventory.imported']);
    }

    public function test_inventory_actions_are_visible_in_warehouse_and_a_review_belongs_to_its_user(): void
    {
        [, $balance] = $this->warehouse();
        $this->get(route('inventory.index', ['location' => 'ALM']))->assertSee('Descargar Excel')->assertSee('Importar Excel');
        $this->post(route('inventory.import.preview'), ['file' => $this->editedFile([$balance->id => '20'])])->assertSessionHasNoErrors();
        $token = session('inventory_import.token');
        $otherUser = User::factory()->create();
        $otherUser->assignRole('Almacén');

        $this->actingAs($otherUser)->get(route('inventory.import.create'))->assertDontSee('Aplicar 1 ajuste');
        $this->from(route('inventory.import.create'))->post(route('inventory.import.store'), ['token' => $token, 'reason' => 'Conteo'])
            ->assertInvalid(['file' => 'La revisión ya no está disponible.']);

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 36]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'inventory.imported']);
    }

    public function test_upload_requires_a_real_xlsx_and_confirmation_requires_a_reason(): void
    {
        [, $balance] = $this->warehouse();
        $this->from(route('inventory.import.create'))->post(route('inventory.import.preview'))
            ->assertInvalid(['file' => 'Selecciona el Excel o CSV que editaste.']);
        $this->from(route('inventory.import.create'))->post(route('inventory.import.preview'), ['file' => UploadedFile::fake()->createWithContent('inventario.xlsx', 'Esto no es un Excel')])
            ->assertInvalid(['file' => 'El archivo no es un Excel .xlsx válido.']);
        $this->post(route('inventory.import.preview'), ['file' => $this->editedFile([$balance->id => '20'])])->assertSessionHasNoErrors();

        $this->from(route('inventory.import.create'))->post(route('inventory.import.store'), ['token' => session('inventory_import.token')])
            ->assertInvalid(['reason' => 'Escribe el motivo del ajuste.']);

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'available_quantity' => 36]);
    }

    /** @return array{User, InventoryBalance} */
    private function warehouse(): array
    {
        $this->seed(CrepeSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Almacén');
        $this->actingAs($user)->withSession(['crepe.mode' => 'almacen']);
        $balance = InventoryBalance::query()->with('variant.product')->whereHas('location', fn ($query) => $query->where('code', 'ALM'))
            ->whereHas('variant', fn ($query) => $query->where('sku', 'SH-REST-STD'))->firstOrFail();

        return [$user, $balance];
    }

    /** @param array<int, string> $quantities */
    private function editedFile(array $quantities, ?callable $edit = null): UploadedFile
    {
        $bytes = $this->get(route('inventory.export'))->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'inventory-test-');
        file_put_contents($path, $bytes);
        try {
            $zip = new ZipArchive;
            $zip->open($path);
            $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
            foreach ($xml->sheetData->row as $row) {
                $id = (int) $row->c[0]->v;
                if (array_key_exists($id, $quantities)) {
                    $row->c[9]->v = $quantities[$id];
                }
            }
            if ($edit !== null) {
                $edit($xml, $zip);
            }
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml->asXML());
            $zip->close();

            $content = file_get_contents($path);

            return UploadedFile::fake()->createWithContent('inventario.xlsx', $content)->mimeType((new \finfo(FILEINFO_MIME_TYPE))->buffer($content));
        } finally {
            unlink($path);
        }
    }

    private function sheet(string $bytes): SimpleXMLElement
    {
        $path = tempnam(sys_get_temp_dir(), 'inventory-test-');
        file_put_contents($path, $bytes);
        try {
            $zip = new ZipArchive;
            $zip->open($path);
            $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
            $zip->close();

            return $xml;
        } finally {
            unlink($path);
        }
    }
}
