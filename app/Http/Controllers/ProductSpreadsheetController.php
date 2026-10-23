<?php

namespace App\Http\Controllers;

use App\Models\SpreadsheetImport;
use App\Services\ProductSpreadsheetService;
use App\Services\SpreadsheetFile;
use App\Services\SpreadsheetImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductSpreadsheetController extends Controller
{
    public function download(Request $request, ProductSpreadsheetService $products, SpreadsheetFile $files): Response
    {
        $data = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'csv'])], 'template' => ['sometimes', 'boolean']]);
        $format = $data['format'] ?? 'xlsx';

        return $files->download($products->export($format, $request->boolean('template'), $request->user()->can('products.costs.view')), ($request->boolean('template') ? 'plantilla-productos-' : 'productos-').now()->format('Y-m-d-His'), $format);
    }

    public function create(Request $request): View
    {
        $import = SpreadsheetImport::query()->where('token', $request->session()->get('product_import_token'))->where('user_id', $request->user()->id)->where('module', 'products')->first();

        return view('products.import', compact('import'));
    }

    public function preview(Request $request, ProductSpreadsheetService $products, SpreadsheetImportService $imports): RedirectResponse
    {
        $request->session()->forget('product_import_token');
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'extensions:xlsx,csv', 'max:5120'],
            'mode' => ['required', Rule::in(['add', 'update', 'upsert'])],
        ], ['file.required' => 'Selecciona un Excel o CSV.', 'file.mimes' => 'Selecciona un Excel .xlsx o CSV válido.', 'file.extensions' => 'Usa un archivo .xlsx o .csv.', 'file.max' => 'El archivo no debe pesar más de 5 MB.']);
        $file = $request->file('file');
        $import = $imports->review('products', $file, $request->user()->id, $data['mode'], null, fn (): array => $products->preview($file->getPathname(), strtolower($file->getClientOriginalExtension()), $data['mode'], $request->user()->can('products.costs.view'), $request->user()->can('products.prices.manage')));
        $request->session()->put('product_import_token', $import->token);

        return redirect()->route('products.import.create');
    }

    public function store(Request $request, ProductSpreadsheetService $products, SpreadsheetImportService $imports): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Escribe el motivo de la importación.']);
        $count = $imports->apply($data['token'], $request->user()->id, 'products', $data['reason'], function (SpreadsheetImport $import) use ($request, $products, $data): int {
            foreach ($import->preview['changes'] as $change) {
                if ((! $request->user()->can('products.costs.view') && (! $change['variant_id'] || collect($change['diff'])->contains('field', 'cost'))) || (! $request->user()->can('products.prices.manage') && (! $change['variant_id'] || collect($change['diff'])->contains(fn (array $diff): bool => in_array($diff['field'], ['sale_price', 'color_bar_sale_price', 'color_bar_pricing_type'], true))))) {
                    throw ValidationException::withMessages(['file' => 'Tus permisos de costos o precios cambiaron. Revisa el archivo nuevamente.']);
                }
            }

            return $products->apply($import->preview['changes'], $request->user()->id, $data['reason'], $import->id);
        });
        $request->session()->forget('product_import_token');

        return redirect()->route('products.index')->with('success', "Se aplicaron {$count} altas o actualizaciones. Consulta el detalle en Historial de importaciones.");
    }
}
