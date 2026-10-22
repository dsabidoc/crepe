<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    protected $fillable = ['name', 'color', 'color_bar_multiplier'];

    protected function casts(): array
    {
        return ['color_bar_multiplier' => 'decimal:4'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
