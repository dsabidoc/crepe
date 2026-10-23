<?php

namespace App\Http\Controllers;

use App\Models\SpreadsheetImport;
use App\Services\SpreadsheetFile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SpreadsheetImportController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['module' => ['nullable', Rule::in(['products', 'inventory'])], 'status' => ['nullable', Rule::in(['reviewed', 'invalid', 'applied'])]]);
        $imports = SpreadsheetImport::query()->with('user')
            ->select(['id', 'user_id', 'module', 'filename', 'format', 'mode', 'location_code', 'status', 'change_count', 'error_count', 'created_at'])
            ->when($data['module'] ?? null, fn ($query, $module) => $query->where('module', $module))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('imports.index', compact('imports'));
    }

    public function show(SpreadsheetImport $spreadsheetImport): View
    {
        $spreadsheetImport->load('user');

        return view('imports.show', ['import' => $spreadsheetImport]);
    }

    public function result(Request $request, SpreadsheetImport $spreadsheetImport, SpreadsheetFile $files): Response
    {
        $rows = [['Fila', 'Acción', 'Producto', 'SKU', 'Campo', 'Anterior', 'Nuevo', 'Detalle']];
        foreach ($spreadsheetImport->preview['changes'] ?? [] as $change) {
            foreach ($change['diff'] ?? [['label' => 'Existencia', 'before' => $change['before'] ?? '', 'after' => $change['after'] ?? '']] as $diff) {
                if (($diff['field'] ?? '') === 'cost' && ! $request->user()->can('products.costs.view')) {
                    continue;
                }
                $rows[] = [$change['row'], ['added' => 'Agregar producto', 'variant_added' => 'Agregar presentación', 'updated' => 'Actualizar'][$change['action'] ?? 'updated'], $change['name'], $change['sku'] ?? '', $diff['label'], $diff['before'], $diff['after'], $spreadsheetImport->status === 'applied' ? 'Aplicado' : 'Sin aplicar'];
            }
        }
        foreach ($spreadsheetImport->preview['errors'] ?? [] as $error) {
            $rows[] = ['', 'Error', '', '', '', '', '', $error];
        }
        foreach ($spreadsheetImport->preview['warnings'] ?? [] as $warning) {
            $rows[] = ['', 'Aviso', '', '', '', '', '', $warning];
        }

        return $files->download($files->writeCsv($rows), 'resultado-importacion-'.$spreadsheetImport->id, 'csv');
    }
}
