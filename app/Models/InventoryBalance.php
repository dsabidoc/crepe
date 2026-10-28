<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryBalance extends Model
{
    protected $fillable = ['inventory_location_id', 'product_variant_id', 'available_quantity', 'minimum_stock', 'maximum_stock', 'limits_configured'];

    protected function casts(): array
    {
        return ['available_quantity' => 'decimal:3', 'minimum_stock' => 'decimal:3', 'maximum_stock' => 'decimal:3', 'limits_configured' => 'boolean'];
    }

    public function location()
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
