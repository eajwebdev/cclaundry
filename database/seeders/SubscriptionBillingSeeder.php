<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchBillingRecord;
use App\Services\SubscriptionBillingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The system's own subscription, as agreed with the client.
 *
 * ₱1,399 a month per branch, starting September 13, 2026. That first month
 * (Sep 13 – Oct 12) is already paid. The next bill covers Oct 13 – Nov 12 and
 * falls due on October 13, the day it starts, which is how the app bills every
 * cycle after a payment: in advance, one bill due on the 13th each month.
 *
 * Safe to run again. An existing record for either cycle is never touched, so
 * a bill edited or paid on the Billing screen is not reset.
 */
class SubscriptionBillingSeeder extends Seeder
{
    public const MONTHLY_PRICE = 1399.00;

    public const FIRST_CYCLE_START = '2026-09-13';

    // Not supplied with the payment; the Billing screen's own default for a
    // manual payment. Both can be corrected there.
    public const FIRST_PAYMENT_DATE = '2026-09-13';

    public const FIRST_PAYMENT_METHOD = 'Cash';

    public function run(SubscriptionBillingService $billing): void
    {
        $start = Carbon::parse(self::FIRST_CYCLE_START)->startOfDay();

        // The same end the Billing screen derives, so every record lines up.
        $end = $billing->periodEnd($start);
        $nextStart = $end->copy()->addDay();

        Branch::query()->where('is_active', true)->orderBy('id')->each(
            function (Branch $branch) use ($billing, $start, $end, $nextStart) {
                // Only fill a price nobody has set. One configured on the Billing
                // screen is the admin's call and stays as it is.
                if ($branch->subscription_price === null) {
                    $branch->forceFill(['subscription_price' => self::MONTHLY_PRICE])->save();
                }

                $price = (float) $branch->subscription_price;

                if (! $this->hasCycleStarting($branch, $start)) {
                    BranchBillingRecord::create([
                        'branch_id' => $branch->id,
                        'billing_month' => $start->month,
                        'billing_year' => $start->year,
                        'subscription_start_date' => $start->toDateString(),
                        'subscription_end_date' => $end->toDateString(),
                        'amount' => $price,
                        'due_date' => $start->toDateString(),
                        'status' => 'paid',
                        'payment_date' => self::FIRST_PAYMENT_DATE,
                        'payment_method' => self::FIRST_PAYMENT_METHOD,
                        'remarks' => 'First subscription cycle, paid.',
                    ]);
                }

                // Built by the app's own method, so this bill is exactly what a
                // payment on the Billing screen would have generated next.
                if (! $this->hasCycleStarting($branch, $nextStart)) {
                    $billing->createRecord($branch, $nextStart, $price);
                }
            }
        );
    }

    private function hasCycleStarting(Branch $branch, Carbon $start): bool
    {
        return BranchBillingRecord::query()
            ->where('branch_id', $branch->id)
            ->whereDate('subscription_start_date', $start->toDateString())
            ->exists();
    }
}
