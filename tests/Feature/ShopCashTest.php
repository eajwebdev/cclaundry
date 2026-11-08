<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchExpense;
use App\Models\CashOpening;
use App\Models\Customer;
use App\Models\JobOrder;
use App\Models\MoneyMovement;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use App\Models\ZReading;
use App\Support\ExpenseReceipt;
use App\Support\ShopCash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Acceptance tests from the Shop Cash developer spec (Nov 6, 2026 numbers). */
class ShopCashTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-11-06';

    private Branch $branch;

    private User $attendant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(self::DATE.' 08:00:00');
        Storage::fake(ExpenseReceipt::DISK);

        SystemSetting::query()->create([
            'business_name' => 'Cane & Cotton Laundry',
            'contact_number' => '09171234567',
            'business_address' => 'Kabankalan City',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'is_completed' => true,
        ]);
        SystemTrialSetting::query()->create([
            'trial_enabled' => true,
            'trial_start_date' => now()->subDay(),
            'trial_end_date' => now()->addDay(),
            'trial_status' => 'active',
            'grace_period_days' => 0,
        ]);
        $this->branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true]);
        $access = ['petty_cash', 'expenses', 'z_readings'];
        $this->attendant = User::factory()->create(['role' => 'cashier', 'branch_id' => $this->branch->id, 'access' => $access]);
        $this->admin = User::factory()->create(['role' => 'admin', 'branch_id' => $this->branch->id, 'access' => $access]);

        // Last night's Z left ₱136 in the drawer and ₱1,500 in the sealed bag.
        ZReading::query()->create([
            'branch_id' => $this->branch->id,
            'reading_number' => 'ZR-PREV',
            'business_date' => '2026-11-05',
            'signature_name' => 'Cashier',
            'float_for_tomorrow' => 136,
            'deposit_bag_amount' => 1500,
        ]);
    }

    public function test_matching_count_opens_without_a_reason(): void
    {
        $this->open(['100' => 1, '20' => 1, '10' => 1, '5' => 1, '1' => 1])->assertSessionHasNoErrors();

        $opening = CashOpening::query()->sole();
        $this->assertSame('136.00', $opening->counted_amount);
        $this->assertSame('0.00', $opening->difference);
        $this->assertSame('1500.00', $opening->bag_due);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'shop_cash_alert']);
    }

    public function test_short_count_needs_a_reason_and_alerts_the_owner(): void
    {
        $short = ['100' => 1, '20' => 1, '5' => 1, '1' => 1]; // ₱126

        $this->open($short)->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('cash_openings', 0);

        $this->open($short, ['reason' => 'Ten pesos missing'])->assertSessionHasNoErrors();
        $this->assertSame('-10.00', CashOpening::query()->sole()->difference);
        $this->assertStringContainsString('SHORT ₱10.00', ActivityLog::query()->where('action', 'shop_cash_alert')->sole()->properties['message']);
    }

    public function test_actions_and_z_save_are_locked_until_the_opening(): void
    {
        $this->actingAs($this->attendant)
            ->post(route('admin.petty-cash.store'), $this->day(['type' => 'owner_in', 'amount' => 100, 'person_name' => 'Owner']))
            ->assertSessionHasErrors('opening');

        $this->actingAs($this->admin)
            ->post(route('admin.z-readings.store'), $this->day(['business_date' => self::DATE, 'float_for_tomorrow' => 0]))
            ->assertSessionHasErrors('shop_cash');
        $this->assertDatabaseCount('money_movements', 0);
    }

    public function test_opening_plus_pos_cash_is_what_should_be_in_the_drawer(): void
    {
        $this->openMatching();
        $this->cashSale(1678);

        $this->assertSame(1814.0, ShopCash::day($this->branch->id, self::DATE)['should_be']);
        $this->actingAs($this->attendant)->get(route('admin.petty-cash.index'))
            ->assertOk()
            ->assertSee('₱1,814.00')
            ->assertDontSee('1 814,00');
    }

    public function test_attendant_drawer_expense_is_confirmed_and_leaves_the_drawer(): void
    {
        $this->openMatching();
        $this->cashSale(1678);

        $this->actingAs($this->attendant)->post(route('admin.expenses.store'), $this->expense())
            ->assertSessionHasErrors('confirm_drawer');

        $this->actingAs($this->attendant)->post(route('admin.expenses.store'), $this->expense(['confirm_drawer' => 1]))
            ->assertSessionHasNoErrors();

        $expense = BranchExpense::query()->sole();
        $this->assertSame('confirmed', $expense->drawer_status);
        $this->assertSame('150.00', $expense->drawer_amount);
        $this->assertSame(1664.0, ShopCash::day($this->branch->id, self::DATE)['should_be']);
    }

    public function test_admin_drawer_expense_waits_and_blocks_the_z_until_answered(): void
    {
        $this->openMatching();
        $this->cashSale(1678);

        $this->actingAs($this->admin)->post(route('admin.expenses.store'), $this->expense(['branch_id' => $this->branch->id]))
            ->assertSessionHasNoErrors();
        $expense = BranchExpense::query()->sole();
        $this->assertSame('pending', $expense->drawer_status);
        $this->assertSame(1814.0, ShopCash::day($this->branch->id, self::DATE)['should_be']);

        $this->actingAs($this->admin)
            ->post(route('admin.z-readings.store'), $this->zPayload(1814))
            ->assertSessionHasErrors('shop_cash');

        $this->actingAs($this->attendant)->get(route('admin.petty-cash.index'))->assertSee('Expenses waiting for your confirmation');
        $this->actingAs($this->attendant)->post(route('admin.petty-cash.expenses.dispute', $expense))->assertSessionHasNoErrors();

        $this->assertSame('disputed', $expense->fresh()->drawer_status);
        $this->assertSame(1814.0, ShopCash::day($this->branch->id, self::DATE)['should_be']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'shop_cash_alert', 'subject_id' => $expense->id]);
    }

    public function test_gcash_expense_does_not_touch_the_drawer(): void
    {
        $this->openMatching();
        $this->cashSale(1678);

        $this->actingAs($this->attendant)->post(route('admin.expenses.store'), $this->expense(['paid_with' => 'gcash']))
            ->assertSessionHasNoErrors();

        $day = ShopCash::day($this->branch->id, self::DATE);
        $this->assertSame(1814.0, $day['should_be']);
        $this->assertSame(150.0, $day['other_expenses']);
        $this->assertSame('none', BranchExpense::query()->sole()->drawer_status);
    }

    public function test_payroll_split_takes_only_the_drawer_part_and_staff_have_no_bank(): void
    {
        $this->openMatching();
        $this->cashSale(1678);
        $payroll = $this->expense([
            'category' => 'payroll',
            'paid_with' => null,
            'amount' => null,
            'attendance_employee_id' => $this->employee()->id,
            'salary_period_start' => '2026-10-31',
            'salary_period_end' => '2026-11-06',
            'split' => ['drawer' => 1000, 'gcash' => 2000],
            'confirm_drawer' => 1,
        ]);

        $this->actingAs($this->attendant)->post(route('admin.expenses.store'), ['split' => ['drawer' => 1000, 'bank' => 500]] + $payroll)
            ->assertSessionHasErrors('split.bank');

        $this->actingAs($this->attendant)->post(route('admin.expenses.store'), $payroll)->assertSessionHasNoErrors();

        $expense = BranchExpense::query()->sole();
        $this->assertSame('3000.00', $expense->amount);
        $this->assertSame(['drawer' => 1000.0, 'gcash' => 2000.0], $expense->sourceAmounts());
        $this->assertSame(814.0, ShopCash::day($this->branch->id, self::DATE)['should_be']);
    }

    public function test_drawer_part_larger_than_the_drawer_is_refused(): void
    {
        $this->openMatching();

        $this->actingAs($this->attendant)->post(route('admin.expenses.store'), $this->expense(['amount' => 500, 'confirm_drawer' => 1]))
            ->assertSessionHasErrors(['split.drawer' => 'That is more than the cash in the drawer (₱136.00). Check the amount.']);
        $this->assertDatabaseCount('branch_expenses', 0);
    }

    public function test_full_bag_deposit_leaves_the_drawer_unchanged(): void
    {
        $this->openMatching();
        $this->cashSale(1678);

        $this->deposit(['bag_amount' => 1500, 'drawer_amount' => 0])->assertSessionHasNoErrors();

        $day = ShopCash::day($this->branch->id, self::DATE);
        $this->assertSame(1814.0, $day['should_be']);
        $this->assertSame('full', $day['bag_status']);
        $this->assertSame(0.0, $day['bag_left']);
    }

    public function test_short_bag_deposit_needs_a_note_and_takes_the_extra_from_the_drawer(): void
    {
        $this->openMatching();
        $this->cashSale(1678);

        $this->deposit(['bag_amount' => 1400, 'drawer_amount' => 100])->assertSessionHasErrors('description');
        $this->deposit(['bag_amount' => 1400, 'drawer_amount' => 100, 'description' => 'Bag was short'])->assertSessionHasNoErrors();

        $day = ShopCash::day($this->branch->id, self::DATE);
        $this->assertSame(1714.0, $day['should_be']);
        $this->assertSame('short', $day['bag_status']);
        $this->assertSame(100.0, $day['bag_difference']);
        $this->assertSame('1500.00', MoneyMovement::query()->sole()->amount);
        $this->assertDatabaseHas('activity_logs', ['action' => 'shop_cash_alert']);
    }

    public function test_deposit_without_reference_or_photo_cannot_be_saved(): void
    {
        $this->openMatching();

        $this->deposit(['bag_amount' => 1500, 'reference_no' => null])->assertSessionHasErrors('reference_no');
        $this->deposit(['bag_amount' => 1500, 'photo' => null])->assertSessionHasErrors('photo');
        $this->assertDatabaseCount('money_movements', 0);
    }

    public function test_z_close_carries_the_float_and_bag_into_the_next_day(): void
    {
        $this->openMatching();
        $this->cashSale(1028); // drawer should be ₱1,164

        $this->actingAs($this->admin)
            ->post(route('admin.z-readings.store'), $this->zPayload(1164, 500))
            ->assertSessionHasNoErrors();

        $z = ZReading::query()->whereDate('business_date', self::DATE)->sole();
        $this->assertSame('664.00', $z->deposit_bag_amount);
        $this->assertSame('500.00', $z->float_for_tomorrow);

        $tomorrow = ShopCash::day($this->branch->id, '2026-11-07');
        $this->assertSame(500.0, $tomorrow['expected_float']);
        $this->assertSame(664.0, $tomorrow['bag_left']);
    }

    public function test_staff_cannot_change_a_day_after_its_z_is_saved(): void
    {
        $this->openMatching();
        $this->actingAs($this->admin)->post(route('admin.z-readings.store'), $this->zPayload(136, 136))->assertSessionHasNoErrors();

        $this->actingAs($this->attendant)
            ->post(route('admin.petty-cash.store'), $this->day(['type' => 'owner_in', 'amount' => 100, 'person_name' => 'Owner']))
            ->assertSessionHasErrors('opening');
    }

    public function test_admin_voids_a_movement_with_a_reason_instead_of_deleting(): void
    {
        $this->openMatching();
        $this->actingAs($this->attendant)
            ->post(route('admin.petty-cash.store'), $this->day(['type' => 'owner_in', 'amount' => 100, 'person_name' => 'Owner']))
            ->assertSessionHasNoErrors();
        $movement = MoneyMovement::query()->sole();

        $this->actingAs($this->attendant)->delete(route('admin.petty-cash.destroy', $movement), ['void_reason' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->delete(route('admin.petty-cash.destroy', $movement), ['void_reason' => 'Typed twice'])->assertSessionHasNoErrors();

        $this->assertNotNull($movement->fresh()->voided_at);
        $this->assertSame(136.0, ShopCash::day($this->branch->id, self::DATE)['should_be']);
        $this->actingAs($this->admin)->get(route('admin.petty-cash.index', ['branch_id' => $this->branch->id]))
            ->assertOk()
            ->assertSee('VOIDED: Typed twice')
            ->assertSee('Record Expense');
    }

    public function test_same_deposit_slip_cannot_be_saved_twice(): void
    {
        $this->openMatching();
        $this->deposit(['bag_amount' => 1500])->assertSessionHasNoErrors();
        $this->deposit(['bag_amount' => 1500])->assertSessionHasErrors('reference_no');
        $this->assertDatabaseCount('money_movements', 1);
    }

    public function test_double_tapped_open_gives_a_message_not_an_error_page(): void
    {
        $this->openMatching();
        $this->open(['100' => 1, '20' => 1, '10' => 1, '5' => 1, '1' => 1])->assertSessionHasErrors('opening');
        $this->assertDatabaseCount('cash_openings', 1);
    }

    public function test_opening_cannot_be_removed_once_cash_moved_or_the_z_is_saved(): void
    {
        $this->openMatching();
        $this->deposit(['bag_amount' => 1500])->assertSessionHasNoErrors();
        $opening = CashOpening::query()->sole();

        $this->actingAs($this->admin)->delete(route('admin.petty-cash.reopen', $opening))->assertSessionHasErrors('opening');
        $this->assertModelExists($opening);
    }

    public function test_staff_cannot_add_an_expense_to_a_closed_day(): void
    {
        $this->openMatching();
        ZReading::query()->create(['branch_id' => $this->branch->id, 'reading_number' => 'ZR-NOV06', 'business_date' => self::DATE, 'signature_name' => 'x']);

        $this->actingAs($this->attendant)->post(route('admin.expenses.store'), $this->expense(['confirm_drawer' => 1]))
            ->assertSessionHasErrors('expense_date');
        $this->assertDatabaseCount('branch_expenses', 0);
    }

    public function test_staff_cannot_open_the_receipt_of_a_bank_paid_expense(): void
    {
        $expense = BranchExpense::query()->create([
            'branch_id' => $this->branch->id, 'category' => 'supplies', 'title' => 'Rent', 'amount' => 100,
            'expense_date' => self::DATE, 'paid_from' => 'store_cash', 'payment_method' => 'bank',
            'receipt_path' => ExpenseReceipt::store(UploadedFile::fake()->image('r.jpg', 40, 40)), 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->attendant)->get(route('admin.expenses.receipt', $expense))->assertNotFound();
        $this->actingAs($this->admin)->get(route('admin.expenses.receipt', $expense))->assertOk();
    }

    public function test_a_report_range_counts_the_float_once_not_per_day(): void
    {
        $this->openMatching();
        CashOpening::query()->create([
            'branch_id' => $this->branch->id, 'business_date' => '2026-11-07', 'expected_float' => 136, 'denominations' => [],
            'counted_amount' => 136, 'opened_by' => $this->admin->id, 'opened_at' => now(),
        ]);

        $this->assertSame(136.0, \App\Support\FinancialReconciliation::forPeriod($this->branch->id, self::DATE, self::DATE)['expected_cash_drawer']);
        $this->assertSame(0.0, \App\Support\FinancialReconciliation::forPeriod($this->branch->id, self::DATE, '2026-11-07')['opening_float']);
    }

    private function openMatching(): void
    {
        $this->open(['100' => 1, '20' => 1, '10' => 1, '5' => 1, '1' => 1])->assertSessionHasNoErrors();
    }

    private function open(array $denominations, array $extra = [])
    {
        return $this->actingAs($this->attendant)->post(route('admin.petty-cash.open'), $this->day(['denominations' => $denominations] + $extra));
    }

    private function deposit(array $overrides)
    {
        return $this->actingAs($this->attendant)->post(route('admin.petty-cash.store'), $this->day(array_merge([
            'type' => 'bank_deposit',
            'reference_no' => 'BDO-558213',
            'photo' => UploadedFile::fake()->image('slip.jpg', 400, 300),
        ], $overrides)));
    }

    private function day(array $data): array
    {
        return ['branch_id' => $this->branch->id, 'movement_date' => self::DATE] + $data;
    }

    private function expense(array $overrides = []): array
    {
        return array_merge([
            'expense_date' => self::DATE,
            'category' => 'supplies',
            'title' => '2 gal vinegar',
            'amount' => 150,
            'paid_with' => 'drawer',
            'receipt' => UploadedFile::fake()->image('receipt.jpg', 400, 300),
        ], $overrides);
    }

    private function zPayload(float $counted, float $float = 0): array
    {
        return [
            'branch_id' => $this->branch->id,
            'business_date' => self::DATE,
            'cash_count' => ['1' => (int) $counted],
            'float_for_tomorrow' => $float,
            'gcash_opening' => 0, // previous Z is pre-rebuild: admin sets the wallet once
            'actual_gcash_amount' => 0,
            'deposit_slip_amount' => 1500,
            'deposit_slip_ref' => 'BDO-558213',
        ];
    }

    private function employee()
    {
        return \App\Models\AttendanceEmployee::query()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'username' => 'ana',
            'password' => 'secret123',
            'monthly_salary' => 13000,
            'status' => 'active',
        ]);
    }

    private function cashSale(float $amount): void
    {
        $customer = Customer::query()->create(['branch_id' => $this->branch->id, 'name' => 'Walk-in', 'billing_type' => 'regular', 'is_active' => true]);
        $order = JobOrder::query()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'job_order_number' => 'JO-'.uniqid(),
            'status' => 'completed',
            'subtotal' => $amount,
            'discount' => 0,
            'tax' => 0,
            'total' => $amount,
            'paid_amount' => $amount,
            'balance' => 0,
        ]);
        Payment::query()->create([
            'branch_id' => $this->branch->id,
            'collected_branch_id' => $this->branch->id,
            'job_order_id' => $order->id,
            'customer_id' => $customer->id,
            'received_by' => $this->admin->id,
            'payment_number' => 'PAY-'.uniqid(),
            'payment_type' => 'cash',
            'amount' => $amount,
            'paid_at' => self::DATE.' 10:00:00',
        ]);
    }
}
