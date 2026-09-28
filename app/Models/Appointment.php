<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $fillable = ['customer_id', 'primary_employee_id', 'secondary_employee_id', 'starts_at', 'ends_at', 'status', 'estimated_total', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'estimated_total' => 'decimal:2'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'primary_employee_id');
    }

    public function secondaryEmployee()
    {
        return $this->belongsTo(Employee::class, 'secondary_employee_id');
    }

    public function services()
    {
        return $this->hasMany(AppointmentService::class);
    }

    public function ticket()
    {
        return $this->hasOne(Ticket::class);
    }
}
