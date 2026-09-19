<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\BusinessDefaults;
use App\Support\Menu;
use Database\Seeders\BusinessSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessSettingsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_business_contact_and_main_branch_machine_count(): void
    {
        $this->seed(BusinessSettingsSeeder::class);

        $settings = SystemSetting::current();
        $main = Branch::query()->where('code', 'MAIN')->firstOrFail();

        $this->assertSame(BusinessDefaults::ADDRESS, $settings->business_address);
        $this->assertSame(BusinessDefaults::CONTACT_NUMBER, $settings->contact_number);
        $this->assertSame(BusinessDefaults::EMAIL, $settings->business_email);
        $this->assertSame(BusinessDefaults::FACEBOOK_URL, $settings->facebook_url);
        $this->assertTrue($settings->isComplete());
        $this->assertTrue($settings->is_completed);
        $this->assertSame(BusinessDefaults::ADDRESS, $main->address);
        $this->assertSame(BusinessDefaults::CONTACT_NUMBER, $main->contact_number);
        $this->assertSame(5, $main->machine_count);

        $admin = User::factory()->create(['role' => 'admin', 'branch_id' => $main->id, 'access' => Menu::keys()]);
        $this->actingAs($admin)
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('Business setup completed.')
            ->assertDontSee('Business setup is incomplete.');

        $this->seed(BusinessSettingsSeeder::class);
        $this->assertSame(1, Branch::query()->where('code', 'MAIN')->count());
        $this->assertSame(1, SystemSetting::query()->count());
    }

    public function test_it_completes_an_existing_unfinished_settings_record(): void
    {
        $settings = SystemSetting::current();
        $settings->update([
            'currency' => '',
            'job_order_prefix' => '',
            'invoice_prefix' => '',
            'is_completed' => false,
        ]);

        $this->seed(BusinessSettingsSeeder::class);

        $settings->refresh();
        $this->assertSame('PHP', $settings->currency);
        $this->assertSame('JO', $settings->job_order_prefix);
        $this->assertSame('INV', $settings->invoice_prefix);
        $this->assertTrue($settings->is_completed);
    }

    public function test_new_full_service_branches_default_to_five_machines(): void
    {
        $this->seed(BusinessSettingsSeeder::class);
        SystemSetting::current()->update(['is_completed' => true]);
        $admin = User::factory()->create(['role' => 'super_admin', 'access' => Menu::keys()]);

        $this->actingAs($admin)
            ->post(route('admin.branches.store'), [
                'name' => 'New Full Service Branch',
                'code' => 'NEW',
                'branch_type' => 'full_service',
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(5, Branch::query()->where('code', 'NEW')->firstOrFail()->machine_count);

        $this->actingAs($admin)
            ->post(route('admin.branches.store'), [
                'name' => 'Pickup Desk',
                'code' => 'DESK',
                'branch_type' => 'pickup_dropoff',
                'machine_count' => 5,
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Branch::query()->where('code', 'DESK')->firstOrFail()->machine_count);
    }
}
