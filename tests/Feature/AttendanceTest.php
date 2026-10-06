<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AttendanceIncident;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_pin_registers_a_late_arrival_and_then_the_exit(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $employee = Employee::query()->where('email', 'ana@crepe.mx')->firstOrFail();
        $employee->update(['requires_check_in' => true, 'check_pin' => '1234']);
        AppSetting::put('attendance.tracking_starts_on', '2026-10-05');
        $this->travelTo(Carbon::parse('2026-10-05 09:11:00'));

        $this->actingAs($administrator)
            ->post(route('attendance.register'), ['pin' => '1234'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $attendance = EmployeeAttendance::query()->whereBelongsTo($employee)->firstOrFail();
        $this->assertSame(1, $attendance->late_minutes);
        $this->assertSame('50.00', $attendance->late_penalty_amount);
        $this->assertNotNull($attendance->checked_in_at);
        $this->assertNull($attendance->checked_out_at);

        $this->travelTo(Carbon::parse('2026-10-05 17:05:00'));
        $this->actingAs($administrator)
            ->post(route('attendance.register'), ['pin' => '1234'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($attendance->fresh()->checked_out_at);
    }

    public function test_payroll_is_blocked_until_a_missing_attendance_is_resolved_and_applies_the_daily_deduction(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        Employee::query()->update(['requires_check_in' => false]);
        $employee = Employee::query()->where('email', 'ana@crepe.mx')->firstOrFail();
        $employee->update(['requires_check_in' => true, 'check_pin' => '1234', 'salary' => 600, 'salary_type' => 'weekly']);
        AppSetting::put('attendance.tracking_starts_on', '2026-10-05');
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));

        $this->actingAs($administrator)
            ->post(route('payroll.store'), ['period_starts_on' => '2026-10-05', 'period_ends_on' => '2026-10-11'])
            ->assertRedirect()
            ->assertSessionHasErrors('period_starts_on');
        $this->assertSame(0, PayrollRun::query()->count());

        $incident = AttendanceIncident::query()->whereBelongsTo($employee)->firstOrFail();
        $this->actingAs($administrator)
            ->put(route('attendance.incidents.resolve', $incident), ['resolution' => 'day_discount', 'reason' => 'Falta sin justificar'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($administrator)
            ->post(route('payroll.store'), ['period_starts_on' => '2026-10-05', 'period_ends_on' => '2026-10-11'])
            ->assertRedirect();

        $payrollRun = PayrollRun::query()->firstOrFail();
        $item = PayrollItem::query()->whereBelongsTo($payrollRun)->whereBelongsTo($employee)->firstOrFail();
        $this->assertSame('100.00', $item->absence_deduction);
        $this->assertSame('500.00', $item->total);
        $this->assertSame($payrollRun->id, $incident->fresh()->payroll_run_id);
    }

    public function test_a_justified_absence_is_resolved_before_payroll_without_a_salary_deduction(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        Employee::query()->update(['requires_check_in' => false]);
        $employee = Employee::query()->where('email', 'ana@crepe.mx')->firstOrFail();
        $employee->update(['requires_check_in' => true, 'salary' => 600, 'salary_type' => 'weekly']);
        AppSetting::put('attendance.tracking_starts_on', '2026-10-05');
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));

        $this->actingAs($administrator)
            ->post(route('payroll.store'), ['period_starts_on' => '2026-10-05', 'period_ends_on' => '2026-10-11'])
            ->assertSessionHasErrors('period_starts_on');

        $incident = AttendanceIncident::query()->whereBelongsTo($employee)->firstOrFail();
        $this->actingAs($administrator)
            ->put(route('attendance.incidents.resolve', $incident), ['resolution' => 'justified_absence', 'reason' => 'Incapacidad médica'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($administrator)
            ->post(route('payroll.store'), ['period_starts_on' => '2026-10-05', 'period_ends_on' => '2026-10-11'])
            ->assertRedirect();

        $item = PayrollItem::query()->whereBelongsTo(PayrollRun::query()->firstOrFail())->whereBelongsTo($employee)->firstOrFail();
        $this->assertSame('0.00', $item->absence_deduction);
        $this->assertSame('600.00', $item->total);
    }
}
