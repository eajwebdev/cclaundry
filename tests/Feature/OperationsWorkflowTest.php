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

    public function test_bank_deposit_transfers_drawer_cash_to_bank_without_creating_sales(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->post(route('admin.petty-cash.store'), [
            'movement_date' => '2026-10-30', 'type' => 'deposit', 'amount' => 1000,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('admin.petty-cash.store'), [
            'movement_date' => '2026-10-30', 'type' => 'bank_deposit', 'amount' => 700,
            'bank_name' => 'Test Bank 1234', 'reference_no' => 'SLIP-001',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('money_movements', [
            'type' => 'bank_deposit', 'direction' => 'out', 'amount' => 700,
            'bank_name' => 'Test Bank 1234', 'recorded_by' => $manager->id,
            'branch_id' => $manager->branch_id,
        ]);
        $financial = FinancialReconciliation::forPeriod($manager->branch_id, '2026-10-30', '2026-10-30');
        $this->assertSame(300.0, $financial['expected_cash_drawer']);
        $this->assertSame(700.0, $financial['expected_bank']);
        $this->assertSame(700.0, $financial['bank_deposits']);
        $this->assertSame(1000.0, $financial['expected_total']);
        $this->assertSame(0.0, $financial['sales_owned']);
        $this->get(route('admin.petty-cash.index', ['movement_date' => '2026-10-30', 'type' => 'bank_deposit']))
            ->assertOk()->assertSee('Test Bank 1234')->assertSee('SLIP-001');
        $this->get(route('admin.petty-cash.index', ['movement_date' => '2026-10-30', 'type' => 'withdraw']))
            ->assertOk()->assertDontSee('SLIP-001');
        $this->post(route('admin.petty-cash.store'), [
            'movement_date' => '2026-10-30', 'type' => 'bank_deposit', 'amount' => 100,
        ])->assertSessionHasErrors(['bank_name', 'reference_no']);
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
}
