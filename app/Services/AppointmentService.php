<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\EmployeeTimeBlock;
use App\Models\SalonService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    /** @return array<int, string> */
    public function availableSlots(string $date, int $duration, array $employeeIds): array
    {
        if (Carbon::parse($date)->startOfDay()->isBefore(now()->startOfDay())) {
            return [];
        }

        $employees = Employee::query()->whereIn('id', $employeeIds)->where('is_bookable', true)->where('status', 'active')->get();
        $available = [];
        for ($minutes = 9 * 60; $minutes <= 18 * 60; $minutes += 60) {
            $startsAt = Carbon::parse($date.' '.sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60));
            $endsAt = $startsAt->copy()->addMinutes($duration);
            try {
                foreach ($employees as $employee) {
                    $this->ensureAvailability($employee, $startsAt, $endsAt);
                }
                $available[] = $startsAt->format('H:i');
            } catch (ValidationException) {
                continue;
            }
        }

        return $available;
    }

    public function create(array $data, int $actorId): Appointment
    {
        return DB::transaction(function () use ($data, $actorId): Appointment {
            $employeeIds = array_values(array_unique(array_filter([
                $data['primary_employee_id'],
                $data['secondary_employee_id'] ?? null,
            ])));
            $employees = Employee::query()->whereIn('id', $employeeIds)->where('is_bookable', true)->where('status', 'active')->lockForUpdate()->get()->keyBy('id');
            if ($employees->count() !== count($employeeIds)) {
                throw ValidationException::withMessages(['employee_ids' => 'Una o más estilistas no están disponibles.']);
            }
            $employee = $employees->get($data['primary_employee_id']);
            $services = SalonService::query()->whereIn('id', $data['service_ids'])->where('status', 'active')->lockForUpdate()->get();
            if ($services->count() !== count(array_unique($data['service_ids']))) {
                throw ValidationException::withMessages(['service_ids' => 'Uno o más servicios no están disponibles.']);
            }

            $startsAt = Carbon::parse("{$data['date']} {$data['time']}");
            $endsAt = $startsAt->copy()->addMinutes((int) $data['duration_minutes']);
            foreach ($employees as $selectedEmployee) {
                $this->ensureAvailability($selectedEmployee, $startsAt, $endsAt);
            }

            $appointment = Appointment::create([
                'customer_id' => $data['customer_id'], 'primary_employee_id' => $employee->id, 'secondary_employee_id' => $data['secondary_employee_id'] ?? null,
                'starts_at' => $startsAt, 'ends_at' => $endsAt, 'status' => 'scheduled',
                'estimated_total' => $services->sum('base_price'), 'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            foreach ($services as $service) {
                $appointment->services()->create([
                    'salon_service_id' => $service->id, 'employee_id' => $employee->id,
                    'name_snapshot' => $service->name, 'estimated_price' => $service->base_price,
                    'estimated_duration_minutes' => $service->estimated_duration_minutes,
                ]);
            }

            app(TicketService::class)->ensureForAppointment($appointment->load('services.employee', 'services.service.prices'), $actorId);

            return $appointment;
        });
    }

    public function assignSecondaryEmployee(Appointment $appointment, int $employeeId): Appointment
    {
        return DB::transaction(function () use ($appointment, $employeeId): Appointment {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            if ($appointment->primary_employee_id === $employeeId) {
                throw ValidationException::withMessages([
                    'secondary_employee_id' => 'Selecciona una estilista distinta de la responsable principal.',
                ]);
            }
            if ($appointment->secondary_employee_id !== null) {
                throw ValidationException::withMessages([
                    'secondary_employee_id' => 'Este ticket ya tiene dos estilistas responsables.',
                ]);
            }

            $employee = Employee::query()
                ->where('is_bookable', true)
                ->where('status', 'active')
                ->lockForUpdate()
                ->find($employeeId);
            if ($employee === null) {
                throw ValidationException::withMessages([
                    'secondary_employee_id' => 'La estilista seleccionada no está disponible.',
                ]);
            }

            if ($appointment->starts_at->isFuture()) {
                $this->ensureAvailability($employee, $appointment->starts_at, $appointment->ends_at, $appointment->id);
            }
            $appointment->update(['secondary_employee_id' => $employee->id]);

            return $appointment->fresh(['employee', 'secondaryEmployee']);
        });
    }

    private function ensureAvailability(Employee $employee, Carbon $startsAt, Carbon $endsAt, ?int $ignoredAppointmentId = null): void
    {
        if ($startsAt->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['time' => 'No puedes reservar una fecha u hora que ya pasó.']);
        }
        $day = $startsAt->dayOfWeek;
        $hours = BusinessHour::query()->where('day_of_week', $day)->first();
        $schedule = EmployeeSchedule::query()->where('employee_id', $employee->id)->where('day_of_week', $day)->first();
        $startTime = $startsAt->format('H:i:s');
        $endTime = $endsAt->format('H:i:s');

        if (! $hours?->is_open || $startTime < $hours->opens_at || $endTime > $hours->closes_at) {
            throw ValidationException::withMessages(['time' => 'El horario seleccionado está fuera de la disponibilidad de la estilista.']);
        }

        if ($schedule !== null && (! $schedule->is_available || $startTime < $schedule->starts_at || $endTime > $schedule->ends_at)) {
            throw ValidationException::withMessages(['time' => 'El horario seleccionado está fuera de la disponibilidad de la estilista.']);
        }

        $hasBlock = EmployeeTimeBlock::query()->where(function ($query) use ($employee) {
            $query->whereNull('employee_id')->orWhere('employee_id', $employee->id);
        })->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)->lockForUpdate()->exists();
        if ($hasBlock) {
            throw ValidationException::withMessages(['time' => 'Ese horario está bloqueado.']);
        }

        $hasConflict = Appointment::query()->where(function ($query) use ($employee): void {
            $query->where('primary_employee_id', $employee->id)->orWhere('secondary_employee_id', $employee->id);
        })
            ->when($ignoredAppointmentId, fn ($query) => $query->whereKeyNot($ignoredAppointmentId))
            ->whereNotIn('status', ['cancelled', 'no_show'])->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)->lockForUpdate()->exists();
        if ($hasConflict) {
            throw ValidationException::withMessages(['time' => 'La estilista ya tiene una cita en ese horario.']);
        }
    }
}
