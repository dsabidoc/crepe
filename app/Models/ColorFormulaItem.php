<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ColorFormulaItem extends Model
{
    protected $fillable = ['color_formula_id', 'product_variant_id', 'quantity', 'unit', 'notes', 'cost_snapshot', 'sale_price', 'charged_unit_price'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'cost_snapshot' => 'decimal:4', 'sale_price' => 'decimal:2', 'charged_unit_price' => 'decimal:2'];
    }

    public function formula(): BelongsTo
    {
        return $this->belongsTo(ColorFormula::class, 'color_formula_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
