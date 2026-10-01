<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZReading extends Model
{
    protected $fillable = [
        'branch_id',
        'prepared_by',
        'reading_number',
        'business_date',
        'cash_count',
        'payment_breakdown',
        'expense_breakdown',
        'machine_counters',
        'expected_cash_amount',
        'cash_expense_amount',
        'expected_cash_drawer_amount',
        'actual_cash_amount',
        'expected_gcash_amount',
        'actual_gcash_amount',
        'expected_bank_amount',
        'actual_bank_amount',
        'expected_total_amount',
        'actual_total_amount',
        'over_short_amount',
        'transaction_count',
        'first_job_order_number',
        'last_job_order_number',
        'signature_name',
        'remarks',
        'closed_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'cash_count' => 'array',
        'payment_breakdown' => 'array',
        'expense_breakdown' => 'array',
        'machine_counters' => 'array',
        'expected_cash_amount' => 'decimal:2',
        'cash_expense_amount' => 'decimal:2',
        'expected_cash_drawer_amount' => 'decimal:2',
        'actual_cash_amount' => 'decimal:2',
        'expected_gcash_amount' => 'decimal:2',
        'actual_gcash_amount' => 'decimal:2',
        'expected_bank_amount' => 'decimal:2',
        'actual_bank_amount' => 'decimal:2',
        'expected_total_amount' => 'decimal:2',
        'actual_total_amount' => 'decimal:2',
        'over_short_amount' => 'decimal:2',
        'closed_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function preparer()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function hasMachineReconciliation(): bool
    {
        return collect($this->machine_counters ?? [])->contains(
            fn ($counter) => data_get($counter, 'wash.system_cycles') !== null
                || data_get($counter, 'dry.system_cycles') !== null
        );
    }

    public function unexplainedMachineCycles(): int
    {
        return (int) collect($this->machine_counters ?? [])->sum(
            fn ($counter) => abs((int) data_get($counter, 'wash.unexplained_cycles', 0))
                + abs((int) data_get($counter, 'dry.unexplained_cycles', 0))
        );
    }

    public function documentedNonJobCycles(): int
    {
        return (int) collect($this->machine_counters ?? [])->sum(
            fn ($counter) => (int) data_get($counter, 'wash.non_job_total', 0)
                + (int) data_get($counter, 'dry.non_job_total', 0)
        );
    }
}
