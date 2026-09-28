<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryRequestItem extends Model
{
    protected $fillable = ['inventory_request_id', 'product_variant_id', 'requested_quantity', 'delivered_quantity', 'unit', 'note'];

    protected function casts(): array
    {
        return ['requested_quantity' => 'decimal:3', 'delivered_quantity' => 'decimal:3'];
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
