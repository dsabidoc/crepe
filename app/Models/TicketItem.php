<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketItem extends Model
{
    protected $fillable = ['ticket_id', 'type', 'name_snapshot', 'quantity', 'unit', 'unit_price', 'line_total', 'cost_snapshot', 'status', 'metadata', 'added_by'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'cost_snapshot' => 'decimal:4', 'metadata' => 'array'];
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }
}
