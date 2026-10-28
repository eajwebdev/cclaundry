<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceEmployee;
use App\Models\Branch;
use App\Models\EmployeeAttendanceRecord;
use App\Models\ScheduleHoliday;
use App\Models\ScheduleLeaveRequest;
use App\Models\ScheduleShift;
use App\Support\Activity;
use App\Support\StaffScheduling as Planner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SchedulingController extends Controller
{
    private function branch(Request $request, bool $write = false): Branch
    {
        if ($write) {
            abort_unless($request->user()->isAdmin() || $request->user()->role === 'branch_manager', 403);
        }

        return Branch::where('is_active', true)
            ->when(! $request->user()->isAdmin(), fn ($query) => $query->whereKey($request->user()->branch_id))
            ->when($request->user()->isAdmin() && $request->filled('branch_id'), fn ($query) => $query->whereKey($request->integer('branch_id')))
            ->orderBy('id')->firstOrFail();
    }

    public function index(Request $request)
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'view' => ['nullable', Rule::in(['day', 'week', 'month'])], 'employee_id' => ['nullable', 'integer']]);
        $branch = $this->branch($request);
        $date = Carbon::parse($request->input('date', today()->toDateString()))->startOfDay();
        $view = $request->input('view', 'week');
        $start = match ($view) {
            'day' => $date->copy(), 'month' => $date->copy()->startOfMonth()->startOfWeek(), default => $date->copy()->startOfWeek()
        };
        $end = match ($view) {
            'day' => $date->copy(), 'month' => $date->copy()->endOfMonth()->endOfWeek(), default => $start->copy()->addDays(6)
        };
        $days = collect();
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $days->push($day->copy());
        }
        $branches = Branch::where('is_active', true)->when(! $request->user()->isAdmin(), fn ($query) => $query->whereKey($branch->id))->orderBy('name')->get();
        $allEmployees = AttendanceEmployee::where('branch_id', $branch->id)->where('status', 'active')->orderBy('first_name')->get();
        $employees = $allEmployees->when($request->filled('employee_id'), fn ($items) => $items->where('id', $request->integer('employee_id')));
        $shifts = ScheduleShift::with('employee')->where('branch_id', $branch->id)->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->get();
        $byCell = $shifts->keyBy(fn ($shift) => $shift->attendance_employee_id.':'.$shift->work_date->toDateString());
        $byDay = $shifts->groupBy(fn ($shift) => $shift->work_date->toDateString());
        $settings = Planner::settings($branch->id);
        $coverage = $days->mapWithKeys(fn ($day) => [$day->toDateString() => Planner::coverage($byDay->get($day->toDateString(), collect()), $settings)]);
        $holidays = ScheduleHoliday::whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()->keyBy(fn ($holiday) => $holiday->date->toDateString());
        $leaveRequests = ScheduleLeaveRequest::with('employee')->where('branch_id', $branch->id)->where('status', 'pending')->orderBy('starts_on')->get();
        $attendance = EmployeeAttendanceRecord::where('branch_id', $branch->id)->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->get()->keyBy(fn ($record) => $record->attendance_employee_id.':'.$record->work_date->toDateString());
        $canEdit = $request->user()->isAdmin() || $request->user()->role === 'branch_manager';
        $showCosts = $request->user()->isSuperAdmin();
        $templates = Planner::templates();

        return view('admin.scheduling.index', compact('branch', 'branches', 'date', 'view', 'start', 'end', 'days', 'employees', 'allEmployees', 'byCell', 'byDay', 'coverage', 'settings', 'holidays', 'leaveRequests', 'attendance', 'canEdit', 'showCosts', 'templates'));
    }

    public function save(Request $request)
    {
        $branch = $this->branch($request, true);
        $validated = $request->validate([
            'cells' => ['required', 'array', 'min:1', 'max:217'],
            'cells.*.employee_id' => ['required', 'integer'], 'cells.*.date' => ['required', 'date_format:Y-m-d'],
            'template' => ['required', Rule::in(array_keys(Planner::templates()))],
            'starts_at' => ['nullable', 'date_format:H:i'], 'ends_at' => ['nullable', 'date_format:H:i'],
            'break_starts_at' => ['nullable', 'date_format:H:i'], 'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'note' => ['nullable', 'string', 'max:1000'], 'overtime_confirmed' => ['nullable', 'boolean'],
        ]);
        $data = $request->only(Planner::FIELDS);
        if (in_array($validated['template'], ['rest', 'leave', 'unscheduled'], true)) {
            $data = array_merge($data, ['starts_at' => null, 'ends_at' => null, 'break_starts_at' => null, 'break_minutes' => 0]);
        } else {
            if (empty($data['starts_at']) || empty($data['ends_at']) || Planner::minutes($data['ends_at']) <= Planner::minutes($data['starts_at'])) {
                throw ValidationException::withMessages(['ends_at' => 'End time must be after start time on the same day.']);
            }
            $break = (int) ($data['break_minutes'] ?? 0);
            if ($break && (empty($data['break_starts_at']) || Planner::minutes($data['break_starts_at']) < Planner::minutes($data['starts_at']) || Planner::minutes($data['break_starts_at']) + $break > Planner::minutes($data['ends_at']))) {
                throw ValidationException::withMessages(['break_starts_at' => 'The full unpaid break must be inside the shift.']);
            }
        }
        $data['break_minutes'] = (int) ($data['break_minutes'] ?? 0);
        $data['overtime_confirmed'] = $request->boolean('overtime_confirmed');
        DB::transaction(function () use ($validated, $branch, $data, $request) {
            foreach ($validated['cells'] as $cell) {
                $employee = AttendanceEmployee::where('branch_id', $branch->id)->where('status', 'active')->lockForUpdate()->findOrFail($cell['employee_id']);
                if (ScheduleShift::where('attendance_employee_id', $employee->id)->whereDate('work_date', $cell['date'])->where('branch_id', '!=', $branch->id)->exists()) {
                    throw ValidationException::withMessages(['cells' => $employee->name.' already has a schedule at another branch on '.$cell['date'].'. Review that assignment before replacing it.']);
                }
                $leave = ScheduleLeaveRequest::where('attendance_employee_id', $employee->id)->where('status', 'approved')->where('starts_on', '<=', $cell['date'])->where('ends_on', '>=', $cell['date'])->exists();
                if ($leave && $data['template'] !== 'leave') {
                    throw ValidationException::withMessages(['cells' => $employee->name.' has approved leave on '.$cell['date'].'.']);
                }
                ScheduleShift::updateOrCreate(['attendance_employee_id' => $employee->id, 'work_date' => $cell['date']], $data + ['branch_id' => $branch->id]);
            }
            Activity::log($request, 'schedule_draft_saved', $branch, ['cells' => $validated['cells'], 'template' => $data['template']], $branch->id);
        });

        return back()->with('success', 'Draft saved. Publish the week to share these changes with staff.');
    }

    public function publish(Request $request)
    {
        $branch = $this->branch($request, true);
        $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'coverage_confirmed' => ['nullable', 'accepted']]);
        $start = Carbon::parse($request->date)->startOfWeek();
        $count = DB::transaction(function () use ($branch, $start, $request) {
            $shifts = ScheduleShift::with('employee')->where('branch_id', $branch->id)->whereBetween('work_date', [$start->toDateString(), $start->copy()->addDays(6)->toDateString()])->lockForUpdate()->get();
            if ($shifts->isEmpty()) {
                throw ValidationException::withMessages(['schedule' => 'Add shifts before publishing.']);
            }
            $settings = Planner::settings($branch->id);
            for ($i = 0; $i < 7; $i++) {
                $dayShifts = $shifts->filter(fn ($shift) => $shift->work_date->isSameDay($start->copy()->addDays($i)));
                if (collect(Planner::coverage($dayShifts, $settings))->contains('short', true) && ! $request->boolean('coverage_confirmed')) {
                    throw ValidationException::withMessages(['coverage_confirmed' => 'Coverage is short. Confirm the coverage warning before publishing.']);
                }
            }
            $count = 0;
            foreach ($shifts as $shift) {
                $data = Planner::snapshot($shift);
                if (Planner::paidHours($data) > 8 && ! $shift->overtime_confirmed) {
                    throw ValidationException::withMessages(['schedule' => 'Confirm or shorten overtime for '.$shift->employee->name.' on '.$shift->work_date->toDateString().'.']);
                }
                if (! Planner::isDraft($shift)) {
                    continue;
                }
                $changed = $shift->published_at !== null;
                $shift->update(['published_data' => $data, 'published_at' => now()]);
                Planner::notice($shift->attendance_employee_id, 'publish:'.$shift->id.':'.Str::uuid(), $changed ? 'Shift changed' : 'Schedule published', $shift->work_date->format('D, M j').' · '.Planner::templates()[$data['template']]['label'].($data['starts_at'] ? ' '.$data['starts_at'].'–'.$data['ends_at'] : ''));
                $count++;
            }
            Activity::log($request, 'schedule_published', $branch, ['week' => $start->toDateString(), 'shifts' => $count, 'coverage_confirmed' => $request->boolean('coverage_confirmed')], $branch->id);

            return $count;
        });

        return back()->with('success', $count.' shift(s) published. Affected staff have a schedule notification.');
    }

    public function copy(Request $request)
    {
        $branch = $this->branch($request, true);
        $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'mode' => ['required', Rule::in(['last_week', 'month'])]]);
        $week = Carbon::parse($request->date)->startOfWeek();
        [$count, $skipped] = DB::transaction(function () use ($branch, $week, $request) {
            $sourceStart = $request->mode === 'last_week' ? $week->copy()->subWeek() : $week->copy();
            $source = ScheduleShift::where('branch_id', $branch->id)->whereBetween('work_date', [$sourceStart->toDateString(), $sourceStart->copy()->addDays(6)->toDateString()])->get();
            $count = $skipped = 0;
            $end = $request->mode === 'month' ? Carbon::parse($request->date)->endOfMonth() : $week->copy()->addDays(6);
            foreach ($source as $shift) {
                $employee = AttendanceEmployee::where('branch_id', $branch->id)->where('status', 'active')->lockForUpdate()->find($shift->attendance_employee_id);
                if (! $employee) {
                    continue;
                }
                for ($target = ($request->mode === 'month' ? Carbon::parse($request->date)->startOfMonth()->startOfWeek() : $week->copy())->addDays($shift->work_date->dayOfWeekIso - 1); $target->lte($end); $target->addWeek()) {
                    if ($request->mode === 'month' && $target->month !== Carbon::parse($request->date)->month) {
                        continue;
                    }
                    if ($request->mode === 'month' && $target->betweenIncluded($week, $week->copy()->addDays(6))) {
                        continue;
                    }
                    $day = $target->toDateString();
                    $existing = ScheduleShift::where('attendance_employee_id', $employee->id)->whereDate('work_date', $day)->first();
                    $leave = ScheduleLeaveRequest::where('attendance_employee_id', $employee->id)->where('status', 'approved')->where('starts_on', '<=', $day)->where('ends_on', '>=', $day)->exists();
                    if ($leave || ScheduleHoliday::whereDate('date', $day)->exists() || $existing?->published_at || ($existing && $request->mode === 'last_week')) {
                        $skipped++;

                        continue;
                    }
                    ScheduleShift::updateOrCreate(['attendance_employee_id' => $employee->id, 'work_date' => $day], Planner::snapshot($shift) + ['branch_id' => $branch->id]);
                    $count++;
                }
            }
            Activity::log($request, 'schedule_copied', $branch, ['mode' => $request->mode, 'week' => $week->toDateString(), 'count' => $count, 'skipped' => $skipped], $branch->id);

            return [$count, $skipped];
        });

        return back()->with('success', "$count draft shift(s) copied; $skipped holiday, leave, published or existing cell(s) skipped.");
    }

    public function settings(Request $request)
    {
        $branch = $this->branch($request, true);
        $data = $request->validate([
            'opens_at' => ['required', 'date_format:H:i'], 'closes_at' => ['required', 'date_format:H:i', 'after:opens_at'],
            'minimum_staff' => ['required', 'integer', 'min:0', 'max:100'], 'minimum_riders' => ['required', 'integer', 'min:0', 'max:100'],
            'early_minutes' => ['required', 'integer', 'min:0', 'max:120'], 'grace_minutes' => ['required', 'integer', 'min:0', 'max:60'], 'overtime_minutes' => ['required', 'integer', 'min:0', 'max:120'],
        ]);
        Planner::settings($branch->id)->fill($data)->save();
        Activity::log($request, 'schedule_settings_updated', $branch, $data, $branch->id);

        return back()->with('success', 'Scheduling settings saved.');
    }

    public function holiday(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'name' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::in(['regular', 'special'])]]);
        $holiday = ScheduleHoliday::updateOrCreate(['date' => $data['date']], $data);
        Activity::log($request, 'schedule_holiday_saved', $holiday, $data);

        return back()->with('success', 'Holiday saved. Premium pay must be reviewed separately.');
    }

    public function removeHoliday(Request $request, ScheduleHoliday $holiday)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        Activity::log($request, 'schedule_holiday_removed', $holiday, $holiday->only(['date', 'name', 'type']));
        $holiday->delete();

        return back()->with('success', 'Holiday removed.');
    }

    public function employee(Request $request, AttendanceEmployee $employee)
    {
        $branch = $this->branch($request, true);
        abort_unless((int) $employee->branch_id === (int) $branch->id, 403);
        $data = $request->validate(['schedule_role' => ['required', Rule::in(['all_around', 'part_time', 'rider'])], 'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:100000']]);
        if (! $request->user()->isSuperAdmin()) {
            unset($data['hourly_rate']);
        }
        $employee->update($data);
        Activity::log($request, 'schedule_employee_updated', $employee, [
            'schedule_role' => $data['schedule_role'],
            'hourly_rate_updated' => array_key_exists('hourly_rate', $data),
        ], $branch->id);

        return back()->with('success', 'Employee scheduling details saved.');
    }

    public function reviewLeave(Request $request, ScheduleLeaveRequest $leave)
    {
        $branch = $this->branch($request, true);
        abort_unless((int) $leave->branch_id === (int) $branch->id, 403);
        $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);
        DB::transaction(function () use ($leave, $branch, $request) {
            AttendanceEmployee::whereKey($leave->attendance_employee_id)->lockForUpdate()->firstOrFail();
            $leave = ScheduleLeaveRequest::lockForUpdate()->findOrFail($leave->id);
            abort_unless($leave->status === 'pending', 422);
            $leave->update(['status' => $request->decision, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            if ($request->decision === 'approved') {
                for ($day = $leave->starts_on->copy(); $day->lte($leave->ends_on); $day->addDay()) {
                    $data = ['template' => 'leave', 'starts_at' => null, 'ends_at' => null, 'break_starts_at' => null, 'break_minutes' => 0, 'note' => 'Approved '.$leave->type, 'overtime_confirmed' => false];
                    $shift = ScheduleShift::updateOrCreate(['attendance_employee_id' => $leave->attendance_employee_id, 'work_date' => $day->toDateString()], $data + ['branch_id' => $branch->id]);
                    // Approved leave is immediately authoritative, including an already published week.
                    $shift->update(['published_data' => Planner::snapshot($shift), 'published_at' => now()]);
                }
            }
            Planner::notice($leave->attendance_employee_id, 'leave:'.$leave->id, 'Time off '.$request->decision, $leave->starts_on->format('M j').'–'.$leave->ends_on->format('M j').': '.$request->decision);
            Activity::log($request, 'schedule_leave_reviewed', $leave, ['decision' => $request->decision], $branch->id);
        });

        return back()->with('success', 'Leave request '.$request->decision.'.');
    }

    public function reviewOvertime(Request $request, EmployeeAttendanceRecord $record)
    {
        $branch = $this->branch($request, true);
        abort_unless((int) $record->branch_id === (int) $branch->id, 403);
        $data = $request->validate(['approved_minutes' => ['required', 'integer', 'min:0', 'max:1440']]);
        DB::transaction(function () use ($record, $request, $data, $branch) {
            $record = EmployeeAttendanceRecord::lockForUpdate()->findOrFail($record->id);
            $flags = $record->schedule_flags ?? [];
            abort_unless(isset($flags['unapproved_overtime_minutes']), 422);
            if ($data['approved_minutes'] > $flags['unapproved_overtime_minutes']) {
                throw ValidationException::withMessages(['approved_minutes' => 'Approved overtime cannot exceed recorded overtime.']);
            }
            $flags['approved_overtime_minutes'] = $data['approved_minutes'];
            $flags['overtime_reviewed_by'] = $request->user()->id;
            $record->update(['schedule_flags' => $flags]);
            Activity::log($request, 'schedule_overtime_reviewed', $record, $data, $branch->id);
        });

        return back()->with('success', 'Overtime review saved.');
    }
}
