<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryRequestReversal extends Model
{
    protected $fillable = ['inventory_request_id', 'request_key', 'created_by', 'reason', 'movements'];

    protected function casts(): array
    {
        return ['movements' => 'array'];
    }
}
