<?php

namespace App\Models;

use Database\Factories\ProductCommissionRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductCommissionRule extends Model
{
    /** @use HasFactory<ProductCommissionRuleFactory> */
    use HasFactory;

    protected $fillable = ['minimum_sales', 'maximum_sales', 'commission_rate', 'positions', 'is_active'];

    protected function casts(): array
    {
        return ['minimum_sales' => 'integer', 'maximum_sales' => 'integer', 'commission_rate' => 'decimal:2', 'positions' => 'array', 'is_active' => 'boolean'];
    }
}
