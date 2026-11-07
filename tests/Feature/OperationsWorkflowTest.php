<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\Branch;
use App\Models\DailyTask;
use App\Models\DailyTaskCompletion;
use App\Models\MoneyMovement;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\FinancialReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        SystemSetting::query()->create([
            'business_name' => 'Laundry', 'contact_number' => '09171234567',
            'business_address' => 'Manila', 'currency' => 'PHP',
            'job_order_prefix' => 'JO', 'invoice_prefix' => 'INV',
            'primary_color' => '#2E7D32', 'is_completed' => true,
        ]);
        $branch = Branch::query()->create(['name' => 'Branch A', 'code' => 'A', 'is_active' => true]);

        return User::factory()->create([
            'role' => 'branch_manager', 'branch_id' => $branch->id,
            'access' => ['daily_tasks', 'petty_cash', 'branches', 'dashboard'],
        ]);
    }

    public function test_admin_can_add_a_task_directly_from_the_tasks_page(): void
    {
        $manager = $this->manager();
        $url = route('admin.daily-tasks.index', ['branch_id' => $manager->branch_id]);
        $this->actingAs($manager)->get($url)->assertOk()->assertDontSee('Add Task');

        $this->post(route('admin.daily-tasks.store', $manager->branch_id), ['name' => 'Forbidden'])->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin', 'access' => ['daily_tasks']]);
        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee('Add Task')->assertSee(route('admin.daily-tasks.store', $manager->branch_id), false);
        $this->from($url)->post(route('admin.daily-tasks.store', $manager->branch_id), [
            'name' => 'Clean counter', 'category' => 'weekly', 'requires_photo' => '0', 'is_active' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect($url);
        $this->assertDatabaseHas('daily_tasks', [
            'branch_id' => $manager->branch_id, 'name' => 'Clean counter',
            'category' => 'weekly', 'requires_photo' => false, 'is_active' => true,
        ]);
        $this->get($url)->assertOk()->assertSee('Clean counter');
    }

    public function test_job_orders_total_uses_all_matching_pages_and_respects_branch_and_status(): void
    {
        $manager = $this->manager();
        $manager->update(['access' => ['job_orders']]);
        $other = Branch::create(['name' => 'Other', 'code' => 'OTHER', 'is_active' => true]);
        $customer = \App\Models\Customer::create([
            'branch_id' => $manager->branch_id, 'name' => 'Customer', 'billing_type' => 'regular', 'is_active' => true,
        ]);
        foreach (range(1, 11) as $number) {
            $order = \App\Models\JobOrder::create([
                'branch_id' => $number === 11 ? $other->id : $manager->branch_id,
                'customer_id' => $customer->id, 'job_order_number' => 'JO-SUM-'.$number,
                'status' => $number === 10 ? 'pending' : 'ready_for_pickup',
                'subtotal' => 125.50, 'total' => 125.50, 'balance' => 125.50,
            ]);
            if ($number === 9) {
                $order->forceFill(['created_at' => today()->subDay()])->save();
            }
        }
        $this->actingAs($manager)->get(route('admin.job-orders.index', [
            'status' => 'ready_for_pickup', 'date_range' => today()->subDay()->toDateString().' to '.today()->toDateString(),
        ]))->assertOk()->assertViewHas('ordersTotal', fn ($total) => (float) $total === 1129.50)
            ->assertSee('Job Orders Total')->assertSee('PHP 1,129.50');
        $this->get(route('admin.job-orders.index', ['status' => 'ready_for_pickup']))
            ->assertOk()->assertViewHas('ordersTotal', fn ($total) => (float) $total === 1004.00);
        $this->get(route('admin.job-orders.index', ['search' => 'JO-SUM-10']))
            ->assertOk()->assertViewHas('ordersTotal', fn ($total) => (float) $total === 125.50);
    }

    public function test_bank_deposit_transfers_drawer_cash_to_bank_without_creating_sales(): void
    {
        Storage::fake(\App\Support\ExpenseReceipt::DISK);
        $manager = $this->manager();
        \App\Models\CashOpening::query()->create([
            'branch_id' => $manager->branch_id, 'business_date' => '2026-10-30', 'expected_float' => 1000,
            'denominations' => ['1000' => 1], 'counted_amount' => 1000, 'opened_by' => $manager->id, 'opened_at' => now(),
        ]);
        $this->actingAs($manager)->post(route('admin.petty-cash.store'), [
            'movement_date' => '2026-10-30', 'type' => 'bank_deposit', 'drawer_amount' => 700,
            'reference_no' => 'SLIP-001', 'photo' => UploadedFile::fake()->image('slip.jpg'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('money_movements', [
            'type' => 'bank_deposit', 'direction' => 'out', 'amount' => 700, 'drawer_amount' => 700,
            'recorded_by' => $manager->id, 'branch_id' => $manager->branch_id,
        ]);
        $financial = FinancialReconciliation::forPeriod($manager->branch_id, '2026-10-30', '2026-10-30');
        $this->assertSame(300.0, $financial['expected_cash_drawer']);
        $this->assertSame(700.0, $financial['expected_bank']);
        $this->assertSame(700.0, $financial['bank_deposits']);
        $this->assertSame(1000.0, $financial['expected_total']);
        $this->assertSame(0.0, $financial['sales_owned']);
        $this->get(route('admin.petty-cash.index', ['movement_date' => '2026-10-30']))
            ->assertOk()->assertSee('SLIP-001');
        $this->post(route('admin.petty-cash.store'), [
            'movement_date' => '2026-10-30', 'type' => 'bank_deposit', 'drawer_amount' => 100,
        ])->assertSessionHasErrors(['reference_no', 'photo']);
    }

    public function test_cash_list_defaults_to_selected_branch_and_today(): void
    {
        $manager = $this->manager();
        $other = Branch::query()->create(['name' => 'Branch B', 'code' => 'B', 'is_active' => true]);
        foreach ([[$manager->branch_id, today()->toDateString(), 'TODAY'], [$manager->branch_id, today()->subDay()->toDateString(), 'YESTERDAY'], [$other->id, today()->toDateString(), 'OTHER-BRANCH']] as [$branchId, $date, $reference]) {
            MoneyMovement::create(['branch_id' => $branchId, 'movement_date' => $date, 'type' => 'deposit', 'direction' => 'in', 'amount' => 10, 'reference_no' => $reference]);
        }
        $this->actingAs($manager)->get(route('admin.petty-cash.index'))
            ->assertOk()->assertSee('TODAY')->assertDontSee('YESTERDAY')->assertDontSee('OTHER-BRANCH');
    }

    public function test_task_schedules_keep_one_completion_per_period_and_reset_at_boundaries(): void
    {
        Storage::fake('uploads');
        $manager = $this->manager();
        foreach (['start_of_day', 'end_of_day', 'weekly', 'monthly'] as $category) {
            $this->actingAs($manager)->post(route('admin.branches.daily-tasks.store', $manager->branch_id), [
                'name' => $category, 'category' => $category, 'is_active' => true,
            ])->assertSessionHasNoErrors()->assertRedirect();
            $task = DailyTask::where('name', $category)->firstOrFail();
            foreach (['2026-10-30', '2026-10-31'] as $date) {
                $this->post(route('admin.daily-tasks.complete', $task), [
                    'branch_id' => $manager->branch_id, 'work_date' => $date,
                    'photo' => UploadedFile::fake()->image('proof.jpg'),
                ])->assertSessionHasNoErrors()->assertRedirect();
            }
            $this->assertSame(in_array($category, ['weekly', 'monthly']) ? 1 : 2, $task->completions()->count());
        }
        foreach (['2026-11-01' => ['weekly'], '2026-11-02' => []] as $date => $expected) {
            $response = $this->get(route('admin.daily-tasks.index', ['date' => $date]))->assertOk();
            $completed = $response->viewData('tasks')->filter(fn ($task) => $task->completions->isNotEmpty())->pluck('category')->all();
            $this->assertSame($expected, $completed);
        }
        $weekly = DailyTask::where('category', 'weekly')->firstOrFail();
        $this->put(route('admin.branches.daily-tasks.update', [$manager->branch_id, $weekly]), [
            'name' => 'Rescheduled', 'category' => 'monthly', 'is_active' => true,
        ])->assertSessionHasErrors('category');
        $this->assertSame('weekly', $weekly->fresh()->category);
        $this->post(route('admin.branches.daily-tasks.store', $manager->branch_id), [
            'name' => 'Bad schedule', 'category' => 'yearly',
        ])->assertSessionHasErrors('category');
    }

    public function test_kiosk_and_admin_share_the_weekly_completion_across_month_boundary(): void
    {
        Storage::fake('uploads');
        $manager = $this->manager();
        $employee = AttendanceEmployee::create([
            'branch_id' => $manager->branch_id, 'first_name' => 'Juan', 'last_name' => 'Cruz',
            'username' => 'juan', 'password' => bcrypt('password'), 'status' => 'active',
        ]);
        $task = DailyTask::create(['branch_id' => $manager->branch_id, 'name' => 'Weekly cleaning', 'category' => 'weekly', 'is_active' => true]);
        $this->travelTo(now()->setDate(2026, 10, 31));
        $this->withSession(['attendance_employee_id' => $employee->id])
            ->post(route('attendance.daily-tasks.complete', $task), ['photo' => UploadedFile::fake()->image('proof.jpg')])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('daily_task_completions', ['daily_task_id' => $task->id, 'completed_by_employee_id' => $employee->id]);
        $this->assertSame('2026-10-26', DailyTaskCompletion::firstOrFail()->work_date->toDateString());
        $this->travelTo(now()->setDate(2026, 11, 1));
        $this->withSession(['attendance_employee_id' => $employee->id])->get(route('attendance.kiosk'))
            ->assertOk()->assertViewHas('dailyTasks', fn ($tasks) => $tasks->first()->completions->count() === 1);
        $this->actingAs($manager)->get(route('admin.daily-tasks.index'))
            ->assertOk()->assertViewHas('tasks', fn ($tasks) => $tasks->first()->completions->count() === 1);
        $this->postJson(route('dashboard.assistant'), ['preset' => 'eod_tasks'])
            ->assertOk()->assertJsonPath('metrics.1.value', '1');
        $this->post(route('admin.daily-tasks.complete', $task), [
            'branch_id' => $manager->branch_id, 'work_date' => '2026-11-01', 'photo' => UploadedFile::fake()->image('replacement.jpg'),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, DailyTaskCompletion::count());
        $this->travelTo(now()->setDate(2026, 11, 2));
        $this->get(route('admin.daily-tasks.index'))
            ->assertOk()->assertViewHas('tasks', fn ($tasks) => $tasks->first()->completions->isEmpty());
    }
    public function test_cash_filters_reject_malformed_dates_and_types_without_crashing(): void
    {
        $manager = $this->manager();
        foreach ([['movement_date' => 'not-a-date'], ['movement_date' => ['bad']], ['branch_id' => ['1']]] as $filter) {
            $this->actingAs($manager)->getJson(route('admin.petty-cash.index', $filter))->assertUnprocessable();
        }
    }

    public function test_tasks_without_required_photos_can_be_completed_in_admin_and_kiosk(): void
    {
        Storage::fake('uploads');
        $manager = $this->manager();
        $this->actingAs($manager)->post(route('admin.branches.daily-tasks.store', $manager->branch_id), [
            'name' => 'Opening check', 'category' => 'start_of_day', 'requires_photo' => '0',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $task = DailyTask::where('name', 'Opening check')->firstOrFail();
        $this->assertFalse($task->requires_photo);
        $this->post(route('admin.daily-tasks.complete', $task), [
            'branch_id' => $manager->branch_id, 'work_date' => today()->toDateString(), 'remarks' => 'Ready',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('daily_task_completions', ['daily_task_id' => $task->id, 'photo_path' => null]);
        $this->get(route('admin.daily-tasks.index'))->assertOk()->assertDontSee('alt="Opening check proof"', false);
        // Adding optional proof and then updating remarks must retain that proof.
        $this->post(route('admin.daily-tasks.complete', $task), [
            'branch_id' => $manager->branch_id, 'work_date' => today()->toDateString(), 'photo' => UploadedFile::fake()->image('optional.jpg'),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $proof = DailyTaskCompletion::where('daily_task_id', $task->id)->firstOrFail()->photo_path;
        $this->post(route('admin.daily-tasks.complete', $task), [
            'branch_id' => $manager->branch_id, 'work_date' => today()->toDateString(), 'remarks' => 'Updated',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($proof, DailyTaskCompletion::where('daily_task_id', $task->id)->firstOrFail()->photo_path);
        Storage::disk('uploads')->assertExists($proof);

        $employee = AttendanceEmployee::create([
            'branch_id' => $manager->branch_id, 'first_name' => 'Juan', 'last_name' => 'Cruz',
            'username' => 'optional-photo', 'password' => bcrypt('password'), 'status' => 'active',
        ]);
        $kioskTask = DailyTask::create(['branch_id' => $manager->branch_id, 'name' => 'Kiosk check', 'requires_photo' => false, 'is_active' => true]);
        $this->withSession(['attendance_employee_id' => $employee->id])
            ->post(route('attendance.daily-tasks.complete', $kioskTask), ['remarks' => 'Done'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('daily_task_completions', ['daily_task_id' => $kioskTask->id, 'photo_path' => null]);
        $this->get(route('attendance.kiosk'))->assertOk();
        $requiredTask = DailyTask::create(['branch_id' => $manager->branch_id, 'name' => 'Required proof', 'requires_photo' => true, 'is_active' => true]);
        $this->post(route('attendance.daily-tasks.complete', $requiredTask))->assertSessionHasErrors('photo');
    }

    public function test_the_same_checklist_name_can_be_used_in_different_schedules_but_not_duplicated_in_one_schedule(): void
    {
        $manager = $this->manager();
        foreach (['start_of_day', 'end_of_day'] as $category) {
            $this->actingAs($manager)->post(route('admin.branches.daily-tasks.store', $manager->branch_id), [
                'name' => 'Count cash drawer', 'category' => $category,
            ])->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->post(route('admin.branches.daily-tasks.store', $manager->branch_id), [
            'name' => 'Count cash drawer', 'category' => 'start_of_day',
        ])->assertSessionHasErrors('name');
        $closing = DailyTask::where('category', 'end_of_day')->firstOrFail();
        $this->put(route('admin.branches.daily-tasks.update', [$manager->branch_id, $closing]), [
            'name' => 'Count cash drawer', 'category' => 'start_of_day', 'is_active' => true,
        ])->assertSessionHasErrors('name');
        $this->assertSame(2, DailyTask::where('name', 'Count cash drawer')->count());
    }

}
