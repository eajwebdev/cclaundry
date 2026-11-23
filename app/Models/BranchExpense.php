<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchExpense extends Model
{
    /** "Paid with" options. Bank is owner/admin only. */
    public const SOURCES = [
        'drawer' => 'Drawer cash',
        'gcash' => 'Shop GCash',
        'owner' => 'Owner paid (to reimburse)',
        'bank' => 'Shop bank account',
    ];

    /** What a bill is for; each bill is spread evenly over its period (period_start to period_end). */
    public const BILL_TYPES = [
        'rent' => 'Rent',
        'electricity' => 'Electricity',
        'lpg' => 'LPG (dryers)',
        'water' => 'Water',
        'wifi' => 'WiFi',
        'taxes' => 'Taxes (BIR)',
        'cell_phone' => 'Cell phone',
    ];

    /** Bill types allowed per expense type. An expense type not listed here is not a bill. */
    public const BILL_TYPES_BY_EXPENSE_TYPE = [
        'rent' => ['rent'],
        'government_fees' => ['taxes'],
        'utilities' => ['electricity', 'lpg', 'water', 'wifi', 'cell_phone'],
    ];

    protected $fillable = [
        'branch_id',
        'attendance_employee_id',
        'category',
        'expense_type',
        'bill_type',
        'period_start',
        'period_end',
        'title',
        'amount',
        'expense_date',
        'salary_period_start',
        'salary_period_end',
        'payment_method',
        'paid_from',
        'reference_no',
        'remarks',
        'receipt_path',
        'source',
        'source_id',
        'accounts_payable_id',
        'created_by',
        'drawer_amount',
        'gcash_amount',
        'bank_amount',
        'owner_amount',
        'drawer_status',
        'confirmed_by',
        'confirmed_at',
        'dispute_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'salary_period_start' => 'date',
        'salary_period_end' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'drawer_amount' => 'decimal:2',
        'gcash_amount' => 'decimal:2',
        'bank_amount' => 'decimal:2',
        'owner_amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Expenses saved outside the Expenses form (billing, older code) still get their money source,
        // using the same rule as the migration backfill.
        static::saving(function (BranchExpense $expense): void {
            $splitSet = $expense->isDirty(['drawer_amount', 'gcash_amount', 'bank_amount', 'owner_amount']);
            if ($splitSet || ($expense->exists && ! $expense->isDirty(['amount', 'paid_from', 'payment_method']))) {
                return;
            }

            $method = strtolower((string) ($expense->payment_method ?? 'cash'));
            $source = match (true) {
                $expense->paid_from === 'owner' => 'owner',
                $method === 'cash' => 'drawer',
                $method === 'gcash' => 'gcash',
                $method === 'bank' => 'bank',
                default => null,
            };

            foreach (array_keys(self::SOURCES) as $key) {
                $expense->{$key.'_amount'} = $key === $source ? $expense->amount : 0;
            }
            $expense->drawer_status = $source === 'drawer' ? 'confirmed' : 'none';
        });
    }

    /** Money that counts in reports: pending and disputed drawer expenses do not. */
    public function scopeCounted($query)
    {
        return $query->whereIn('drawer_status', ['none', 'confirmed']);
    }

    /** Non-zero parts, e.g. ['drawer' => 1000.0, 'gcash' => 2000.0]. */
    public function sourceAmounts(): array
    {
        return array_filter(
            collect(array_keys(self::SOURCES))->mapWithKeys(fn ($key) => [$key => (float) $this->{$key.'_amount'}])->all(),
            fn ($amount) => $amount > 0
        );
    }

    /** What this expense took out of the drawer: only confirmed drawer parts count. */
    public function drawerEffect(): float
    {
        return $this->drawer_status === 'confirmed' ? -(float) $this->drawer_amount : 0.0;
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function employee()
    {
        return $this->belongsTo(AttendanceEmployee::class, 'attendance_employee_id')->withTrashed();
    }

    public function accountsPayable()
    {
        return $this->belongsTo(AccountsPayable::class);
    }
}
