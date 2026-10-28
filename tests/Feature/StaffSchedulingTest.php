<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\Branch;
use App\Models\EmployeeAttendanceRecord;
use App\Models\ScheduleHoliday;
use App\Models\ScheduleLeaveRequest;
use App\Models\ScheduleNotice;
use App\Models\ScheduleShift;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\StaffScheduling as Planner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StaffSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private AttendanceEmployee $employee;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(8, 0));
        SystemSetting::create(['business_name' => 'Laundry', 'currency' => 'PHP', 'is_completed' => true]);
        $this->branch = Branch::create(['name' => 'Guanzon', 'code' => 'G', 'is_active' => true]);
        $this->employee = AttendanceEmployee::create(['branch_id' => $this->branch->id, 'first_name' => 'Staff', 'last_name' => 'A', 'username' => 'staff-a', 'password' => bcrypt('123456'), 'status' => 'active', 'daily_rate' => 123.45]);
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($this->owner);
    }

    private function saveShift(string $date = '2026-11-09', array $overrides = [])
    {
        return $this->post(route('admin.scheduling.save'), array_merge([
            'branch_id' => $this->branch->id, 'cells' => [['employee_id' => $this->employee->id, 'date' => $date]],
            'template' => 'opening', 'starts_at' => '08:00', 'ends_at' => '17:00', 'break_starts_at' => '12:00', 'break_minutes' => 60,
        ], $overrides));
    }

    private function publish()
    {
        return $this->post(route('admin.scheduling.publish'), ['branch_id' => $this->branch->id, 'date' => '2026-11-09', 'coverage_confirmed' => 1]);
    }

    public function test_day_week_and_month_render_and_rates_are_owner_only(): void
    {
        $this->saveShift()->assertSessionHasNoErrors();
        foreach (['day', 'week', 'month'] as $view) {
            $this->get(route('admin.scheduling.index', ['view' => $view, 'date' => '2026-11-09']))->assertOk()->assertSee('Staff Scheduling');
        }
        $admin = User::factory()->create(['role' => 'admin', 'access' => ['scheduling']]);
        $this->actingAs($admin)->get(route('admin.scheduling.index'))->assertOk()->assertDontSee('123.45')->assertDontSee('Rate/day');
        $this->put(route('admin.scheduling.employee', $this->employee), ['branch_id' => $this->branch->id, 'schedule_role' => 'rider', 'daily_rate' => 999])->assertSessionHasNoErrors();
        $this->assertEquals(123.45, $this->employee->fresh()->daily_rate);
    }

    public function test_staff_only_see_published_snapshot_until_changes_are_republished(): void
    {
        $this->saveShift(overrides: ['note' => 'Draft private note'])->assertSessionHasNoErrors();
        $this->withSession(['attendance_employee_id' => $this->employee->id])->get(route('attendance.schedule'))->assertOk()->assertDontSee('Draft private note');
        $this->publish()->assertSessionHasNoErrors();
        $this->get(route('attendance.schedule'))->assertSee('Draft private note');
        $this->saveShift(overrides: ['note' => 'New private draft'])->assertSessionHasNoErrors();
        $this->get(route('attendance.schedule'))->assertSee('Draft private note')->assertDontSee('New private draft');
        $this->publish()->assertSessionHasNoErrors();
        $this->get(route('attendance.schedule'))->assertSee('New private draft');
        $this->assertDatabaseCount('schedule_notices', 2);
        $this->publish()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedule_notices', 2);
    }

    public function test_long_shifts_publish_without_overtime_and_cost_one_daily_rate(): void
    {
        $this->saveShift(overrides: ['ends_at' => '18:00'])->assertSessionHasNoErrors();
        $this->post(route('admin.scheduling.publish'), ['branch_id' => $this->branch->id, 'date' => '2026-11-09'])->assertSessionHasErrors('coverage_confirmed');
        $this->publish()->assertSessionHasNoErrors();
        $data = Planner::snapshot(ScheduleShift::sole());
        $this->assertEquals(9, Planner::paidHours($data));
        $this->assertEquals(100, Planner::cost($data, 100));
        $data['ends_at'] = '14:00';
        $this->assertEquals(100, Planner::cost($data, 100));
        $data['starts_at'] = null;
        $this->assertEquals(0, Planner::cost($data, 100));
        $this->get(route('admin.scheduling.index'))->assertOk()->assertDontSee('Confirm planned overtime')->assertDontSee('OT · +25%');
    }

    public function test_legacy_overtime_field_does_not_turn_a_published_shift_into_a_draft(): void
    {
        $this->saveShift();
        $this->publish();
        $shift = ScheduleShift::sole();
        $shift->update(['published_data' => $shift->published_data + ['overtime_confirmed' => true]]);
        $this->assertFalse(Planner::isDraft($shift->fresh()));
    }

    public function test_breaks_reduce_coverage_and_paid_hours(): void
    {
        $this->saveShift();
        $settings = Planner::settings($this->branch->id);
        $settings->minimum_staff = 1;
        $blocks = collect(Planner::coverage(ScheduleShift::with('employee')->get(), $settings))->keyBy('time');
        $this->assertFalse($blocks['11:45']['short']);
        $this->assertTrue($blocks['12:00']['short']);
        $this->assertFalse($blocks['13:00']['short']);
        $this->saveShift(overrides: ['break_starts_at' => '16:30'])->assertSessionHasErrors('break_starts_at');
    }

    public function test_branch_scope_rejects_cross_branch_writes_and_staff_cannot_edit(): void
    {
        $other = Branch::create(['name' => 'Other', 'code' => 'O', 'is_active' => true]);
        $manager = User::factory()->create(['role' => 'branch_manager', 'branch_id' => $other->id, 'access' => ['scheduling']]);
        $this->actingAs($manager);
        $this->saveShift()->assertNotFound();
        $staff = User::factory()->create(['role' => 'staff', 'branch_id' => $this->branch->id, 'access' => ['scheduling']]);
        $this->actingAs($staff);
        $this->saveShift()->assertForbidden();
        $this->assertDatabaseCount('schedule_shifts', 0);
    }

    public function test_copy_and_repeat_skip_holidays_approved_leave_and_published_days(): void
    {
        $this->saveShift();
        $this->publish();
        ScheduleHoliday::create(['date' => '2026-11-16', 'name' => 'Holiday', 'type' => 'special']);
        ScheduleLeaveRequest::create(['attendance_employee_id' => $this->employee->id, 'branch_id' => $this->branch->id, 'type' => 'vacation', 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-23', 'status' => 'approved']);
        $this->post(route('admin.scheduling.copy'), ['branch_id' => $this->branch->id, 'date' => '2026-11-09', 'mode' => 'month'])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('schedule_shifts', ['work_date' => '2026-11-16']);
        $this->assertDatabaseMissing('schedule_shifts', ['work_date' => '2026-11-23']);
        $this->assertDatabaseHas('schedule_shifts', ['work_date' => '2026-11-30', 'published_at' => null]);
        $this->assertNotNull(ScheduleShift::whereDate('work_date', '2026-11-09')->sole()->published_at);
    }

    public function test_employee_time_off_requires_approval_and_cannot_overlap(): void
    {
        $this->withSession(['attendance_employee_id' => $this->employee->id]);
        $request = ['type' => 'vacation', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-11', 'reason' => 'Family'];
        $this->post(route('attendance.schedule.leave'), $request)->assertSessionHasNoErrors();
        $this->post(route('attendance.schedule.leave'), $request)->assertSessionHasErrors('starts_on');
        $this->assertDatabaseCount('schedule_shifts', 0);
        $leave = ScheduleLeaveRequest::sole();
        $this->patch(route('admin.scheduling.leave.review', $leave), ['branch_id' => $this->branch->id, 'decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedule_shifts', 2);
        $this->assertEquals('leave', ScheduleShift::first()->published_data['template']);
        $this->saveShift('2026-11-10')->assertSessionHasErrors('cells');
        $this->assertDatabaseCount('schedule_notices', 1);
    }

    public function test_staff_cannot_read_someone_elses_notices(): void
    {
        $other = AttendanceEmployee::create(['branch_id' => $this->branch->id, 'first_name' => 'Other', 'last_name' => 'Staff', 'username' => 'other', 'password' => 'x']);
        $notice = ScheduleNotice::create(['attendance_employee_id' => $other->id, 'deduplication_key' => 'private', 'title' => 'Private', 'message' => 'Secret']);
        $this->withSession(['attendance_employee_id' => $this->employee->id])->patch(route('attendance.schedule.notices.read', $notice))->assertForbidden();
    }

    public function test_attendance_clock_rules_missing_punches_and_reminders(): void
    {
        $this->saveShift();
        $this->publish();
        $this->travelTo(now()->setTime(7, 30));
        $this->expectException(ValidationException::class);
        Planner::checkClockIn($this->employee, $this->branch->id);
    }

    public function test_missing_punch_auto_close_and_reminders_are_repeatable(): void
    {
        $this->saveShift();
        $this->saveShift('2026-11-10');
        $this->publish();
        $record = EmployeeAttendanceRecord::create(['attendance_employee_id' => $this->employee->id, 'branch_id' => $this->branch->id, 'work_date' => '2026-11-09', 'clock_in' => ['08:12:00'], 'clock_out' => []]);
        $status = Planner::attendanceStatus(ScheduleShift::whereDate('work_date', '2026-11-09')->first(), $record, 5);
        $this->assertEquals(12, $status['late']);
        $this->artisan('scheduling:maintain', ['--remind' => true])->assertSuccessful();
        $this->artisan('scheduling:maintain', ['--remind' => true])->assertSuccessful();
        $this->assertEquals(1, ScheduleNotice::where('title', 'Tomorrow’s shift')->count());
        $this->travelTo(now()->addDay()->setTime(4, 0));
        $this->artisan('scheduling:maintain')->assertSuccessful();
        $this->artisan('scheduling:maintain')->assertSuccessful();
        $this->assertSame(['17:00:00'], $record->fresh()->clock_out);
        $this->assertTrue($record->fresh()->schedule_flags['missing_punch']);
    }

    public function test_offline_sync_is_idempotent_and_preserves_capture_time(): void
    {
        Storage::fake('uploads');
        $this->saveShift();
        $this->publish();
        $this->travelTo(now()->setTime(10, 0));
        $data = ['event_id' => (string) Str::uuid(), 'employee_id' => $this->employee->id, 'branch_id' => $this->branch->id, 'action' => 'in', 'captured_at' => '2026-11-09T08:02:00'.now()->format('P'), 'face_image' => 'data:image/png;base64,'.base64_encode('test-photo')];
        $this->withSession(['attendance_employee_id' => $this->employee->id])->postJson(route('attendance.sync-punch'), $data)->assertOk();
        $this->postJson(route('attendance.sync-punch'), $data)->assertOk();
        $this->assertEquals(1, DB::table('attendance_punch_events')->count());
        $this->assertSame(['08:02:00'], EmployeeAttendanceRecord::sole()->clock_in);
        $this->assertTrue(EmployeeAttendanceRecord::sole()->schedule_flags['device_time_synced']);
        $data['event_id'] = (string) Str::uuid();
        $data['action'] = 'out';
        $data['captured_at'] = now()->setTime(18, 0)->toIso8601String();
        $this->travelTo(now()->setTime(18, 1));
        $this->postJson(route('attendance.sync-punch'), $data)->assertOk();
        $record = EmployeeAttendanceRecord::sole();
        $this->assertSame(['18:00:00'], $record->clock_out);
        $this->assertArrayNotHasKey('unapproved_overtime_minutes', $record->schedule_flags);
    }

    public function test_bulk_save_rolls_back_if_any_cell_is_on_approved_leave(): void
    {
        ScheduleLeaveRequest::create(['attendance_employee_id' => $this->employee->id, 'branch_id' => $this->branch->id, 'type' => 'sick', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-10', 'status' => 'approved']);
        $this->saveShift(overrides: ['cells' => [
            ['employee_id' => $this->employee->id, 'date' => '2026-11-09'],
            ['employee_id' => $this->employee->id, 'date' => '2026-11-10'],
        ]])->assertSessionHasErrors('cells');
        $this->assertDatabaseCount('schedule_shifts', 0);
    }

    public function test_delayed_offline_clock_out_replaces_the_auto_closed_missing_punch(): void
    {
        Storage::fake('uploads');
        $this->saveShift();
        $this->publish();
        $record = EmployeeAttendanceRecord::create(['attendance_employee_id' => $this->employee->id, 'branch_id' => $this->branch->id, 'work_date' => '2026-11-09', 'clock_in' => ['08:00:00'], 'clock_out' => []]);
        $this->travelTo(now()->addDay()->setTime(9, 0));
        $this->artisan('scheduling:maintain')->assertSuccessful();
        $this->assertTrue($record->fresh()->schedule_flags['missing_punch']);
        $this->withSession(['attendance_employee_id' => $this->employee->id])->postJson(route('attendance.sync-punch'), [
            'event_id' => (string) Str::uuid(), 'employee_id' => $this->employee->id, 'branch_id' => $this->branch->id,
            'action' => 'out', 'captured_at' => now()->subDay()->setTime(17, 20)->toIso8601String(),
            'face_image' => 'data:image/png;base64,'.base64_encode('test-photo'),
        ])->assertOk();
        $this->assertSame(['17:20:00'], $record->fresh()->clock_out);
        $this->assertArrayNotHasKey('missing_punch', $record->fresh()->schedule_flags);
        $this->assertArrayNotHasKey('unapproved_overtime_minutes', $record->fresh()->schedule_flags);
    }

    public function test_offline_sync_rejects_another_employee_and_stale_device_time(): void
    {
        $data = ['event_id' => (string) Str::uuid(), 'employee_id' => $this->employee->id + 99, 'branch_id' => $this->branch->id, 'action' => 'in', 'captured_at' => now()->toIso8601String(), 'face_image' => 'data:image/png;base64,'.base64_encode('test-photo')];
        $this->withSession(['attendance_employee_id' => $this->employee->id])->postJson(route('attendance.sync-punch'), $data)->assertForbidden();
        $data['employee_id'] = $this->employee->id;
        $data['captured_at'] = now()->subDays(3)->toIso8601String();
        $this->postJson(route('attendance.sync-punch'), $data)->assertUnprocessable();
        $this->assertDatabaseCount('employee_attendance_records', 0);
    }
}
