<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MoneyMovement extends Model
{
    public const TYPES = [
        'bank_deposit' => ['label' => 'Bank deposit', 'direction' => 'out'],
        'owner_out' => ['label' => 'Owner took cash', 'direction' => 'out'],
        'owner_in' => ['label' => 'Owner added cash', 'direction' => 'in'],
    ];

    // Older vouchers (and accounts-payable cash funding / repayments) keep their type.
    public const LEGACY_TYPES = [
        'deposit' => ['label' => 'Cash In', 'direction' => 'in'],
        'withdraw' => ['label' => 'Cash Out', 'direction' => 'out'],
        'owner_cash_in' => ['label' => 'Cash In', 'direction' => 'in'],
        'cash_float_in' => ['label' => 'Cash In', 'direction' => 'in'],
        'sales_withdrawal' => ['label' => 'Cash Out', 'direction' => 'out'],
        'cash_out' => ['label' => 'Cash Out', 'direction' => 'out'],
    ];

    protected $fillable = [
        'branch_id',
        'recorded_by',
        'movement_date',
        'type',
        'direction',
        'amount',
        'reference_no',
        'bank_name',
        'description',
        'bag_amount',
        'drawer_amount',
        'person_name',
        'photo_path',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'amount' => 'decimal:2',
        'bag_amount' => 'decimal:2',
        'drawer_amount' => 'decimal:2',
        'voided_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Signed effect on the cash drawer. The bag part of a bank deposit was never in the drawer. */
    public function drawerEffect(): float
    {
        if ($this->voided_at) {
            return 0.0;
        }

        if ($this->type === 'bank_deposit') {
            return -(float) $this->drawer_amount;
        }

        return $this->direction === 'in' ? (float) $this->amount : -(float) $this->amount;
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type]['label']
            ?? self::LEGACY_TYPES[$this->type]['label']
            ?? ucfirst(str_replace('_', ' ', $this->type));
    }

    public static function typeOptions(): array
    {
        return self::TYPES;
    }
}
