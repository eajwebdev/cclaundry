<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class DailyTask extends Model
{
    public const CATEGORIES = [
        'start_of_day' => 'Start of Day',
        'end_of_day' => 'End of Day',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
    ];

    protected $attributes = ['category' => 'end_of_day'];

    public function periodDate(string $date): string
    {
        $day = Carbon::parse($date);

        return match ($this->category) {
            'weekly' => $day->startOfWeek(Carbon::MONDAY)->toDateString(),
            'monthly' => $day->startOfMonth()->toDateString(),
            default => $day->toDateString(),
        };
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? self::CATEGORIES['end_of_day'];
    }

    // Each task loads its own period record, including weeks that cross a month boundary.
    public function scopeWithCompletionsForDate($query, int $branchId, string $date)
    {
        $day = Carbon::parse($date);
        $earliest = min($day->copy()->startOfWeek(Carbon::MONDAY)->toDateString(), $day->copy()->startOfMonth()->toDateString());

        return $query->with(['completions' => fn ($completions) => $completions
            ->with(['completer', 'employeeCompleter'])
            ->where('branch_id', $branchId)
            ->whereDate('work_date', '>=', $earliest)
            ->whereDate('work_date', '<=', $day->toDateString())]);
    }

    public function retainPeriodCompletion(string $date): self
    {
        $periodDate = $this->periodDate($date);
        $this->setRelation('completions', $this->completions->filter(
            fn ($completion) => $completion->work_date->toDateString() === $periodDate
        )->values());

        return $this;
    }

    protected $fillable = ['branch_id', 'name', 'category', 'requires_photo', 'is_active'];

    protected $casts = ['requires_photo' => 'boolean', 'is_active' => 'boolean'];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function completions()
    {
        return $this->hasMany(DailyTaskCompletion::class);
    }
}
