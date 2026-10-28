<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceptionPriceController extends Controller
{
    public function update(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->can('products.reception-prices.manage') && $product->is_reception_usable, 403);
        $data = $request->validate(['sale_price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'], 'previous_price' => ['required', 'numeric']]);
        DB::transaction(function () use ($request, $product, $data): void {
            $variant = $product->variants()->lockForUpdate()->firstOrFail();
            if ((float) $variant->sale_price !== (float) $data['previous_price']) {
                throw ValidationException::withMessages(['sale_price' => 'El precio cambió mientras editabas. Actualiza la página antes de guardar.']);
            }
            if ((float) $variant->sale_price === (float) $data['sale_price']) {
                return;
            }
            $before = $variant->sale_price;
            $variant->update(['sale_price' => $data['sale_price']]);
            AuditLog::query()->create(['user_id' => $request->user()->id, 'action' => 'product.reception_price.updated', 'subject_type' => Product::class, 'subject_id' => $product->id, 'before' => ['sale_price' => $before], 'after' => ['sale_price' => $variant->sale_price], 'reason' => 'Cambio de precio de Recepción']);
        });

        return back()->with('success', 'Precio de Recepción actualizado.');
    }
}
