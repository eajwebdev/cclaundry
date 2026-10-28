<?php

namespace App\Support;

use App\Models\AttendanceEmployee;
use App\Models\EmployeeAttendanceRecord;
use App\Models\ScheduleNotice;
use App\Models\ScheduleSetting;
use App\Models\ScheduleShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class StaffScheduling
{
    public const FIELDS = ['template', 'starts_at', 'ends_at', 'break_starts_at', 'break_minutes', 'note'];

    public static function templates(): array
    {
        return [
            'opening' => ['label' => 'Opening', 'starts_at' => '08:00', 'ends_at' => '17:00', 'break_starts_at' => '12:00', 'break_minutes' => 60],
            'closing' => ['label' => 'Closing', 'starts_at' => '10:00', 'ends_at' => '19:00', 'break_starts_at' => '14:00', 'break_minutes' => 60],
            'sunday' => ['label' => 'Sunday', 'starts_at' => '10:00', 'ends_at' => '17:00', 'break_starts_at' => '13:00', 'break_minutes' => 60],
            'rider' => ['label' => 'Rider', 'starts_at' => '11:00', 'ends_at' => '19:00', 'break_starts_at' => '15:00', 'break_minutes' => 60],
            'rest' => ['label' => 'Rest day', 'starts_at' => null, 'ends_at' => null, 'break_starts_at' => null, 'break_minutes' => 0],
            'leave' => ['label' => 'Approved leave', 'starts_at' => null, 'ends_at' => null, 'break_starts_at' => null, 'break_minutes' => 0],
            'unscheduled' => ['label' => 'Not scheduled', 'starts_at' => null, 'ends_at' => null, 'break_starts_at' => null, 'break_minutes' => 0],
        ];
    }

    public static function minutes(?string $time): int
    {
        return $time ? ((int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2)) : 0;
    }

    public static function snapshot(ScheduleShift $shift): array
    {
        $data = $shift->only(self::FIELDS);
        foreach (['starts_at', 'ends_at', 'break_starts_at'] as $field) {
            $data[$field] = $data[$field] ? substr($data[$field], 0, 5) : null;
        }
        $data['break_minutes'] = (int) $data['break_minutes'];

        return $data;
    }

    public static function isDraft(ScheduleShift $shift): bool
    {
        return self::snapshot($shift) !== ($shift->published_data === null ? null : array_intersect_key($shift->published_data, array_flip(self::FIELDS)));
    }

    public static function paidHours(array $shift): float
    {
        if (empty($shift['starts_at']) || empty($shift['ends_at'])) {
            return 0;
        }

        return round(max(0, self::minutes($shift['ends_at']) - self::minutes($shift['starts_at']) - (int) ($shift['break_minutes'] ?? 0)) / 60, 2);
    }

    public static function cost(array $shift, float $rate): float
    {
        return self::paidHours($shift) > 0 ? round($rate, 2) : 0.0;
    }

    public static function settings(int $branchId): ScheduleSetting
    {
        return ScheduleSetting::firstOrNew(['branch_id' => $branchId], [
            'opens_at' => '08:00', 'closes_at' => '19:00', 'minimum_staff' => 2, 'minimum_riders' => 0,
            'early_minutes' => 15, 'grace_minutes' => 5,
        ]);
    }

    public static function coverage(Collection $shifts, ScheduleSetting $settings): array
    {
        $blocks = [];
        // Check every 15 minutes, including partial-hour breaks and shifts.
        for ($minute = self::minutes($settings->opens_at); $minute < self::minutes($settings->closes_at); $minute += 15) {
            $staff = $riders = 0;
            foreach ($shifts as $shift) {
                $data = self::snapshot($shift);
                if (! $data['starts_at'] || self::minutes($data['starts_at']) > $minute || self::minutes($data['ends_at']) <= $minute) {
                    continue;
                }
                $break = self::minutes($data['break_starts_at']);
                if ($data['break_minutes'] && $minute >= $break && $minute < $break + $data['break_minutes']) {
                    continue;
                }
                if ($shift->employee?->schedule_role === 'rider' || $data['template'] === 'rider') {
                    $riders++;
                } else {
                    $staff++;
                }
            }
            $blocks[] = ['time' => sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60), 'staff' => $staff, 'riders' => $riders,
                'short' => $staff < $settings->minimum_staff || $riders < $settings->minimum_riders];
        }

        return $blocks;
    }

    public static function publishedShift(int $employeeId, string $date, ?int $branchId = null): ?ScheduleShift
    {
        return ScheduleShift::where('attendance_employee_id', $employeeId)->whereDate('work_date', $date)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereNotNull('published_data')->first();
    }

    public static function checkClockIn(AttendanceEmployee $employee, int $branchId, ?Carbon $capturedAt = null): void
    {
        $capturedAt ??= now();
        $shift = self::publishedShift($employee->id, $capturedAt->toDateString());
        // Scheduling is opt-in: existing unscheduled employees keep their time clock.
        if (! $shift) {
            return;
        }
        $data = $shift->published_data;
        $start = $data['starts_at'] ?? null;
        if ((int) $shift->branch_id !== $branchId || ! $start) {
            throw ValidationException::withMessages(['schedule' => 'You are not scheduled to work at this branch today. Ask a manager to review your schedule.']);
        }
        $allowed = Carbon::parse($capturedAt->toDateString().' '.$start)->subMinutes(self::settings($branchId)->early_minutes);
        if ($capturedAt->lt($allowed)) {
            throw ValidationException::withMessages(['schedule' => 'Clock-in opens at '.$allowed->format('g:i A').'. Earlier clock-in needs a manager to record it.']);
        }
    }

    public static function attendanceStatus(ScheduleShift $shift, ?EmployeeAttendanceRecord $record, int $grace): array
    {
        $data = $shift->published_data;
        $clockIn = $record?->clock_in[0] ?? null;
        $start = $data['starts_at'] ?? null;
        $late = $clockIn && $start ? max(0, self::minutes($clockIn) - self::minutes($start)) : 0;
        $status = $clockIn ? 'Clocked in '.substr($clockIn, 0, 5) : 'Not clocked in';
        if (! $start) {
            $status = $data ? (self::templates()[$data['template']]['label'] ?? 'Not scheduled') : 'Draft only';
        } elseif ($late > $grace) {
            $status .= ' · '.$late.' min late';
        }
        if ($record && count($record->clock_out ?? []) >= count($record->clock_in ?? []) && $clockIn) {
            $status .= ' · Clocked out';
        } elseif ($clockIn && $shift->work_date->isToday() && ! empty($data['break_minutes'])) {
            $minute = self::minutes(now()->format('H:i'));
            $break = self::minutes($data['break_starts_at']);
            if ($minute >= $break && $minute < $break + $data['break_minutes']) {
                $status .= ' · On scheduled break';
            }
        }

        return ['label' => $status, 'late' => $late > $grace ? $late : 0];
    }

    public static function workedHours(?EmployeeAttendanceRecord $record, ?array $shift, Carbon $date): float
    {
        $minutes = 0;
        foreach ($record?->clock_in ?? [] as $index => $start) {
            $end = $record->clock_out[$index] ?? ($date->isToday() ? now()->format('H:i') : ($shift['ends_at'] ?? $start));
            $from = self::minutes($start);
            $to = self::minutes($end);
            $worked = max(0, $to - $from);
            if (! empty($shift['break_minutes']) && ! empty($shift['break_starts_at'])) {
                $break = self::minutes($shift['break_starts_at']);
                $worked -= max(0, min($to, $break + $shift['break_minutes']) - max($from, $break));
            }
            $minutes += max(0, $worked);
        }

        return round($minutes / 60, 2);
    }

    public static function notice(int $employeeId, string $key, string $title, string $message): void
    {
        ScheduleNotice::firstOrCreate(['deduplication_key' => $key], [
            'attendance_employee_id' => $employeeId, 'title' => $title, 'message' => $message,
        ]);
    }
}
