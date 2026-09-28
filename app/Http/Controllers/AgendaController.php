<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AgendaController extends Controller
{
    public function __invoke(Request $request): View
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'view' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'employees' => ['nullable', 'array'],
            'employees.*' => ['integer', 'distinct', Rule::exists('employees', 'id')],
        ]);

        $view = $data['view'] ?? 'day';
        $date = isset($data['date']) ? Carbon::parse($data['date'])->startOfDay() : now()->startOfDay();
        [$periodStart, $periodEnd] = match ($view) {
            'week' => [$date->copy()->startOfWeek(Carbon::MONDAY), $date->copy()->endOfWeek(Carbon::SUNDAY)],
            'month' => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()],
            default => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
        };

        $filterEmployees = Employee::query()
            ->where('is_bookable', true)
            ->where('status', 'active')
            ->orderBy('first_name')
            ->get();
        $employees = $filterEmployees;
        $selectedEmployeeIds = collect($data['employees'] ?? [])
            ->map(fn (int|string $employeeId): int => (int) $employeeId)
            ->intersect($filterEmployees->modelKeys())
            ->values()
            ->all();

        if ($selectedEmployeeIds !== []) {
            $employees = $employees->whereIn('id', $selectedEmployeeIds)->values();
        }

        $appointments = Appointment::query()
            ->with(['customer', 'employee', 'services', 'ticket'])
            ->whereIn('primary_employee_id', $employees->modelKeys())
            ->whereBetween('starts_at', [$periodStart, $periodEnd])
            ->orderBy('starts_at')
            ->get();
        $appointmentsByEmployee = $appointments->groupBy('primary_employee_id');
        $appointmentsByDate = $appointments->groupBy(fn (Appointment $appointment): string => $appointment->starts_at->toDateString());
        $weekDays = collect(range(0, 6))->map(fn (int $day): Carbon => $periodStart->copy()->addDays($day));
        $monthDays = collect();

        if ($view === 'month') {
            $monthGridStart = $periodStart->copy()->startOfWeek(Carbon::MONDAY);
            $monthGridEnd = $periodEnd->copy()->endOfWeek(Carbon::SUNDAY);

            for ($day = $monthGridStart->copy(); $day->lte($monthGridEnd); $day->addDay()) {
                $monthDays->push($day->copy());
            }
        }

        return view('agenda.index', compact(
            'appointmentsByDate',
            'appointmentsByEmployee',
            'date',
            'employees',
            'filterEmployees',
            'monthDays',
            'periodEnd',
            'periodStart',
            'selectedEmployeeIds',
            'view',
            'weekDays',
        ));
    }
}
