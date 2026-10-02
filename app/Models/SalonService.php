<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalonService extends Model
{
    use HasFactory;

    protected $fillable = ['service_category_id', 'name', 'description', 'cover_image_path', 'base_price', 'base_cost', 'estimated_duration_minutes', 'commission_rate', 'commission_type', 'commission_fixed_amount', 'price_type', 'requires_color_bar', 'status'];

    protected function casts(): array
    {
        return ['base_price' => 'decimal:2', 'base_cost' => 'decimal:2', 'commission_rate' => 'decimal:2', 'commission_fixed_amount' => 'decimal:2', 'requires_color_bar' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SalonServicePrice::class)->orderBy('tier');
    }
}
