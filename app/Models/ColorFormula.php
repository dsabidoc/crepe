<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ColorFormula extends Model
{
    protected $fillable = ['ticket_id', 'ticket_item_id', 'service_name_snapshot', 'color_bar_included', 'customer_id', 'inventory_location_id', 'supersedes_color_formula_id', 'status', 'notes', 'created_by', 'corrected_by', 'corrected_at'];

    protected function casts(): array
    {
        return ['corrected_at' => 'datetime', 'color_bar_included' => 'boolean'];
    }

    public function serviceItem(): BelongsTo
    {
        return $this->belongsTo(TicketItem::class, 'ticket_item_id');
    }

    public function items()
    {
        return $this->hasMany(ColorFormulaItem::class);
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function supersedes()
    {
        return $this->belongsTo(self::class, 'supersedes_color_formula_id');
    }
}
