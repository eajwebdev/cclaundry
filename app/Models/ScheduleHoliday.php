<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ScheduleHoliday extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['date' => 'date'];

    public function setDateAttribute($value): void
    {
        $this->attributes['date'] = Carbon::parse($value)->toDateString();
    }
}
