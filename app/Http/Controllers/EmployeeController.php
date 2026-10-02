<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\JobPosition;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search'));
        $status = $request->string('status')->toString();
        $availability = $request->string('availability')->toString();
        $access = $request->string('access')->toString();
        $allEmployees = Employee::query()->get(['id', 'position', 'is_bookable', 'status']);
        $employees = Employee::query()->with('user')
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('position', 'like', "%{$search}%")))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('status', $status))
            ->when(in_array($availability, ['bookable', 'internal'], true), fn ($query) => $query->where('is_bookable', $availability === 'bookable'))
            ->when(in_array($access, ['enabled', 'disabled'], true), fn ($query) => $query->whereHas('user', fn ($users) => $users->where('is_active', $access === 'enabled')))
            ->orderBy('position')->orderBy('first_name')->paginate(12)->withQueryString();

        return view('employees.index', [
            'employees' => $employees,
            'ownerCount' => $allEmployees->where('position', 'Dueña')->count(),
            'bookableCount' => $allEmployees->where('is_bookable', true)->where('status', 'active')->count(),
            'cashierCount' => $allEmployees->where('position', 'Cajera')->where('status', 'active')->count(),
            'access' => $access,
            'availability' => $availability,
            'search' => $search,
            'status' => $status,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('employees.form', [
            'employee' => new Employee,
            'roles' => $this->systemRoles(),
            'jobPositions' => $this->jobPositions(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        DB::transaction(fn (): Employee => $this->saveEmployee($this->validated($request)));

        return redirect()->route('employees.index')->with('success', 'Colaboradora agregada.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Employee $employee): View
    {
        return view('employees.form', [
            'employee' => $employee->load('user.roles'),
            'roles' => $this->systemRoles(),
            'jobPositions' => $this->jobPositions(),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Employee $employee): View
    {
        return $this->show($employee);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Employee $employee): RedirectResponse
    {
        DB::transaction(fn (): Employee => $this->saveEmployee($this->validated($request, $employee), $employee));

        return redirect()->route('employees.index')->with('success', 'Colaboradora actualizada.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Employee $employee): RedirectResponse
    {
        DB::transaction(function () use ($employee): void {
            $employee->update(['status' => 'inactive', 'is_bookable' => false]);
            $employee->user?->update(['is_active' => false]);
        });

        return redirect()->route('employees.index')->with('success', 'Colaboradora retirada del equipo. Se conserva su historial.');
    }

    private function validated(Request $request, ?Employee $employee = null): array
    {
        $systemAccessEnabled = $request->boolean('system_access_enabled');
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:32'],
            'position' => ['required', 'string', 'max:100', Rule::exists('job_positions', 'name')->where('is_active', true)], 'hired_at' => ['nullable', 'date'],
            'salary' => ['nullable', 'numeric', 'min:0'], 'salary_type' => ['nullable', 'string', 'max:32'],
            'commission_rate' => ['nullable', 'numeric', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_bookable' => ['nullable', 'boolean'], 'status' => ['required', 'in:active,inactive'],
            'system_access_enabled' => ['nullable', 'boolean'],
            'system_email' => [Rule::requiredIf($systemAccessEnabled), 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($employee?->user_id)],
            'system_role' => [Rule::requiredIf($systemAccessEnabled), 'nullable', Rule::in($this->systemRoles())],
            'system_password' => [Rule::requiredIf($systemAccessEnabled && $employee?->user_id === null), 'nullable', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);
        $data['is_bookable'] = $request->boolean('is_bookable');
        $data['system_access_enabled'] = $systemAccessEnabled;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveEmployee(array $data, ?Employee $employee = null): Employee
    {
        $employeeData = Arr::except($data, ['system_access_enabled', 'system_email', 'system_role', 'system_password', 'system_password_confirmation']);
        $employee ??= new Employee;
        $employee->fill($employeeData);

        $systemAccessEnabled = $data['system_access_enabled'] && $employee->status === 'active';
        if ($systemAccessEnabled) {
            $user = $employee->user ?? new User;
            $user->fill([
                'name' => $employee->full_name,
                'email' => $data['system_email'],
                'is_active' => true,
            ]);
            if (! empty($data['system_password'])) {
                $user->password = $data['system_password'];
            }
            $user->save();
            $user->syncRoles([$data['system_role']]);
            $employee->user()->associate($user);
        } elseif ($employee->user !== null) {
            $employee->user->update(['is_active' => false]);
        }

        $employee->save();

        return $employee;
    }

    /**
     * @return list<string>
     */
    private function systemRoles(): array
    {
        return Role::query()
            ->whereIn('name', ['Administrador', 'Recepción', 'Color Bar', 'Almacén'])
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * @return Collection<int, JobPosition>
     */
    private function jobPositions(): Collection
    {
        return JobPosition::query()->active()->orderBy('sort_order')->orderBy('name')->get();
    }
}
