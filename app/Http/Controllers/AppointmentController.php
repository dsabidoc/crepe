<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\SalonService;
use App\Services\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    public function create(Request $request): View
    {
        return view('appointments.create', [
            'customers' => Customer::query()->where('status', 'active')->orderBy('first_name')->get(),
            'employees' => Employee::query()->where('is_bookable', true)->where('status', 'active')->orderBy('first_name')->get(),
            'services' => SalonService::query()->with('category')->where('status', 'active')->orderBy('service_category_id')->orderBy('name')->get(),
            'date' => (string) $request->input('date', now()->toDateString()),
            'time' => (string) $request->input('time', '10:00'),
            'selectedCustomerId' => $request->integer('customer_id'),
            'selectedEmployeeId' => $request->integer('employee_id'),
        ]);
    }

    public function availability(Request $request, AppointmentService $appointments): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'], 'duration_minutes' => ['required', 'integer', 'min:15', 'max:720'],
            'employee_ids' => ['required', 'array', 'min:1', 'max:2'], 'employee_ids.*' => ['integer', 'distinct', Rule::exists('employees', 'id')],
        ]);

        return response()->json(['available' => $appointments->availableSlots($data['date'], $data['duration_minutes'], $data['employee_ids'])]);
    }

    public function store(Request $request, AppointmentService $appointments): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:200'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'employee_ids' => ['nullable', 'array', 'min:1', 'max:2'], 'employee_ids.*' => ['integer', 'distinct', Rule::exists('employees', 'id')],
            'primary_employee_id' => ['nullable', Rule::exists('employees', 'id')],
            'date' => ['required', 'date'], 'time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:720'],
            'service_ids' => ['required', 'array', 'min:1'], 'service_ids.*' => ['integer', Rule::exists('salon_services', 'id')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $appointment = DB::transaction(function () use ($data, $request, $appointments): \App\Models\Appointment {
            if (! ($data['customer_id'] ?? null)) {
                $name = trim((string) $data['customer_name']);
                $parts = preg_split('/\s+/', $name, 2) ?: [$name];
                $customer = Customer::create([
                    'first_name' => $parts[0], 'last_name' => $parts[1] ?? '',
                    'phone' => $data['customer_phone'] ?? null, 'status' => 'active',
                ]);
                $data['customer_id'] = $customer->id;
            }
            $employeeIds = $data['employee_ids'] ?? array_values(array_filter([$data['primary_employee_id'] ?? null]));
            if ($employeeIds === []) {
                throw ValidationException::withMessages(['employee_ids' => 'Selecciona al menos una estilista.']);
            }
            $data['primary_employee_id'] = (int) $employeeIds[0];
            $data['secondary_employee_id'] = isset($employeeIds[1]) ? (int) $employeeIds[1] : null;

            return $appointments->create($data, $request->user()->id);
        });

        return redirect()->route('agenda.index', ['date' => $data['date']])->with('success', 'Cita y ticket creados correctamente.');
    }

    public function destroy(Appointment $appointment): RedirectResponse
    {
        if (in_array($appointment->status, ['cancelled', 'completed'], true)) {
            return back()->with('success', 'La cita ya no está activa.');
        }
        if ($appointment->ticket?->payments()->where('status', 'registered')->exists()) {
            return back()->withErrors(['appointment' => 'No puedes cancelar una cita con pagos registrados; registra primero el ajuste o devolución correspondiente.']);
        }

        $appointment->update(['status' => 'cancelled']);
        $appointment->ticket?->update(['status' => 'cancelled']);

        return redirect()->route('agenda.index', ['date' => $appointment->starts_at->toDateString()])
            ->with('success', 'Cita cancelada. El registro permanece disponible para auditoría.');
    }
}
