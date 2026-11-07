<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashOpening extends Model
{
    public const DENOMINATIONS = ['1000', '500', '200', '100', '50', '20', '10', '5', '1'];

    protected $fillable = [
        'branch_id',
        'business_date',
        'expected_float',
        'bag_due',
        'denominations',
        'counted_amount',
        'difference',
        'reason',
        'opened_by',
        'opened_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'denominations' => 'array',
        'expected_float' => 'decimal:2',
        'bag_due' => 'decimal:2',
        'counted_amount' => 'decimal:2',
        'difference' => 'decimal:2',
        'opened_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function opener()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
