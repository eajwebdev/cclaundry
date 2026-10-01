<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceEmployee extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'branch_id', 'first_name', 'last_name', 'phone', 'username', 'password', 'monthly_salary', 'status', 'last_login_at'];

    protected $hidden = ['password'];

    protected $casts = [
        'monthly_salary' => 'decimal:2',
        'last_login_at' => 'datetime',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function attendanceRecords() { return $this->hasMany(EmployeeAttendanceRecord::class); }
    public function salaryExpenses() { return $this->hasMany(BranchExpense::class, 'attendance_employee_id'); }

    public function getNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function getConfiguredMonthlySalaryAttribute(): float
    {
        $salary = (float) $this->monthly_salary;

        return $salary > 0 ? $salary : (float) ($this->user?->monthly_salary ?? 0);
    }
}
