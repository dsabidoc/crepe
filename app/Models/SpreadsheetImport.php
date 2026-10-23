<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpreadsheetImport extends Model
{
    protected $fillable = ['token', 'user_id', 'module', 'filename', 'format', 'mode', 'location_code', 'status', 'preview', 'reason', 'applied_at', 'total_count', 'change_count', 'error_count'];

    protected function casts(): array
    {
        return ['preview' => 'array', 'applied_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
