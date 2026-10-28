<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\Branch;
use App\Models\ScheduleShift;
use Database\Seeders\StaffSchedulingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffSchedulingDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeding_is_repeatable_and_preserves_edited_shifts(): void
    {
        Branch::create(['name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);
        $this->seed(StaffSchedulingDemoSeeder::class);
        $this->assertDatabaseCount('attendance_employees', 4);
        $this->assertDatabaseCount('schedule_shifts', 56);
        $this->assertDatabaseCount('schedule_leave_requests', 1);
        $this->assertEquals(4, AttendanceEmployee::where('first_name', 'like', 'Demo %')->count());
        $shift = ScheduleShift::firstOrFail();
        $shift->update(['note' => 'User edited this sample']);
        $this->seed(StaffSchedulingDemoSeeder::class);
        $this->assertDatabaseCount('attendance_employees', 4);
        $this->assertDatabaseCount('schedule_shifts', 56);
        $this->assertDatabaseCount('schedule_leave_requests', 1);
        $this->assertSame('User edited this sample', $shift->fresh()->note);
    }
}
