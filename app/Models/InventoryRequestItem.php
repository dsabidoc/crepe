<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryRequestItem extends Model
{
    protected $fillable = ['inventory_request_id', 'product_variant_id', 'requested_quantity', 'requested_units', 'delivered_quantity', 'delivered_units', 'unit', 'note'];

    protected function casts(): array
    {
        return ['requested_quantity' => 'decimal:3', 'requested_units' => 'integer', 'delivered_quantity' => 'decimal:3', 'delivered_units' => 'integer'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(InventoryRequest::class, 'inventory_request_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
