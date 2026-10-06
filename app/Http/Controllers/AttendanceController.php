<?php

namespace App\Http\Controllers;

use App\Models\AttendanceIncident;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(Request $request, AttendanceService $attendance): View
    {
        $from = $request->date('from')?->startOfDay() ?? now()->startOfMonth();
        $to = $request->date('to')?->startOfDay() ?? now()->startOfDay();
        $canManageIncidents = $request->user()->can('settings.manage');
        $incidents = collect();

        if ($canManageIncidents) {
            $attendance->syncIncidents($from, $to);
            $incidents = AttendanceIncident::query()
                ->with('employee:id,first_name,last_name,position')
                ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
                ->latest('work_date')
                ->latest('id')
                ->get();
        }

        return view('attendance.index', compact('canManageIncidents', 'from', 'incidents', 'to'));
    }

    public function register(Request $request, AttendanceService $attendance): RedirectResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits:4']]);
        $record = $attendance->register($data['pin']);
        $employee = $record->employee()->firstOrFail();
        $message = $record->checked_out_at === null
            ? 'Entrada registrada para '.$employee->full_name.' · '.$record->checked_in_at->format('H:i').($record->late_minutes > 0 ? ' · '.$record->late_minutes.' min de retardo.' : ' · a tiempo.')
            : 'Salida registrada para '.$employee->full_name.' · '.$record->checked_out_at->format('H:i').'.';

        return back()->with('success', $message);
    }

    public function resolve(Request $request, AttendanceIncident $attendanceIncident): RedirectResponse
    {
        abort_if($attendanceIncident->status !== 'pending', 422, 'Esta incidencia ya fue atendida.');
        $data = $request->validate([
            'resolution' => ['required', Rule::in(['day_discount', 'justified_absence'])],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $attendanceIncident->update([
            'status' => 'resolved',
            'resolution' => $data['resolution'],
            'reason' => trim($data['reason']),
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return back()->with('success', 'Incidencia atendida.');
    }
}
