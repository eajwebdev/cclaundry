<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchBillingRecord;
use App\Services\SubscriptionBillingService;
use Database\Seeders\SubscriptionBillingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SubscriptionBillingSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function branch(array $overrides = []): Branch
    {
        return Branch::query()->create(array_merge([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'address' => 'Kabankalan City',
            'is_active' => true,
            'machine_count' => 3,
        ], $overrides));
    }

    private function cycles(Branch $branch)
    {
        return BranchBillingRecord::query()->where('branch_id', $branch->id)->orderBy('subscription_start_date')->get();
    }

    /** First month Sep 13 – Oct 12 already paid; the next bill is due Oct 13. */
    public function test_it_records_the_paid_first_month_and_the_bill_due_next_month(): void
    {
        $branch = $this->branch();

        $this->seed(SubscriptionBillingSeeder::class);

        [$first, $next] = $this->cycles($branch)->all();

        $this->assertSame('2026-09-13', $first->subscription_start_date->toDateString());
        $this->assertSame('2026-10-12', $first->subscription_end_date->toDateString());
        $this->assertSame('paid', $first->status);
        $this->assertSame('2026-09-13', $first->payment_date->toDateString());
        $this->assertSame('1399.00', $first->amount);

        $this->assertSame('2026-10-13', $next->subscription_start_date->toDateString());
        $this->assertSame('2026-11-12', $next->subscription_end_date->toDateString());
        $this->assertSame('2026-10-13', $next->due_date->toDateString());
        $this->assertSame('unpaid', $next->status);
        $this->assertSame('1399.00', $next->amount);

        $this->assertSame('1399.00', $branch->fresh()->subscription_price);
    }

    /**
     * The whole point of recording the first month as paid: paying the
     * October bill must leave exactly one bill due, a month later, not two
     * on the same day.
     */
    public function test_paying_the_october_bill_leaves_one_bill_due_in_november(): void
    {
        $branch = $this->branch();
        $this->seed(SubscriptionBillingSeeder::class);

        Carbon::setTestNow('2026-10-13');
        $billing = app(SubscriptionBillingService::class);
        $billing->markPaidAndRenew($this->cycles($branch)->firstWhere('status', 'unpaid'), ['payment_method' => 'Cash']);

        $open = BranchBillingRecord::query()->where('branch_id', $branch->id)->where('status', 'unpaid')->get();

        $this->assertCount(1, $open);
        $this->assertSame('2026-11-13', $open->sole()->due_date->toDateString());
        $this->assertNotNull($billing->activePaidRecord($branch, Carbon::parse('2026-10-13')));
    }

    /** Today the branch is covered: no bill is open against it yet. */
    public function test_the_branch_is_covered_until_the_first_month_ends(): void
    {
        $branch = $this->branch();
        $this->seed(SubscriptionBillingSeeder::class);

        $billing = app(SubscriptionBillingService::class);

        $this->assertNotNull($billing->activePaidRecord($branch, Carbon::parse('2026-09-13')));
        $this->assertNotNull($billing->activePaidRecord($branch, Carbon::parse('2026-10-12')));
        $this->assertNull($billing->activePaidRecord($branch, Carbon::parse('2026-10-13')));
    }

    public function test_running_it_again_does_not_duplicate_either_bill(): void
    {
        $branch = $this->branch();

        $this->seed(SubscriptionBillingSeeder::class);
        $this->seed(SubscriptionBillingSeeder::class);

        $this->assertCount(2, $this->cycles($branch));
    }

    /** A bill edited or paid since the first seed is never reset. */
    public function test_running_it_again_leaves_changed_bills_alone(): void
    {
        $branch = $this->branch();
        $this->seed(SubscriptionBillingSeeder::class);

        $next = $this->cycles($branch)->firstWhere('status', 'unpaid');
        $next->update(['status' => 'paid', 'payment_date' => '2026-10-11', 'amount' => 1200]);

        $this->seed(SubscriptionBillingSeeder::class);

        $next->refresh();
        $this->assertSame('paid', $next->status);
        $this->assertSame('1200.00', $next->amount);
    }

    /** A price someone already set on the Billing screen is theirs to keep. */
    public function test_it_keeps_a_price_already_configured(): void
    {
        $branch = $this->branch(['subscription_price' => 999]);

        $this->seed(SubscriptionBillingSeeder::class);

        $this->assertSame('999.00', $branch->fresh()->subscription_price);
        $this->assertSame(['999.00', '999.00'], $this->cycles($branch)->pluck('amount')->all());
    }

    public function test_it_does_not_bill_an_inactive_branch(): void
    {
        $this->branch(['is_active' => false]);

        $this->seed(SubscriptionBillingSeeder::class);

        $this->assertSame(0, BranchBillingRecord::query()->count());
    }
}
