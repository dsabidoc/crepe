<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionEntry extends Model
{
    protected $fillable = ['ticket_id', 'employee_id', 'ticket_item_id', 'type', 'base_amount', 'rate_snapshot', 'amount', 'status'];

    protected function casts(): array
    {
        return ['base_amount' => 'decimal:2', 'rate_snapshot' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
