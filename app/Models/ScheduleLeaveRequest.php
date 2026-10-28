<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ScheduleLeaveRequest extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'reviewed_at' => 'datetime'];

    public function employee()
    {
        return $this->belongsTo(AttendanceEmployee::class, 'attendance_employee_id')->withTrashed();
    }

    public function setStartsOnAttribute($value): void
    {
        $this->attributes['starts_on'] = Carbon::parse($value)->toDateString();
    }

    public function setEndsOnAttribute($value): void
    {
        $this->attributes['ends_on'] = Carbon::parse($value)->toDateString();
    }
}
