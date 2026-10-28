<?php

namespace Database\Seeders;

use App\Models\AttendanceEmployee;
use App\Models\Branch;
use App\Models\ScheduleLeaveRequest;
use App\Models\ScheduleShift;
use App\Support\StaffScheduling;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StaffSchedulingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::where('is_active', true)->orderBy('id')->firstOrFail();
        $week = today()->startOfWeek();
        $employeesCreated = $shiftsCreated = 0;

        DB::transaction(function () use ($branch, $week, &$employeesCreated, &$shiftsCreated): void {
            foreach ([
                ['Alex', 'Santos', 'all_around', 80],
                ['Bea', 'Cruz', 'all_around', 85],
                ['Chris', 'Tan', 'part_time', 75],
                ['Drew', 'Reyes', 'rider', 80],
            ] as $index => [$firstName, $lastName, $role, $rate]) {
                $employee = AttendanceEmployee::withTrashed()->firstOrCreate([
                    'username' => 'demo-schedule-'.$branch->id.'-'.strtolower($firstName),
                ], [
                    'branch_id' => $branch->id,
                    'first_name' => 'Demo '.$firstName,
                    'last_name' => $lastName,
                    // Preview records do not share a known sign-in password.
                    'password' => Hash::make(Str::random(40)),
                    'status' => 'active',
                    'schedule_role' => $role,
                    'hourly_rate' => $rate,
                ]);
                $employeesCreated += $employee->wasRecentlyCreated ? 1 : 0;
                if ($employee->trashed() || $employee->status !== 'active' || (int) $employee->branch_id !== (int) $branch->id) {
                    continue;
                }

                for ($offset = 0; $offset < 14; $offset++) {
                    $date = $week->copy()->addDays($offset);
                    $day = $offset % 7;
                    $template = match (true) {
                        $day === ($index % 2 ? 4 : 5) => 'rest',
                        $index === 2 && in_array($day, [0, 3], true) => 'rest',
                        $index === 3 && $day === 2 => 'leave',
                        $index === 3 => 'rider',
                        $day === 6 => 'sunday',
                        $index === 1 => 'closing',
                        default => 'opening',
                    };
                    $data = StaffScheduling::templates()[$template];
                    unset($data['label']);
                    $published = $offset < 7 && in_array($index, [0, 3], true);
                    if ($index === 1 && $day === 3) {
                        $data['ends_at'] = '20:00';
                    }
                    $shift = ScheduleShift::firstOrCreate([
                        'attendance_employee_id' => $employee->id,
                        'work_date' => $date->toDateString(),
                    ], $data + [
                        'branch_id' => $branch->id,
                        'template' => $template,
                        'note' => 'Sample schedule for preview.',
                        'overtime_confirmed' => false,
                    ]);
                    if ($shift->wasRecentlyCreated) {
                        $shiftsCreated++;
                        if ($published) {
                            $shift->update(['published_data' => StaffScheduling::snapshot($shift), 'published_at' => now()]);
                        }
                    }
                }

                if ($index === 2) {
                    ScheduleLeaveRequest::firstOrCreate([
                        'attendance_employee_id' => $employee->id,
                        'starts_on' => $week->copy()->addDays(10)->toDateString(),
                        'ends_on' => $week->copy()->addDays(11)->toDateString(),
                    ], [
                        'branch_id' => $branch->id,
                        'type' => 'personal',
                        'reason' => 'Sample time-off request for preview.',
                        'status' => 'pending',
                    ]);
                }
            }
        });

        $this->command?->info("{$branch->name}: created {$employeesCreated} demo employee(s) and {$shiftsCreated} sample shifts for ".$week->format('M j').'–'.$week->copy()->addDays(13)->format('M j, Y').'. Existing entries were preserved.');
    }
}
