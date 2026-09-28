<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    protected $fillable = ['uuid', 'product_variant_id', 'inventory_location_id', 'type', 'quantity', 'before_quantity', 'after_quantity', 'unit_cost', 'reference_type', 'reference_id', 'reverses_movement_id', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'before_quantity' => 'decimal:3', 'after_quantity' => 'decimal:3', 'unit_cost' => 'decimal:4'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
