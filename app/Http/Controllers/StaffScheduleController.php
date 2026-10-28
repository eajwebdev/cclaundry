<?php

namespace App\Http\Controllers;

use App\Models\AttendanceEmployee;
use App\Models\ScheduleLeaveRequest;
use App\Models\ScheduleNotice;
use App\Models\ScheduleShift;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffScheduleController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'view' => ['nullable', Rule::in(['week', 'month'])]]);
        $employee = AttendanceEmployee::findOrFail(session('attendance_employee_id'));
        $date = Carbon::parse($request->input('date', today()->toDateString()));
        $view = $request->input('view', 'week');
        $start = $view === 'month' ? $date->copy()->startOfMonth() : $date->copy()->startOfWeek();
        $end = $view === 'month' ? $date->copy()->endOfMonth() : $date->copy()->endOfWeek();
        $shifts = ScheduleShift::with('branch:id,name')->where('attendance_employee_id', $employee->id)->whereNotNull('published_data')->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->get()->keyBy(fn ($shift) => $shift->work_date->toDateString());
        $days = collect();
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $days->push($day->copy());
        }
        $notices = ScheduleNotice::where('attendance_employee_id', $employee->id)->latest('id')->limit(30)->get();
        $leaveRequests = ScheduleLeaveRequest::where('attendance_employee_id', $employee->id)->latest('id')->limit(20)->get();

        return response()->view('attendance.scheduling.index', compact('employee', 'date', 'view', 'start', 'end', 'days', 'shifts', 'notices', 'leaveRequests'))->header('Cache-Control', 'private, no-store');
    }

    public function requestLeave(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['vacation', 'sick', 'personal', 'unpaid'])],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'before_or_equal:'.today()->addYear()->toDateString()],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        DB::transaction(function () use ($data) {
            $employee = AttendanceEmployee::lockForUpdate()->findOrFail(session('attendance_employee_id'));
            if (ScheduleLeaveRequest::where('attendance_employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists()) {
                throw ValidationException::withMessages(['starts_on' => 'You already have a pending or approved request in this date range.']);
            }
            ScheduleLeaveRequest::create($data + ['attendance_employee_id' => $employee->id, 'branch_id' => $employee->branch_id, 'status' => 'pending']);
        });

        return back()->with('success', 'Time-off request sent for manager approval.');
    }

    public function readNotice(Request $request, ScheduleNotice $notice)
    {
        abort_unless((int) $notice->attendance_employee_id === (int) session('attendance_employee_id'), 403);
        $notice->update(['read_at' => now()]);

        return back();
    }
}
