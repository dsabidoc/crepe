<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryAdjustmentBatch extends Model
{
    protected $fillable = ['request_key', 'created_by', 'payload_hash', 'reason', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }
}
