<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentService extends Model
{
    protected $fillable = ['appointment_id', 'salon_service_id', 'employee_id', 'name_snapshot', 'estimated_price', 'estimated_duration_minutes'];

    protected function casts(): array
    {
        return ['estimated_price' => 'decimal:2'];
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function service()
    {
        return $this->belongsTo(SalonService::class, 'salon_service_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
