<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    protected $fillable = [
        'name', 'code', 'method', 'type', 'value_type', 'value', 'minimum_amount', 'buy_quantity',
        'reward_quantity', 'target', 'reward', 'usage_limit', 'usage_count', 'one_per_customer',
        'starts_at', 'ends_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2', 'minimum_amount' => 'decimal:2', 'target' => 'array', 'reward' => 'array',
            'one_per_customer' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime',
        ];
    }

    public function isAvailable(): bool
    {
        return $this->status === 'active'
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture())
            && ($this->usage_limit === null || $this->usage_count < $this->usage_limit);
    }
}
