<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $fillable = ['product_id', 'name', 'sku', 'base_unit', 'content_quantity', 'cost', 'sale_price', 'minimum_stock', 'maximum_stock'];

    protected function casts(): array
    {
        return ['content_quantity' => 'decimal:3', 'cost' => 'decimal:4', 'sale_price' => 'decimal:2', 'minimum_stock' => 'decimal:3', 'maximum_stock' => 'decimal:3'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function balances()
    {
        return $this->hasMany(InventoryBalance::class);
    }
}
