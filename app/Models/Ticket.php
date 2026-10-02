<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $fillable = ['code', 'customer_id', 'appointment_id', 'ticket_type', 'status', 'estimated_total', 'listed_total', 'discount_total', 'charged_total', 'lock_version', 'opened_at', 'paid_at'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'paid_at' => 'datetime',
            'estimated_total' => 'decimal:2',
            'listed_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'charged_total' => 'decimal:2',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function items()
    {
        return $this->hasMany(TicketItem::class);
    }

    public function adjustments()
    {
        return $this->hasMany(TicketAdjustment::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function getSubtotalAttribute(): float
    {
        return (float) $this->listed_total;
    }

    public function getAdjustmentTotalAttribute(): float
    {
        return (float) $this->charged_total - $this->listed_total;
    }

    public function getTotalAttribute(): float
    {
        return (float) $this->charged_total;
    }

    public function getPaidTotalAttribute(): float
    {
        return (float) $this->payments()->where('status', 'registered')->sum('amount');
    }

    public function getBalanceAttribute(): float
    {
        return $this->total - $this->paid_total;
    }
}
