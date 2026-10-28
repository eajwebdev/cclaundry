<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class EmployeeAttendanceRecord extends Model
{
    protected $fillable = [
        'attendance_employee_id',
        'branch_id',
        'work_date',
        'schedule_flags',
        'clock_in',
        'clock_out',
        'clock_in_photos',
        'clock_out_photos',
        'clock_in_locations',
        'clock_out_locations',
    ];

    protected $casts = [
        'work_date' => 'date',
        'schedule_flags' => 'array',
        'clock_in' => 'array',
        'clock_out' => 'array',
        'clock_in_photos' => 'array',
        'clock_out_photos' => 'array',
        'clock_in_locations' => 'array',
        'clock_out_locations' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(AttendanceEmployee::class, 'attendance_employee_id')->withTrashed();
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function setWorkDateAttribute($value): void
    {
        $this->attributes['work_date'] = Carbon::parse($value)->toDateString();
    }
}
