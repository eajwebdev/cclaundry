<?php

namespace Tests\Feature\OwnerDashboard;

use App\Models\Branch;
use App\Models\BranchExpense;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\ExpenseReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BillFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ExpenseReceipt::DISK);
        SystemSetting::query()->create([
            'business_name' => 'Spin Klean Laundry', 'currency' => 'PHP', 'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV', 'primary_color' => '#2E7D32', 'is_completed' => true,
        ]);
        $this->admin = User::factory()->create(['role' => 'super_admin', 'access' => ['expenses']]);
        $this->branch = Branch::query()->create(['name' => 'Branch 1', 'code' => 'B001', 'branch_type' => 'full_service', 'is_active' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'branch_id' => $this->branch->id,
            'category' => 'utilities',
            'title' => 'Electric bill',
            'amount' => 1200,
            'paid_with' => 'gcash',
            'receipt' => UploadedFile::fake()->image('receipt.jpg', 400, 300),
            'expense_date' => '2026-11-10',
        ];
    }

    private function bill(array $overrides = []): array
    {
        return $this->payload($overrides + ['bill_type' => 'electricity', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30']);
    }

    public function test_a_bill_needs_a_type_and_a_period(): void
    {
        $this->actingAs($this->admin)->post(route('admin.expenses.store'), $this->payload(['category' => 'utilities']))
            ->assertSessionHasErrors(['bill_type', 'period_start', 'period_end']);
        $this->actingAs($this->admin)->post(route('admin.expenses.store'), $this->payload(['category' => 'rent', 'title' => 'Rent']))
            ->assertSessionHasErrors(['period_start', 'period_end'])
            ->assertSessionDoesntHaveErrors('bill_type');
        $this->assertDatabaseCount('branch_expenses', 0);
    }

    public function test_bill_type_must_match_the_expense_type(): void
    {
        $this->actingAs($this->admin)->post(route('admin.expenses.store'), $this->bill(['bill_type' => 'rent']))
            ->assertSessionHasErrors('bill_type');
        $this->actingAs($this->admin)->post(route('admin.expenses.store'), $this->bill(['bill_type' => 'sky']))
            ->assertSessionHasErrors('bill_type');
    }

    public function test_rent_and_government_fees_get_their_bill_type_automatically(): void
    {
        $period = ['period_start' => '2026-11-01', 'period_end' => '2026-11-30'];
        $this->actingAs($this->admin)->post(route('admin.expenses.store'), $this->payload($period + ['category' => 'rent', 'title' => 'Rent']))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.expenses.store'), $this->payload($period + ['category' => 'government_fees', 'title' => 'BIR']))
            ->assertSessionHasNoErrors();

        $this->assertSame(['rent', 'taxes'], BranchExpense::query()->orderBy('id')->pluck('bill_type')->all());
    }

    public function test_period_end_before_period_start_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.expenses.store'), $this->bill(['period_start' => '2026-11-30', 'period_end' => '2026-11-01']))
            ->assertSessionHasErrors('period_end');

        $this->actingAs($this->admin)->post(route('admin.expenses.store'), $this->bill())->assertSessionHasNoErrors();
        $expense = BranchExpense::query()->firstOrFail();

        $this->actingAs($this->admin)->put(route('admin.expenses.update', $expense), $this->bill(['period_start' => '2026-11-30', 'period_end' => '2026-11-01']))
            ->assertSessionHasErrors('period_end');
        $this->assertSame('2026-11-30', $expense->fresh()->period_end->toDateString());
    }

    public function test_non_bill_expense_saves_without_bill_fields(): void
    {
        $this->actingAs($this->admin)->post(route('admin.expenses.store'), $this->payload([
            'category' => 'other', 'title' => 'Mop', 'bill_type' => 'water', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30',
        ]))->assertSessionHasNoErrors();

        $expense = BranchExpense::query()->firstOrFail();
        $this->assertNull($expense->bill_type);
        $this->assertNull($expense->period_start);
        $this->assertNull($expense->period_end);
    }

    public function test_changing_a_bill_to_a_non_bill_clears_the_bill_fields(): void
    {
        $this->actingAs($this->admin)->post(route('admin.expenses.store'), $this->bill())->assertSessionHasNoErrors();
        $expense = BranchExpense::query()->firstOrFail();
        $this->assertSame('electricity', $expense->bill_type);

        $this->actingAs($this->admin)->put(route('admin.expenses.update', $expense), $this->payload(['category' => 'other']))
            ->assertSessionHasNoErrors();

        $expense->refresh();
        $this->assertNull($expense->bill_type);
        $this->assertNull($expense->period_start);
        $this->assertNull($expense->period_end);
    }

    public function test_counted_scope_excludes_pending_and_disputed(): void
    {
        foreach (['none', 'confirmed', 'pending', 'disputed'] as $status) {
            $expense = BranchExpense::query()->create([
                'branch_id' => $this->branch->id, 'category' => 'other', 'expense_type' => 'other', 'title' => $status,
                'amount' => 10, 'expense_date' => '2026-11-10', 'payment_method' => 'gcash', 'paid_from' => 'store_cash',
            ]);
            $expense->forceFill(['drawer_status' => $status])->saveQuietly();
        }

        $this->assertEqualsCanonicalizing(['none', 'confirmed'], BranchExpense::query()->counted()->pluck('title')->all());
    }

    public function test_backfill_fills_old_bills(): void
    {
        $rows = [
            'rent' => ['rent', 'Monthly rent', 'rent', '2026-02-10'],
            'water' => ['utilities', 'Water bill', 'utilities', '2026-03-15'],
            'lpg' => ['utilities', 'LPG refill', 'utilities', '2026-03-15'],
            'wifi' => ['utilities', 'PLDT Internet', 'utilities', '2026-03-15'],
            'phone' => ['utilities', 'Globe phone load', 'utilities', '2026-03-15'],
            'electric' => ['utilities', 'Electric bill', 'utilities', '2026-03-15'],
            'tax' => ['government_fees', 'BIR', 'government_fees', '2026-03-15'],
            'other' => ['other', 'Mop', 'other', '2026-03-15'],
        ];
        $ids = [];
        foreach ($rows as $key => [$type, $title, $category, $date]) {
            $ids[$key] = DB::table('branch_expenses')->insertGetId([
                'branch_id' => $this->branch->id, 'category' => $category, 'expense_type' => $type, 'title' => $title,
                'amount' => 100, 'expense_date' => $date, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $migration = require database_path('migrations/2026_11_23_000003_add_bill_fields_to_branch_expenses.php');
        $migration->backfill();

        $expect = [
            'rent' => ['rent', '2026-02-01', '2026-02-28'],
            'water' => ['water', '2026-03-01', '2026-03-31'],
            'lpg' => ['lpg', '2026-03-01', '2026-03-31'],
            'wifi' => ['wifi', '2026-03-01', '2026-03-31'],
            'phone' => ['cell_phone', '2026-03-01', '2026-03-31'],
            'electric' => ['electricity', '2026-03-01', '2026-03-31'],
            'tax' => ['taxes', '2026-03-01', '2026-03-31'],
            'other' => [null, null, null],
        ];
        $read = fn () => collect($ids)->map(fn ($id) => array_values((array) DB::table('branch_expenses')->where('id', $id)->first(['bill_type', 'period_start', 'period_end'])))->all();
        $first = $read();
        foreach ($expect as $key => $values) {
            $this->assertSame($values, [$first[$key][0], ...array_map(fn ($v) => $v === null ? null : substr($v, 0, 10), array_slice($first[$key], 1))], $key);
        }

        $migration->backfill();
        $this->assertSame($first, $read());
    }
}
