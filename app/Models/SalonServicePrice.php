<?php

namespace App\Models;

use Database\Factories\SalonServicePriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalonServicePrice extends Model
{
    /** @use HasFactory<SalonServicePriceFactory> */
    use HasFactory;

    protected $fillable = ['salon_service_id', 'tier', 'cost', 'sale_price'];

    protected $appends = ['profit'];

    protected function casts(): array
    {
        return ['cost' => 'decimal:2', 'sale_price' => 'decimal:2'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(SalonService::class, 'salon_service_id');
    }

    public function getProfitAttribute(): float
    {
        return (float) $this->sale_price - (float) ($this->cost ?? 0);
    }
}
