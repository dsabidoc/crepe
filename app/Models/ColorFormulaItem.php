<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ColorFormulaItem extends Model
{
    protected $fillable = ['color_formula_id', 'product_variant_id', 'quantity', 'unit', 'notes', 'cost_snapshot', 'sale_price'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'cost_snapshot' => 'decimal:4', 'sale_price' => 'decimal:2'];
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
