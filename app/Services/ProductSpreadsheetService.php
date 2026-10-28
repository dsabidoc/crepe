<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\SpreadsheetImport;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductSpreadsheetService
{
    public const Columns = [
        'id' => 'ID presentación', 'product_sku' => 'SKU producto', 'variant_sku' => 'SKU presentación',
        'name' => 'Producto', 'variant_name' => 'Presentación', 'category' => 'Categoría', 'brand' => 'Marca',
        'supplier' => 'Proveedor', 'description' => 'Descripción', 'base_unit' => 'Unidad', 'content_quantity' => 'Contenido',
        'cost' => 'Costo', 'sale_price' => 'Precio venta', 'color_bar_sale_price' => 'Precio Color Bar',
        'color_bar_pricing_type' => 'Cobro Color Bar', 'minimum_stock' => 'Mínimo', 'maximum_stock' => 'Máximo',
        'is_reception_usable' => 'Recepción', 'is_color_bar_usable' => 'Fórmulas', 'is_color_bar_supply' => 'Insumo Color Bar',
        'status' => 'Estado', 'control' => 'Control',
    ];

    private const ProductFields = ['sku', 'name', 'product_category_id', 'product_brand_id', 'brand', 'preferred_supplier_id', 'description', 'is_reception_usable', 'is_color_bar_usable', 'is_color_bar_supply', 'status'];

    private const VariantFields = ['sku', 'name', 'base_unit', 'content_quantity', 'cost', 'sale_price', 'color_bar_sale_price', 'color_bar_pricing_type', 'minimum_stock', 'maximum_stock'];

    public function __construct(private InventoryWorkbook $workbook, private SpreadsheetFile $files) {}

    public function export(string $format, bool $template = false, bool $costs = true): string
    {
        $rows = [];
        if (! $template) {
            $variants = ProductVariant::query()->with(['product.category', 'product.preferredSupplier'])->orderBy('product_id')->orderBy('id')->limit(10001)->get();
            if ($variants->count() > 10000) {
                throw ValidationException::withMessages(['file' => 'La exportación admite hasta 10,000 presentaciones.']);
            }
            foreach ($variants as $variant) {
                $row = $this->values($variant);
                if (! $costs) {
                    $row['cost'] = '';
                }
                $row['control'] = $this->signature($this->snapshot($variant));
                $rows[] = array_values($row);
            }
        }
        if ($format === 'csv') {
            return $this->files->writeCsv([array_values(self::Columns), ...$rows]);
        }
        $instructions = [
            ['CATÁLOGO DE PRODUCTOS · CREPÉ'], ['Descargado: '.now()->format('d/m/Y H:i')],
            ['Una fila por presentación. Puedes subir únicamente las filas que necesites; las ausentes no se eliminan.'],
            ['Para altas deja ID y Control vacíos. SKU producto agrupa presentaciones; SKU presentación debe ser único.'],
            ['En actualizaciones los campos vacíos conservan el valor actual; 0 es un valor.'],
            ['Escribe [BORRAR] para quitar marca, proveedor, descripción o máximo. Los otros campos no se pueden borrar.'],
            ['Usa categorías, marcas y proveedores ya registrados. Las marcas y proveedores nuevos deben estar activos.'],
            ['Unidad de inventario: unidad. Contenido: 1. Los gramos se capturan únicamente en fórmulas; no cambies los datos históricos de presentación.'],
            ['Recepción, Fórmulas e Insumo Color Bar: 1 o 0. Estado: active o inactive. Cobro Color Bar: fixed, multiplier o both.'],
            ['Al menos un uso debe ser 1. multiplier y both requieren categoría con multiplicador. both permite aplicación y gramos.'],
            ['Altas: SKU producto, SKU presentación, Producto, Presentación, Categoría, Unidad, Contenido y Costo obligatorios.'],
            ['No uses fórmulas. Conserva los encabezados y Control. Revisa y confirma antes de aplicar.'],
            ['Esta carga no modifica existencias. Usa Inventario para registrar un conteo físico.'],
        ];

        return $this->workbook->write($rows, array_values(self::Columns), 'Productos', $instructions, range(1, 20), [0, 10, 11, 12, 13, 15, 16, 17, 18, 19], [9 => ['unidad'], 14 => ['fixed', 'multiplier', 'both'], 17 => ['0', '1'], 18 => ['0', '1'], 19 => ['0', '1'], 20 => ['active', 'inactive']], [11 => 4, 12 => 2, 13 => 2]);
    }

    /** @return array{changes: list<array<string, mixed>>, unchanged: int, skipped: int, total: int, errors: list<string>, warnings: list<string>} */
    public function preview(string $path, string $format, string $mode, bool $costs = true, bool $prices = true): array
    {
        $rows = $this->files->read($path, $format, 'Productos', count(self::Columns));
        foreach (array_values(self::Columns) as $index => $header) {
            if (($rows[1][InventoryWorkbook::columnName($index)] ?? '') !== $header) {
                throw ValidationException::withMessages(['file' => 'Los encabezados no coinciden. Usa la plantilla de Productos sin cambiar sus columnas.']);
            }
        }
        unset($rows[1]);
        $changes = $errors = $warnings = $seen = $products = $newProducts = [];
        $unchanged = $skipped = $total = 0;
        foreach ($rows as $number => $row) {
            if (count(array_filter($row, fn (string $value): bool => trim($value) !== '')) === 0) {
                continue;
            }
            $total++;
            try {
                $data = [];
                foreach (array_keys(self::Columns) as $index => $key) {
                    $data[$key] = trim($row[InventoryWorkbook::columnName($index)] ?? '');
                }
                $variant = null;
                if ($data['id'] !== '') {
                    if (! ctype_digit($data['id']) || ! ($variant = ProductVariant::query()->with('product')->find($data['id']))) {
                        $this->invalid('ID presentación no existe.');
                    }
                } elseif ($data['variant_sku'] !== '') {
                    $variant = ProductVariant::query()->with('product')->where('sku', $data['variant_sku'])->first();
                }
                $key = $variant ? 'id:'.$variant->id : 'sku:'.mb_strtolower($data['variant_sku']);
                if (isset($seen[$key])) {
                    $this->invalid('SKU presentación o ID duplicado en el archivo.');
                }
                $seen[$key] = true;
                if (! $variant && $data['control'] !== '') {
                    $this->invalid('Control corresponde a una presentación que ya no se identifica. Conserva el ID o descarga el catálogo nuevamente.');
                }
                if ($variant && $data['control'] !== '' && ! hash_equals($this->signature($this->snapshot($variant)), $data['control'])) {
                    $this->invalid('El producto cambió desde la descarga o cambió el ID. Descarga el catálogo nuevamente.');
                }
                if ($variant && $mode === 'add') {
                    $skipped++;
                    $warnings[] = "Fila {$number}: ya existe {$variant->sku}; se omite porque elegiste solo agregar.";

                    continue;
                }
                if (! $variant && $mode === 'update') {
                    $this->invalid('La presentación no existe; el modo seleccionado solo actualiza existentes.');
                }
                $product = $variant?->product ?? Product::query()->where('sku', $data['product_sku'])->first();
                if ($variant && $data['product_sku'] !== '' && $data['product_sku'] !== $product->sku) {
                    $this->invalid('SKU producto no corresponde al ID o SKU presentación. Edita el código del producto desde su ficha.');
                }
                $before = $variant ? $this->values($variant) : [];
                if ($variant && collect($data)->every(fn (string $value, string $field): bool => in_array($field, ['id', 'control'], true) || $value === '' || $value === (string) ($before[$field] ?? ''))) {
                    $unchanged++;

                    continue;
                }
                $values = array_replace([
                    'product_sku' => $product?->sku ?? '', 'name' => $product?->name ?? '', 'category' => $product?->category?->name ?? '',
                    'brand' => $product?->brand ?? '', 'supplier' => $product?->preferredSupplier?->name ?? '', 'description' => $product?->description ?? '',
                    'variant_sku' => '', 'variant_name' => '', 'base_unit' => '', 'content_quantity' => '', 'cost' => '',
                    'sale_price' => '0', 'color_bar_sale_price' => '0', 'color_bar_pricing_type' => 'fixed', 'minimum_stock' => '0', 'maximum_stock' => '',
                    'is_reception_usable' => (string) (int) ($product?->is_reception_usable ?? true),
                    'is_color_bar_usable' => (string) (int) ($product?->is_color_bar_usable ?? false),
                    'is_color_bar_supply' => (string) (int) ($product?->isColorBarSupply() ?? false), 'status' => $product?->status ?? 'active',
                ], $before, array_filter($data, fn (string $value): bool => $value !== ''));
                foreach (['brand', 'supplier', 'description', 'maximum_stock'] as $field) {
                    if ($values[$field] === '[BORRAR]') {
                        $values[$field] = '';
                    }
                }
                $prepared = $this->prepare($values, $product, $variant);
                if (! $costs && (! $variant || $prepared['variant']['cost'] !== $before['cost'])) {
                    $this->invalid('No tienes permiso para establecer o modificar costos.');
                }
                if (! $prices && (! $variant || $prepared['variant']['sale_price'] !== $before['sale_price'] || $prepared['variant']['color_bar_sale_price'] !== $before['color_bar_sale_price'] || $prepared['variant']['color_bar_pricing_type'] !== $before['color_bar_pricing_type'])) {
                    $this->invalid('No tienes permiso para establecer o modificar precios.');
                }
                $productKey = $product ? 'id:'.$product->id : 'sku:'.mb_strtolower($prepared['product']['sku']);
                if (isset($products[$productKey]) && $products[$productKey] !== $prepared['product']) {
                    $this->invalid('Los datos compartidos del producto difieren entre presentaciones. Usa los mismos datos en todas sus filas.');
                }
                $products[$productKey] = $prepared['product'];
                $after = array_replace($values, ['id' => $variant?->id ?? '', 'control' => '']);
                $after = array_replace($after, [
                    'cost' => $prepared['variant']['cost'], 'content_quantity' => $prepared['variant']['content_quantity'],
                    'sale_price' => $prepared['variant']['sale_price'], 'color_bar_sale_price' => $prepared['variant']['color_bar_sale_price'],
                    'minimum_stock' => $prepared['variant']['minimum_stock'], 'maximum_stock' => $prepared['variant']['maximum_stock'] ?? '',
                ]);
                $diff = [];
                foreach (self::Columns as $field => $label) {
                    if (in_array($field, ['id', 'control'], true)) {
                        continue;
                    }
                    if (! $variant || (string) ($before[$field] ?? '') !== (string) $after[$field]) {
                        $diff[] = ['field' => $field, 'label' => $label, 'before' => $before[$field] ?? '', 'after' => $after[$field]];
                    }
                }
                if ($diff === []) {
                    $unchanged++;

                    continue;
                }
                if (! $product && Product::query()->where('name', $prepared['product']['name'])->exists()) {
                    $warnings[] = "Fila {$number}: existe un producto con ese nombre y otro SKU. Se agregará como producto distinto.";
                }
                $changes[] = [
                    'row' => $number, 'action' => $variant ? 'updated' : ($product || isset($newProducts[$productKey]) ? 'variant_added' : 'added'),
                    'name' => $prepared['product']['name'].' · '.$prepared['variant']['name'], 'sku' => $prepared['variant']['sku'],
                    'variant_id' => $variant?->id, 'product_id' => $product?->id,
                    'snapshot' => $variant ? $this->snapshot($variant) : ($product ? ['product' => $product->only(self::ProductFields)] : null),
                    'product' => $prepared['product'], 'variant' => $prepared['variant'], 'diff' => $diff,
                ];
                $newProducts[$productKey] = true;
            } catch (ValidationException $exception) {
                foreach ($exception->validator->errors()->all() as $message) {
                    $errors[] = "Fila {$number}: {$message}";
                }
            }
        }
        if ($total === 0) {
            $errors[] = 'El archivo no contiene filas de productos.';
        }

        return compact('changes', 'unchanged', 'skipped', 'total', 'errors', 'warnings');
    }

    /** @param list<array{product_id: ?int, variant_id: ?int, product: array<string, mixed>, variant: array<string, mixed>, snapshot: ?array, row: int, diff: list<array<string, mixed>>}> $changes */
    public function apply(array $changes, int $actorId, string $reason, int $importId): int
    {
        return DB::transaction(function () use ($changes, $actorId, $reason, $importId): int {
            foreach ($changes as $change) {
                $product = $change['product_id'] ? Product::query()->lockForUpdate()->find($change['product_id']) : null;
                $variant = $change['variant_id'] ? ProductVariant::query()->lockForUpdate()->find($change['variant_id']) : null;
                if ($variant && collect($change['diff'])->contains(fn (array $diff): bool => in_array($diff['field'], ['base_unit', 'content_quantity'], true))) {
                    $variant->balances()->orderBy('id')->lockForUpdate()->get();
                }
                if ($change['snapshot'] !== null) {
                    $current = $variant && $product ? $this->snapshot($variant->setRelation('product', $product)) : ($product ? ['product' => $product->only(self::ProductFields)] : null);
                    if ($current !== $change['snapshot']) {
                        $this->invalid("Fila {$change['row']}: el producto cambió después de la revisión. No se aplicó ningún cambio. Revisa el archivo nuevamente.");
                    }
                }
                if (! $product && Product::query()->where('sku', $change['product']['sku'])->exists()) {
                    $this->invalid("Fila {$change['row']}: el SKU producto ya existe. Revisa el archivo nuevamente.");
                }
                if (! $variant && ProductVariant::query()->where('sku', $change['variant']['sku'])->exists()) {
                    $this->invalid("Fila {$change['row']}: el SKU presentación ya existe. Revisa el archivo nuevamente.");
                }
                $values = $this->inputValues($change['product'], $change['variant']);
                $prepared = $this->prepare($values, $product, $variant);
                if ($prepared['product'] !== $change['product'] || $prepared['variant'] !== $change['variant']) {
                    $this->invalid("Fila {$change['row']}: cambió un catálogo relacionado. Revisa el archivo nuevamente.");
                }
            }
            $created = [];
            foreach ($changes as $change) {
                $product = $change['product_id'] ? Product::findOrFail($change['product_id']) : ($created[$change['product']['sku']] ?? null);
                if ($product === null) {
                    $product = Product::create($change['product']);
                    $created[$change['product']['sku']] = $product;
                } else {
                    $product->update($change['product']);
                }
                if ($change['variant_id']) {
                    $updatedVariant = ProductVariant::findOrFail($change['variant_id']);
                    $updatedVariant->update($change['variant']);
                } else {
                    $updatedVariant = $product->variants()->create($change['variant']);
                }
                foreach (InventoryLocation::query()->whereIn('code', ['ALM', 'REC', 'CB'])->get() as $location) {
                    $balance = InventoryBalance::query()->firstOrCreate(['product_variant_id' => $updatedVariant->id, 'inventory_location_id' => $location->id], ['available_quantity' => 0, 'minimum_stock' => $updatedVariant->minimum_stock, 'maximum_stock' => $updatedVariant->maximum_stock, 'limits_configured' => true]);
                    if ($location->code === 'ALM') {
                        $balance->forceFill(['minimum_stock' => $updatedVariant->minimum_stock, 'maximum_stock' => $updatedVariant->maximum_stock, 'limits_configured' => true])->save();
                    }
                }
            }
            AuditLog::create([
                'user_id' => $actorId, 'action' => 'products.imported', 'subject_type' => SpreadsheetImport::class, 'subject_id' => $importId,
                'before' => ['items' => array_column($changes, 'snapshot')],
                'after' => ['items' => array_map(fn (array $change): array => ['product' => $change['product'], 'variant' => $change['variant']], $changes)],
                'reason' => mb_substr($reason, 0, 255), 'correlation_id' => (string) Str::uuid(),
            ]);

            return count($changes);
        });
    }

    /** @return array{product: array<string, mixed>, variant: array<string, mixed>} */
    private function prepare(array $values, ?Product $product, ?ProductVariant $variant): array
    {
        $validator = Validator::make($values, [
            'product_sku' => [$product?->sku === null && $product !== null ? 'nullable' : 'required', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($product)],
            'variant_sku' => [$variant ? 'nullable' : 'required', 'string', 'max:100', Rule::unique('product_variants', 'sku')->ignore($variant)],
            'name' => ['required', 'string', 'max:150'], 'variant_name' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::exists('product_categories', 'name')], 'brand' => ['nullable', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'],
            'base_unit' => ['required', Rule::in(array_unique(['g', 'ml', 'l', 'unidad', ...($variant ? [$variant->base_unit] : [])]))],
            'content_quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
            'cost' => ['required', 'numeric', 'min:0', 'max:99999999.9999', 'decimal:0,4'],
            'sale_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'color_bar_sale_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'minimum_stock' => ['required', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'maximum_stock' => ['nullable', 'numeric', 'gte:minimum_stock', 'max:999999999.999', 'decimal:0,3'],
            'is_reception_usable' => ['required', 'boolean'], 'is_color_bar_usable' => ['required', 'boolean'], 'is_color_bar_supply' => ['required', 'boolean'],
            'color_bar_pricing_type' => ['required', Rule::in(['fixed', 'multiplier', 'both'])], 'status' => ['required', Rule::in(['active', 'inactive'])],
        ], [
            'required' => ':attribute es obligatorio.', 'unique' => ':attribute ya existe.', 'exists' => ':attribute no está registrado.',
            'in' => ':attribute tiene un valor no permitido.', 'numeric' => ':attribute debe ser numérico.', 'min' => ':attribute no puede ser negativo.',
            'max' => ':attribute excede el límite permitido.', 'gt' => ':attribute debe ser mayor que cero.', 'gte' => 'Máximo debe ser mayor o igual a Mínimo.',
            'decimal' => ':attribute tiene demasiados decimales.', 'boolean' => ':attribute debe ser 1 o 0.',
        ], self::Columns);
        $validator->validate();
        foreach (['minimum_stock', 'maximum_stock'] as $field) {
            if ($values[$field] !== '' && $values[$field] !== null && (float) $values[$field] !== floor((float) $values[$field])) {
                $this->invalid('Mínimo y Máximo deben ser cantidades enteras de unidades.');
            }
        }
        $category = ProductCategory::query()->where('name', $values['category'])->firstOrFail();
        $brand = $values['brand'] !== '' ? ProductBrand::query()->where('name', $values['brand'])->first() : null;
        $supplier = $values['supplier'] !== '' ? Supplier::query()->where('name', $values['supplier'])->first() : null;
        if ($values['brand'] !== '' && $values['brand'] !== $product?->brand && (! $brand || ! $brand->is_active)) {
            $this->invalid('Marca no registrada o inactiva. Créala o actívala desde Catálogos.');
        }
        if ($values['supplier'] !== '' && $values['supplier'] !== $product?->preferredSupplier?->name && (! $supplier || ! $supplier->is_active)) {
            $this->invalid('Proveedor no registrado o inactivo. Créalo o actívalo desde Catálogos.');
        }
        if (! $values['is_reception_usable'] && ! $values['is_color_bar_usable'] && ! $values['is_color_bar_supply']) {
            $this->invalid('Selecciona al menos un uso: Recepción, Fórmulas o Insumo Color Bar.');
        }
        if ($values['is_color_bar_usable'] && in_array($values['color_bar_pricing_type'], ['multiplier', 'both'], true) && ($product?->colorBarRateCategory ?? $category)->color_bar_multiplier === null) {
            $this->invalid('Categoría requiere un multiplicador para el cobro de Color Bar.');
        }
        $keepsLegacyPresentation = $variant !== null
            && $values['base_unit'] === $variant->base_unit
            && (float) $values['content_quantity'] === (float) $variant->content_quantity;
        if (! $keepsLegacyPresentation && ($values['base_unit'] !== 'unidad' || (float) $values['content_quantity'] !== 1.0)) {
            $this->invalid('El inventario se registra en unidad y Contenido 1. Los gramos se capturan únicamente en las fórmulas. Se conservan las presentaciones con existencias o movimientos.');
        }
        if ($variant !== null) {
            $values['base_unit'] = $variant->base_unit;
            $values['content_quantity'] = $variant->content_quantity;
        }

        return [
            'product' => [
                'sku' => $values['product_sku'] ?: null, 'name' => $values['name'], 'product_category_id' => $category->id,
                'product_brand_id' => $brand?->id, 'brand' => $values['brand'] ?: null, 'preferred_supplier_id' => $supplier?->id,
                'description' => $values['description'] ?: null, 'is_reception_usable' => (bool) $values['is_reception_usable'],
                'is_color_bar_usable' => (bool) $values['is_color_bar_usable'], 'is_color_bar_supply' => (bool) $values['is_color_bar_supply'], 'status' => $values['status'],
            ],
            'variant' => [
                'sku' => $values['variant_sku'] ?: null, 'name' => $values['variant_name'], 'base_unit' => $values['base_unit'],
                'content_quantity' => number_format((float) $values['content_quantity'], 3, '.', ''), 'cost' => number_format((float) $values['cost'], 4, '.', ''),
                'sale_price' => number_format((float) $values['sale_price'], 2, '.', ''),
                'color_bar_sale_price' => number_format((float) $values['color_bar_sale_price'], 2, '.', ''),
                'color_bar_pricing_type' => $values['color_bar_pricing_type'], 'minimum_stock' => number_format((float) $values['minimum_stock'], 3, '.', ''),
                'maximum_stock' => $values['maximum_stock'] === '' ? null : number_format((float) $values['maximum_stock'], 3, '.', ''),
            ],
        ];
    }

    private function inputValues(array $product, array $variant): array
    {
        return array_replace($product, $variant, [
            'product_sku' => $product['sku'] ?? '', 'variant_sku' => $variant['sku'] ?? '', 'name' => $product['name'], 'variant_name' => $variant['name'],
            'category' => ProductCategory::find($product['product_category_id'])?->name ?? '',
            'brand' => $product['brand'] ?? '', 'supplier' => Supplier::find($product['preferred_supplier_id'])?->name ?? '',
            'description' => $product['description'] ?? '', 'maximum_stock' => $variant['maximum_stock'] ?? '',
        ]);
    }

    private function values(ProductVariant $variant): array
    {
        $product = $variant->product;

        return [
            'id' => $variant->id, 'product_sku' => $product->sku ?? '', 'variant_sku' => $variant->sku ?? '',
            'name' => $product->name, 'variant_name' => $variant->name, 'category' => $product->category?->name ?? '',
            'brand' => $product->brand ?? '', 'supplier' => $product->preferredSupplier?->name ?? '', 'description' => $product->description ?? '',
            'base_unit' => 'unidad', 'content_quantity' => '1', 'cost' => $variant->cost,
            'sale_price' => $variant->sale_price, 'color_bar_sale_price' => $variant->color_bar_sale_price,
            'color_bar_pricing_type' => $variant->color_bar_pricing_type, 'minimum_stock' => $variant->minimum_stock, 'maximum_stock' => $variant->maximum_stock ?? '',
            'is_reception_usable' => (string) (int) $product->is_reception_usable, 'is_color_bar_usable' => (string) (int) $product->is_color_bar_usable,
            'is_color_bar_supply' => (string) (int) $product->isColorBarSupply(), 'status' => $product->status, 'control' => '',
        ];
    }

    /** @return array{product: array<string, mixed>, variant: array<string, mixed>} */
    private function snapshot(ProductVariant $variant): array
    {
        return ['product' => $variant->product->only(self::ProductFields), 'variant' => $variant->only(self::VariantFields)];
    }

    private function signature(array $snapshot): string
    {
        return hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
