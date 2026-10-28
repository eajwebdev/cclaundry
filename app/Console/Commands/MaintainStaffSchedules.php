<?php

namespace App\Console\Commands;

use App\Models\EmployeeAttendanceRecord;
use App\Models\ScheduleShift;
use App\Support\StaffScheduling;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainStaffSchedules extends Command
{
    protected $signature = 'scheduling:maintain {--remind : Add tomorrow shift reminders}';

    protected $description = 'Flag and close missing punches from previous days; optionally add staff reminders';

    public function handle(): int
    {
        $closed = 0;
        EmployeeAttendanceRecord::where('work_date', '<', today())->where('work_date', '>=', today()->subDays(31))->chunkById(100, function ($records) use (&$closed) {
            foreach ($records as $record) {
                DB::transaction(function () use ($record, &$closed) {
                    $record = EmployeeAttendanceRecord::lockForUpdate()->findOrFail($record->id);
                    if (count($record->clock_in ?? []) <= count($record->clock_out ?? [])) {
                        return;
                    }
                    $shift = StaffScheduling::publishedShift($record->attendance_employee_id, $record->work_date->toDateString(), $record->branch_id);
                    $end = $shift?->published_data['ends_at'] ?? null;
                    if (! $end) {
                        return;
                    }
                    $lastIn = collect($record->clock_in)->last();
                    if (StaffScheduling::minutes($lastIn) >= StaffScheduling::minutes($end)) {
                        return;
                    }
                    $flags = $record->schedule_flags ?? [];
                    $flags['missing_punch'] = true;
                    $record->update([
                        'clock_out' => [...($record->clock_out ?? []), $end.':00'],
                        'clock_out_photos' => [...($record->clock_out_photos ?? []), null],
                        'clock_out_locations' => [...($record->clock_out_locations ?? []), null],
                        'schedule_flags' => $flags,
                    ]);
                    $closed++;
                });
            }
        });
        if ($this->option('remind')) {
            ScheduleShift::whereDate('work_date', today()->addDay())->whereNotNull('published_data')->each(function ($shift) {
                $data = $shift->published_data;
                if (! empty($data['starts_at'])) {
                    StaffScheduling::notice($shift->attendance_employee_id, 'reminder:'.$shift->id.':'.today()->toDateString(), 'Tomorrow’s shift', StaffScheduling::templates()[$data['template']]['label'].' '.$data['starts_at'].'–'.$data['ends_at']);
                }
            });
        }
        $this->info("Closed $closed missing punch(es).");

        return self::SUCCESS;
    }
}
