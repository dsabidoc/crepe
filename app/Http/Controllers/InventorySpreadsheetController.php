<?php

namespace App\Http\Controllers;

use App\Models\SpreadsheetImport;
use App\Services\InventorySpreadsheetService;
use App\Services\SpreadsheetFile;
use App\Services\SpreadsheetImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InventorySpreadsheetController extends Controller
{
    public function download(Request $request, InventorySpreadsheetService $spreadsheets, SpreadsheetFile $files): Response
    {
        $data = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'csv'])], 'location_code' => ['sometimes', Rule::in(['ALM', 'REC', 'CB'])]]);
        $format = $data['format'] ?? 'xlsx';
        $location = $data['location_code'] ?? 'ALM';

        return $files->download($spreadsheets->export($format, $location, $request->user()->can('products.costs.view')), 'inventario-'.['ALM' => 'almacen', 'REC' => 'recepcion', 'CB' => 'color-bar'][$location].'-'.now()->format('Y-m-d-His'), $format);
    }

    public function create(Request $request): View
    {
        $preview = $request->session()->get('inventory_import');
        if (($preview['user_id'] ?? null) !== $request->user()->id) {
            $preview = null;
        }
        $data = $request->validate(['location_code' => ['sometimes', Rule::in(['ALM', 'REC', 'CB'])]]);
        $locationCode = $preview['location_code'] ?? $data['location_code'] ?? 'ALM';
        $import = $preview ? SpreadsheetImport::query()->where('token', $preview['token'])->where('user_id', $request->user()->id)->first() : null;

        return view('inventory.import', compact('preview', 'locationCode', 'import'));
    }

    public function preview(Request $request, InventorySpreadsheetService $spreadsheets, SpreadsheetImportService $imports): RedirectResponse
    {
        $request->session()->forget('inventory_import');
        $data = $request->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'extensions:xlsx,csv', 'max:5120'], 'location_code' => ['sometimes', Rule::in(['ALM', 'REC', 'CB'])]], [
            'file.required' => 'Selecciona el Excel o CSV que editaste.',
            'file.mimes' => 'Selecciona un archivo Excel .xlsx o CSV válido.',
            'file.extensions' => 'Guarda el archivo en formato .xlsx o .csv.',
            'file.max' => 'El archivo no debe pesar más de 5 MB.',
        ]);
        $file = $request->file('file');
        $locationCode = $data['location_code'] ?? 'ALM';
        $import = $imports->review('inventory', $file, $request->user()->id, 'count', $locationCode, fn (): array => $spreadsheets->preview($file->getPathname(), strtolower($file->getClientOriginalExtension()), $locationCode, $request->user()->can('products.costs.view'), $request->user()->can('products.prices.manage'), $request->user()->can('products.manage')));
        $request->session()->put('inventory_import', $import->preview + [
            'user_id' => $request->user()->id, 'token' => $import->token, 'location_code' => $locationCode,
        ]);
        if ($import->status === 'invalid') {
            throw ValidationException::withMessages(['file' => array_slice($import->preview['errors'], 0, 50)]);
        }

        return redirect()->route('inventory.import.create');
    }

    public function store(Request $request, InventorySpreadsheetService $spreadsheets, SpreadsheetImportService $imports): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:1000'],
        ], ['reason.required' => 'Escribe el motivo del ajuste.', 'reason.max' => 'El motivo no debe superar los 1,000 caracteres.']);
        $preview = $request->session()->get('inventory_import');
        if (($preview['user_id'] ?? null) !== $request->user()->id || ! hash_equals($preview['token'] ?? '', $data['token']) || empty($preview['changes'])) {
            throw ValidationException::withMessages(['file' => 'La revisión ya no está disponible. Sube el Excel y revisa los cambios nuevamente.']);
        }
        $count = $imports->apply($data['token'], $request->user()->id, 'inventory', $data['reason'], fn (SpreadsheetImport $import): int => $spreadsheets->apply($import->preview['changes'], $request->user()->id, $data['reason'], $import->location_code, $import->format, $request->user()->can('products.costs.view'), $request->user()->can('products.prices.manage'), $request->user()->can('products.manage')));
        $request->session()->forget('inventory_import');

        return redirect()->route('inventory.index', ['location' => $preview['location_code']])->with('success', "Se actualizaron {$count} registros de inventario o catálogo. Los ajustes quedaron registrados en la bitácora y en el historial de importaciones.");
    }
}
