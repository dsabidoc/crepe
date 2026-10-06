<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\AttendanceIncident;
use App\Models\BusinessHour;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\PayrollRun;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function register(string $pin): EmployeeAttendance
    {
        $employee = Employee::query()
            ->where('status', 'active')
            ->where('requires_check_in', true)
            ->where('check_pin', $pin)
            ->first();

        if ($employee === null) {
            throw ValidationException::withMessages(['pin' => 'El código no corresponde a una colaboradora activa.']);
        }

        return DB::transaction(function () use ($employee): EmployeeAttendance {
            $now = now();
            $attendance = EmployeeAttendance::query()
                ->whereBelongsTo($employee)
                ->whereDate('work_date', $now->toDateString())
                ->lockForUpdate()
                ->first();

            if ($attendance === null) {
                [$startsAt, $endsAt] = $this->scheduleFor($now);
                $lateMinutes = max(0, $startsAt->diffInMinutes($now, false) - $this->toleranceMinutes());
                $attendance = EmployeeAttendance::query()->create([
                    'employee_id' => $employee->id,
                    'work_date' => $now->toDateString(),
                    'checked_in_at' => $now,
                    'scheduled_starts_at' => $startsAt->format('H:i:s'),
                    'scheduled_ends_at' => $endsAt->format('H:i:s'),
                    'late_minutes' => $lateMinutes,
                    'late_penalty_amount' => $lateMinutes > 0 ? $this->tardinessPenalty() : 0,
                ]);

                return $attendance;
            }

            if ($attendance->checked_out_at === null) {
                $attendance->update(['checked_out_at' => $now]);

                return $attendance->fresh();
            }

            throw ValidationException::withMessages(['pin' => 'La entrada y salida de hoy ya fueron registradas para esta colaboradora.']);
        });
    }

    public function syncIncidents(Carbon $from, Carbon $to): void
    {
        $trackingStartsOn = Carbon::parse(AppSetting::value('attendance.tracking_starts_on', now()->toDateString()))->startOfDay();
        $from = $from->copy()->startOfDay()->max($trackingStartsOn);
        $to = $to->copy()->startOfDay()->min(now()->startOfDay());
        if ($from->greaterThan($to)) {
            return;
        }

        $businessHours = BusinessHour::query()->get()->keyBy('day_of_week');
        $employees = Employee::query()
            ->where('status', 'active')
            ->where('requires_check_in', true)
            ->with('schedules')
            ->orderBy('id')
            ->get();

        foreach ($employees as $employee) {
            for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date->addDay()) {
                if (! $this->isScheduledDate($employee, $date, $businessHours)) {
                    continue;
                }

                $attendance = EmployeeAttendance::query()
                    ->whereBelongsTo($employee)
                    ->whereDate('work_date', $date->toDateString())
                    ->first();
                $type = $attendance?->checked_in_at === null ? 'absence' : ($attendance->checked_out_at === null ? 'missing_check_out' : null);

                if ($type === null) {
                    AttendanceIncident::query()
                        ->whereBelongsTo($employee)
                        ->whereDate('work_date', $date->toDateString())
                        ->where('status', 'pending')
                        ->delete();

                    continue;
                }

                $incident = AttendanceIncident::query()
                    ->whereBelongsTo($employee)
                    ->whereDate('work_date', $date->toDateString())
                    ->first();

                if ($incident === null) {
                    AttendanceIncident::query()->create([
                        'employee_id' => $employee->id,
                        'work_date' => $date->toDateString(),
                        'type' => $type,
                        'status' => 'pending',
                    ]);
                }
            }
        }
    }

    public function ensurePayrollIncidentsResolved(Carbon $from, Carbon $to): void
    {
        $this->syncIncidents($from, $to);
        $pending = AttendanceIncident::query()
            ->with('employee:id,first_name,last_name')
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->where('status', 'pending')
            ->orderBy('work_date')
            ->get();

        if ($pending->isNotEmpty()) {
            $names = $pending->take(3)->map(fn (AttendanceIncident $incident): string => $incident->employee->full_name.' · '.$incident->work_date->format('d/m'))->join(', ');
            throw ValidationException::withMessages([
                'period_starts_on' => 'Atiende primero las incidencias de asistencia del periodo'.($names !== '' ? ': '.$names.'.' : '.'),
            ]);
        }
    }

    public function absenceDeduction(Employee $employee, PayrollRun $payrollRun, float $basePay): float
    {
        $incidents = AttendanceIncident::query()
            ->whereBelongsTo($employee)
            ->whereBetween('work_date', [$payrollRun->period_starts_on->toDateString(), $payrollRun->period_ends_on->toDateString()])
            ->where('status', 'resolved')
            ->where('resolution', 'day_discount')
            ->whereNull('payroll_run_id')
            ->lockForUpdate()
            ->get();
        if ($incidents->isEmpty()) {
            return 0;
        }

        $workDays = max(1, $this->scheduledDaysFor($employee, $payrollRun->period_starts_on, $payrollRun->period_ends_on));
        $dailyAmount = round($basePay / $workDays, 2);
        $incidents->each(function (AttendanceIncident $incident) use ($dailyAmount, $payrollRun): void {
            $incident->update([
                'deduction_amount' => $dailyAmount,
                'payroll_run_id' => $payrollRun->id,
            ]);
        });

        return round($dailyAmount * $incidents->count(), 2);
    }

    public function tardinessDeduction(Employee $employee, Carbon $from, Carbon $to): float
    {
        return (float) EmployeeAttendance::query()
            ->whereBelongsTo($employee)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->where('late_minutes', '>', 0)
            ->sum('late_penalty_amount');
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function scheduleFor(Carbon $date): array
    {
        $startsAt = AppSetting::value('attendance.starts_at', '09:00');
        $endsAt = AppSetting::value('attendance.ends_at', '17:00');

        return [
            $date->copy()->setTimeFromTimeString($startsAt),
            $date->copy()->setTimeFromTimeString($endsAt),
        ];
    }

    /**
     * @param  Collection<int, BusinessHour>  $businessHours
     */
    private function isScheduledDate(Employee $employee, Carbon $date, Collection $businessHours): bool
    {
        if ($employee->hired_at?->greaterThan($date)) {
            return false;
        }

        $schedule = $employee->schedules->firstWhere('day_of_week', $date->dayOfWeek);
        if ($schedule !== null) {
            return $schedule->is_available;
        }

        return $businessHours->get($date->dayOfWeek)?->is_open ?? $date->dayOfWeek !== Carbon::SUNDAY;
    }

    private function scheduledDaysFor(Employee $employee, Carbon $from, Carbon $to): int
    {
        $businessHours = BusinessHour::query()->get()->keyBy('day_of_week');
        $employee->loadMissing('schedules');
        $days = 0;
        for ($date = $from->copy()->startOfDay(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            if ($this->isScheduledDate($employee, $date, $businessHours)) {
                $days++;
            }
        }

        return $days;
    }

    private function toleranceMinutes(): int
    {
        return max(0, (int) AppSetting::value('attendance.tolerance_minutes', 10));
    }

    private function tardinessPenalty(): float
    {
        return max(0, (float) AppSetting::value('attendance.tardiness_penalty', 50));
    }
}
