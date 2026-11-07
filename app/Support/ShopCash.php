<?php

namespace App\Support;

use App\Models\BranchExpense;
use App\Models\CashOpening;
use App\Models\ZReading;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** One branch's cash drawer for one business date: what Shop Cash shows and Z-Reading Step 3 uses. */
class ShopCash
{
    public static function day(int $branchId, string $date): array
    {
        $money = FinancialReconciliation::forPeriod($branchId, $date, $date);
        $opening = CashOpening::query()->with('opener:id,name')
            ->where('branch_id', $branchId)->whereDate('business_date', $date)->first();
        $previousZ = ZReading::query()
            ->where('branch_id', $branchId)
            ->whereDate('business_date', '<', $date)
            ->latest('business_date')
            ->first();
        $zReading = ZReading::query()->where('branch_id', $branchId)->whereDate('business_date', $date)->first();
        $pending = BranchExpense::query()->with('creator:id,name')
            ->where('branch_id', $branchId)->whereDate('expense_date', $date)
            ->where('drawer_status', 'pending')->orderBy('id')->get();

        // null = no Z with a float yet (first day after go-live): the admin types it once.
        $expectedFloat = $opening ? (float) $opening->expected_float
            : ($previousZ?->float_for_tomorrow !== null ? (float) $previousZ->float_for_tomorrow : null);
        $bagDue = $opening ? (float) $opening->bag_due : (float) ($previousZ?->deposit_bag_amount ?? 0);
        $bagDeposited = $money['bag_deposited'];
        $bagShort = round($bagDue - $bagDeposited, 2);

        return [
            'opening' => $opening,
            'expected_float' => $expectedFloat,
            'opening_float' => $money['opening_float'],
            'opening_difference' => (float) ($opening?->difference ?? 0),
            'cash_payments' => $money['cash_collections'],
            'owner_added' => $money['cash_in'],
            'drawer_expenses' => $money['store_cash_expenses'],
            'extra_deposited' => $money['extra_deposited'],
            'owner_took' => $money['owner_took'],
            'should_be' => $money['expected_cash_drawer'],
            'other_expenses' => round($money['store_gcash_expenses'] + $money['owner_paid_expenses'], 2),
            'bag_due' => $bagDue,
            'bag_deposited' => $bagDeposited,
            'bag_left' => max($bagShort, 0),
            'bag_status' => match (true) {
                $bagDue <= 0 && $bagDeposited <= 0 => 'none',
                $bagDeposited <= 0 => 'not_deposited',
                $bagShort == 0 => 'full',
                $bagShort > 0 => 'short',
                default => 'over',
            },
            'bag_difference' => abs($bagShort),
            'pending' => $pending,
            'z_reading' => $zReading,
        ];
    }

    public static function money(float|string|null $amount): string
    {
        return '₱'.number_format((float) $amount, 2);
    }

    /** Owner alert: shown on the dashboard for owner/admin. */
    public static function alert(Request $request, Model $subject, int $branchId, string $message): void
    {
        Activity::log($request, 'shop_cash_alert', $subject, ['message' => $message], $branchId);
    }
}
