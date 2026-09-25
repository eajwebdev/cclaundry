<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderAccessRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::query()->create([
            'business_name' => 'Cane & Cotton Laundry',
            'contact_number' => '09171234567',
            'business_address' => 'Kabankalan City',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'primary_color' => '#A07148',
            'is_completed' => true,
        ]);

        SystemTrialSetting::query()->create([
            'trial_enabled' => true,
            'trial_start_date' => now()->subDay()->toDateString(),
            'trial_end_date' => now()->addDay()->toDateString(),
            'trial_status' => 'active',
            'grace_period_days' => 0,
        ]);

        $this->branch = Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
    }

    public function test_rider_cannot_access_dashboard_or_admin_pages(): void
    {
        $rider = User::factory()->create([
            'role' => 'rider',
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'access' => [],
        ]);

        // Rider blocked from dashboard
        $this->actingAs($rider)
            ->get(route('dashboard'))
            ->assertForbidden();

        $this->actingAs($rider)
            ->getJson(route('dashboard.data'))
            ->assertForbidden();

        // Rider blocked from admin routes
        $this->actingAs($rider)
            ->get(route('admin.customers.index'))
            ->assertForbidden();

        $this->actingAs($rider)
            ->get(route('admin.job-orders.index'))
            ->assertForbidden();

        $this->actingAs($rider)
            ->get(route('admin.settings.edit'))
            ->assertForbidden();

        $this->actingAs($rider)
            ->get(route('admin.users.index'))
            ->assertForbidden();

        // Rider has no accessible menu items
        $this->assertEmpty($rider->accessibleMenuItems());
        $this->assertFalse($rider->hasMenuAccess('dashboard'));
        $this->assertFalse($rider->hasMenuAccess('job_orders'));
    }

    public function test_rider_can_still_access_rider_console(): void
    {
        $rider = User::factory()->create([
            'role' => 'rider',
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'access' => [],
        ]);

        $this->actingAs($rider)
            ->get(route('rider.index'))
            ->assertOk();

        $this->actingAs($rider)
            ->getJson(route('rider.runs'))
            ->assertOk();
    }

    public function test_admin_can_access_dashboard_and_admin_pages(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'access' => ['dashboard', 'customers', 'settings'],
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.customers.index'))
            ->assertOk();
    }
}
