<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ScheduleShift extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['work_date' => 'date', 'published_at' => 'datetime', 'published_data' => 'array', 'overtime_confirmed' => 'boolean'];

    public function employee()
    {
        return $this->belongsTo(AttendanceEmployee::class, 'attendance_employee_id');
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
