<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\CashOpening;
use App\Models\Customer;
use App\Models\CycleRecord;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use App\Models\ZReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Acceptance tests from the Z-Reading Rebuild spec (Nov 06 real numbers). */
class ZReadingStepsTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-11-06';

    private Branch $branch;

    private User $admin;

    private User $attendant;

    private JobOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(self::DATE.' 18:00:00');

        SystemSetting::query()->create([
            'business_name' => 'Cane & Cotton Laundry', 'contact_number' => '09171234567', 'business_address' => 'Kabankalan City',
            'currency' => 'PHP', 'job_order_prefix' => 'JO', 'invoice_prefix' => 'INV', 'is_completed' => true,
        ]);
        SystemTrialSetting::query()->create([
            'trial_enabled' => true, 'trial_start_date' => now()->subDay(), 'trial_end_date' => now()->addDays(5),
            'trial_status' => 'active', 'grace_period_days' => 0,
        ]);
        $this->branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'machine_count' => 2, 'titan_machine_numbers' => [2], 'is_active' => true]);
        $access = ['petty_cash', 'expenses', 'z_readings'];
        $this->attendant = User::factory()->create(['role' => 'cashier', 'branch_id' => $this->branch->id, 'access' => $access]);
        $this->admin = User::factory()->create(['role' => 'admin', 'branch_id' => $this->branch->id, 'access' => $access]);

        // Nov 05 closing: float ₱136, wallet ₱4,000, sealed bag ₱1,600, counters at 100/200.
        ZReading::query()->create([
            'branch_id' => $this->branch->id, 'reading_number' => 'ZR-NOV05', 'business_date' => '2026-11-05',
            'signature_name' => 'Cashier', 'float_for_tomorrow' => 136, 'deposit_bag_amount' => 1600,
            'actual_gcash_amount' => 4000, 'actual_cash_amount' => 1736, 'status' => 'balanced',
            'machine_counters' => [
                1 => ['wash' => ['ending' => 100], 'dry' => ['ending' => 200]],
                2 => ['wash' => ['ending' => 100], 'dry' => ['ending' => 200]],
            ],
        ]);
        $this->openShop(self::DATE, 136, 136, 1600);

        $this->order = $this->sale(self::DATE, 'JO-0412', 1678, 'cash');
        $this->sale(self::DATE, 'JO-0413', 253, 'gcash');
    }

    public function test_nov_06_balances_with_an_explained_machine_and_adds_the_missing_cycle(): void
    {
        $payload = $this->payload();
        $payload['machine_counters'][1]['wash'] = ['ending' => 101, 'non_job_cycles' => ['not_recorded' => 1], 'jo_ref' => 'JO-0412'];

        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $payload)->assertSessionHasNoErrors();

        $z = $this->z();
        $this->assertSame('balanced', $z->status);
        $this->assertSame('explained', $z->machine_counters[1]['wash']['reconciliation_status']);
        $this->assertSame('JO-0412', $z->machine_counters[1]['wash']['jo_ref']);
        $this->assertSame(1, CycleRecord::query()->where('job_order_id', $this->order->id)->where('machine_number', 1)->count());
        $this->assertSame('1814.00', $z->expected_cash_drawer_amount);
        $this->assertSame('0.00', $z->cash_diff);
        $this->assertSame($this->attendant->id, $z->closed_by);
    }

    public function test_unexplained_machine_and_missing_jo_number_block_the_save(): void
    {
        $payload = $this->payload();
        $payload['machine_counters'][1]['wash'] = ['ending' => 101];
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $payload)->assertSessionHasErrors('machine_counters.1.wash.non_job_cycles');

        $payload['machine_counters'][1]['wash'] += ['non_job_cycles' => ['not_recorded' => 1]];
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $payload)->assertSessionHasErrors('machine_counters.1.wash.jo_ref');

        $payload['machine_counters'][1]['wash']['jo_ref'] = 'JO-NOPE';
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $payload)->assertSessionHasErrors('machine_counters.1.wash.jo_ref');

        $payload['machine_counters'][1]['wash'] = ['ending' => 99];
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $payload)->assertSessionHasErrors('machine_counters.1.wash.ending');

        $this->assertDatabaseCount('z_readings', 1);
    }

    public function test_cash_over_and_gcash_short_each_show_in_their_own_step_and_need_a_remark(): void
    {
        $payload = $this->payload(['cash_count' => ['1' => 1864], 'actual_gcash_amount' => 4203]);

        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $payload)->assertSessionHasErrors('remarks');
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $payload + ['remarks' => 'Change mix-up'])->assertSessionHasNoErrors();

        $z = $this->z();
        $this->assertSame('50.00', $z->cash_diff);
        $this->assertSame('-50.00', $z->gcash_diff);
        $this->assertSame('with_over_short', $z->status);
        $alert = ActivityLog::query()->where('action', 'shop_cash_alert')->sole()->properties['message'];
        $this->assertStringContainsString('Cash over ₱50.00', $alert);
        $this->assertStringContainsString('GCash short ₱50.00', $alert);
    }

    public function test_gcash_carries_the_wallet_and_an_owner_transfer_lowers_it(): void
    {
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload([
            'actual_gcash_amount' => 238, 'gcash_transfer' => 1, 'gcash_transfer_amount' => 4000, 'gcash_transfer_fee' => 15,
        ]))->assertSessionHasErrors('gcash_transfer_ref');

        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload([
            'actual_gcash_amount' => 238, 'gcash_transfer' => 1, 'gcash_transfer_amount' => 4000, 'gcash_transfer_fee' => 15, 'gcash_transfer_ref' => '9031 552 718406',
        ]))->assertSessionHasNoErrors();

        $z = $this->z();
        $this->assertSame('4000.00', $z->gcash_opening);
        $this->assertSame('238.00', $z->expected_gcash_amount); // 4,000 + 253 − 4,000 − 15
        $this->assertSame('0.00', $z->gcash_diff);
        $this->assertSame('9031 552 718406', $z->gcash_transfer_ref);
    }

    public function test_short_slip_is_red_only_in_the_deposit_step(): void
    {
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload(['deposit_slip_amount' => 1500, 'remarks' => 'Bank counted ₱100 less']))
            ->assertSessionHasNoErrors();

        $z = $this->z();
        $this->assertSame('-100.00', $z->deposit_diff);
        $this->assertSame('0.00', $z->cash_diff);
        $this->assertSame('with_over_short', $z->status);
    }

    public function test_not_deposited_today_adds_the_old_bag_to_tonights_bag(): void
    {
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload([
            'deposit_carried_over' => 1, 'deposit_slip_amount' => null, 'deposit_slip_ref' => null, 'float_for_tomorrow' => 500,
        ]))->assertSessionHasNoErrors();

        $z = $this->z();
        $this->assertTrue($z->deposit_carried_over);
        $this->assertSame('0.00', $z->deposit_diff);
        $this->assertSame('2914.00', $z->deposit_bag_amount); // 1,814 − 500 + 1,600
    }

    public function test_float_above_the_cash_counted_is_rejected(): void
    {
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload(['float_for_tomorrow' => 2000]))
            ->assertSessionHasErrors('float_for_tomorrow');
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload(['float_for_tomorrow' => null]))
            ->assertSessionHasErrors('float_for_tomorrow');
    }

    public function test_next_day_opens_with_the_actual_values_of_this_z(): void
    {
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload(['actual_gcash_amount' => 4300, 'float_for_tomorrow' => 500, 'remarks' => 'GCash over']))
            ->assertSessionHasNoErrors();

        $this->travelTo('2026-11-07 08:00:00');
        $this->actingAs($this->attendant)->get(route('admin.z-readings.create', ['business_date' => '2026-11-07']))
            ->assertOk()
            ->assertSeeInOrder(['Balance at last Z-Reading', '₱4,300.00'])
            ->assertSeeInOrder(['Bag from Nov 06 closing', '₱1,314.00'])
            ->assertSee('\\u0022begin\\u0022:100', false)
            ->assertSee('Titan 15 kg');
    }

    public function test_saved_z_is_locked_for_staff_and_admin_edit_recalculates_later_openings(): void
    {
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload(['float_for_tomorrow' => 1814]))->assertSessionHasNoErrors();
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload())->assertSessionHasErrors('locked');

        $this->travelTo('2026-11-07 18:00:00');
        $this->openShop('2026-11-07', 1814, 1814, 0);
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload([
            'business_date' => '2026-11-07', 'cash_count' => ['1' => 1814], 'actual_gcash_amount' => 4253, 'float_for_tomorrow' => 1814,
            'deposit_slip_amount' => null, 'deposit_slip_ref' => null,
        ]))->assertSessionHasNoErrors();
        $next = ZReading::query()->whereDate('business_date', '2026-11-07')->sole();
        $this->assertSame('balanced', $next->status);

        $edit = $this->payload(['float_for_tomorrow' => 1814, 'actual_gcash_amount' => 4300, 'remarks' => 'Wallet re-checked']);
        $this->actingAs($this->admin)->post(route('admin.z-readings.store'), $edit)->assertSessionHasErrors('edit_reason');
        $this->actingAs($this->admin)->post(route('admin.z-readings.store'), $edit + ['edit_reason' => 'Typo in the GCash balance'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($message) => str_contains($message, $next->reading_number));

        $next->refresh();
        $this->assertSame('4300.00', $next->gcash_opening);
        $this->assertSame('-47.00', $next->gcash_diff);
        $this->assertSame('with_over_short', $next->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'z_reading_edited', 'subject_id' => $this->z()->id]);
    }

    public function test_a_missed_day_blocks_staff_until_it_is_closed(): void
    {
        $this->travelTo('2026-11-07 18:00:00');
        $this->openShop('2026-11-07', 136, 136, 1600);

        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload(['business_date' => '2026-11-07']))
            ->assertSessionHasErrors('missed_day');
    }

    public function test_attendant_never_sees_a_bank_balance(): void
    {
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload())->assertSessionHasNoErrors();

        $this->actingAs($this->attendant)->get(route('admin.z-readings.create'))
            ->assertOk()
            ->assertDontSee('Expected Bank')
            ->assertDontSee('Bank Balance')
            ->assertDontSee('actual_bank_amount')
            ->assertSee('Only an admin can change it');
        $this->actingAs($this->attendant)->get(route('admin.petty-cash.index'))->assertOk()->assertDontSee('Expected Bank');
        $this->actingAs($this->attendant)->get(route('admin.z-readings.pdf', $this->z()))->assertOk();
    }

    public function test_first_z_after_go_live_needs_an_admin_to_set_the_gcash_balance(): void
    {
        ZReading::query()->where('reading_number', 'ZR-NOV05')->update(['status' => null]);

        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload())->assertSessionHasErrors('setup');
        $this->actingAs($this->admin)->post(route('admin.z-readings.store'), $this->payload())->assertSessionHasErrors('gcash_opening');
        $this->actingAs($this->admin)->post(route('admin.z-readings.store'), $this->payload(['gcash_opening' => 4000]))->assertSessionHasNoErrors();
        $this->assertSame('0.00', $this->z()->gcash_diff);
    }

    public function test_saving_a_missed_earlier_day_recalculates_the_later_z(): void
    {
        $this->travelTo('2026-11-07 18:00:00');
        $this->openShop('2026-11-07', 136, 1950, 1600);
        $this->actingAs($this->admin)->post(route('admin.z-readings.store'), $this->payload([
            'business_date' => '2026-11-07', 'cash_count' => ['1' => 1950], 'actual_gcash_amount' => 4253, 'remarks' => 'Nov 06 was missed',
        ]))->assertSessionHasNoErrors();
        $later = ZReading::query()->whereDate('business_date', '2026-11-07')->sole();
        $this->assertSame('4000.00', $later->gcash_opening);

        $this->actingAs($this->admin)->post(route('admin.z-readings.store'), $this->payload(['actual_gcash_amount' => 4253]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($message) => str_contains($message, $later->reading_number));
        $this->assertSame('4253.00', $later->fresh()->gcash_opening);
    }

    public function test_admin_edit_keeps_who_closed_the_day(): void
    {
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload())->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.z-readings.store'), $this->payload(['edit_reason' => 'Recount']))->assertSessionHasNoErrors();

        $this->assertSame($this->attendant->id, $this->z()->closed_by);
    }

    public function test_admin_can_correct_a_z_saved_before_shop_cash_existed(): void
    {
        CashOpening::query()->delete();
        ZReading::query()->create(['branch_id' => $this->branch->id, 'reading_number' => 'ZR-NOV06', 'business_date' => self::DATE, 'signature_name' => 'x']);

        $this->actingAs($this->admin)->post(route('admin.z-readings.store'), $this->payload([
            'edit_reason' => 'Fix old record', 'cash_count' => ['1' => 1678], 'float_for_tomorrow' => 0, 'remarks' => 'No float that day',
        ]))->assertSessionHasNoErrors();
    }

    public function test_empty_cash_count_and_centavo_float_are_rejected(): void
    {
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload(['cash_count' => [], 'remarks' => 'x']))
            ->assertSessionHasErrors('cash_count');
        $this->actingAs($this->attendant)->post(route('admin.z-readings.store'), $this->payload(['cash_count' => ['1' => 1813, '0.25' => 4], 'float_for_tomorrow' => 135.75]))
            ->assertSessionHasErrors('float_for_tomorrow');
    }

    public function test_orders_charged_to_an_account_still_add_up_in_step_1(): void
    {
        $this->sale(self::DATE, 'JO-PO-1', 500, 'po');

        $this->actingAs($this->attendant)->get(route('admin.z-readings.create'))
            ->assertOk()
            ->assertSee('Charged to account (PO / monthly)')
            ->assertDontSee('this does not add up');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_date' => self::DATE,
            'cash_count' => ['1' => 1814],
            'actual_gcash_amount' => 4253,
            'deposit_slip_amount' => 1600,
            'deposit_slip_ref' => '0045821',
            'float_for_tomorrow' => 136,
            'machine_counters' => [
                1 => ['wash' => ['ending' => 100], 'dry' => ['ending' => 200]],
                2 => ['wash' => ['ending' => 100], 'dry' => ['ending' => 200]],
            ],
        ], $overrides, ['branch_id' => $this->branch->id]);
    }

    private function z(): ZReading
    {
        return ZReading::query()->whereDate('business_date', self::DATE)->sole();
    }

    private function openShop(string $date, float $expected, float $counted, float $bag): void
    {
        CashOpening::query()->create([
            'branch_id' => $this->branch->id, 'business_date' => $date, 'expected_float' => $expected, 'bag_due' => $bag,
            'denominations' => ['1' => (int) $counted], 'counted_amount' => $counted, 'difference' => $counted - $expected,
            'opened_by' => $this->attendant->id, 'opened_at' => now(),
        ]);
    }

    private function sale(string $date, string $number, float $amount, string $type): JobOrder
    {
        $customer = Customer::query()->create(['branch_id' => $this->branch->id, 'name' => 'Walk-in '.$number, 'billing_type' => 'regular', 'is_active' => true]);
        $order = JobOrder::query()->create([
            'branch_id' => $this->branch->id, 'customer_id' => $customer->id, 'created_by' => $this->admin->id,
            'job_order_number' => $number, 'status' => 'completed', 'subtotal' => $amount, 'discount' => 0, 'tax' => 0,
            'total' => $amount, 'paid_amount' => $amount, 'balance' => 0,
        ]);
        Payment::query()->create([
            'branch_id' => $this->branch->id, 'collected_branch_id' => $this->branch->id, 'job_order_id' => $order->id,
            'customer_id' => $customer->id, 'received_by' => $this->admin->id, 'payment_number' => 'PAY-'.$number,
            'payment_type' => $type, 'amount' => $amount, 'paid_at' => $date.' 10:00:00',
        ]);

        return $order;
    }
}
