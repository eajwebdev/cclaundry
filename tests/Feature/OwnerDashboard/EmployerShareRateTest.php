<?php

namespace Tests\Feature\OwnerDashboard;

use App\Models\AttendanceEmployee;
use App\Models\Branch;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployerShareRateTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::create(['business_name' => 'Laundry', 'currency' => 'PHP', 'is_completed' => true]);
        $this->branch = Branch::create(['name' => 'Guanzon', 'code' => 'G', 'is_active' => true]);
        $this->employee = AttendanceEmployee::create(['branch_id' => $this->branch->id, 'first_name' => 'Staff', 'last_name' => 'A', 'username' => 'staff-a', 'password' => bcrypt('123456'), 'status' => 'active']);
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
    }

    private function save(array $extra = [])
    {
        return $this->put(route('admin.scheduling.employee', $this->employee), array_merge(['branch_id' => $this->branch->id, 'schedule_role' => 'rider'], $extra));
    }

    public function test_admin_can_set_employer_share_rate(): void
    {
        $this->assertEquals('14.50', $this->employee->fresh()->employer_share_rate);

        $this->save(['employer_share_rate' => 12.5])->assertSessionHasNoErrors();
        $this->assertSame('12.50', $this->employee->fresh()->employer_share_rate);

        $this->save(['employer_share_rate' => 101])->assertSessionHasErrors('employer_share_rate');
        $this->assertSame('12.50', $this->employee->fresh()->employer_share_rate);
    }

    public function test_omitted_rate_keeps_the_stored_value(): void
    {
        $this->employee->update(['employer_share_rate' => 12.5]);
        $this->save()->assertSessionHasNoErrors();
        $this->assertSame('12.50', $this->employee->fresh()->employer_share_rate);
    }

    public function test_negative_rate_is_rejected_and_zero_is_stored(): void
    {
        $this->save(['employer_share_rate' => -1])->assertSessionHasErrors('employer_share_rate');
        $this->save(['employer_share_rate' => 0])->assertSessionHasNoErrors();
        $this->assertSame('0.00', $this->employee->fresh()->employer_share_rate);
    }

    public function test_non_super_admin_cannot_change_the_rate(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->save(['employer_share_rate' => 5]);
        $this->assertSame('14.50', $this->employee->fresh()->employer_share_rate);
    }
}
