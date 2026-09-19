<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\Customer;
use App\Models\LaundryService;
use App\Models\LaundryServiceCategory;
use App\Models\PickupRequest;
use App\Models\ServicePreset;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use App\Support\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What staff now manage instead of it being fixed in code: the weight promises,
 * each branch's pickup windows, which category holds the add-ons, and the alert
 * that rings when a customer books.
 */
class BookingOperationsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private SystemSetting $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = SystemSetting::query()->create([
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

        $this->branch = Branch::query()->create(['name' => 'Kabankalan Main', 'code' => 'KBK', 'is_active' => true]);

        LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Regular Laundry',
            'pricing_type' => 'kilo',
            'price' => 30,
            'is_active' => true,
            'show_on_landing' => true,
        ]);
    }

    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'branch_name' => $this->branch->name,
            'branch_code' => $this->branch->code,
            'branch_type' => 'full_service',
            'business_name' => 'Cane & Cotton Laundry',
            'contact_number' => '09171234567',
            'business_address' => 'Kabankalan City',
            'currency' => 'PHP',
            'primary_color' => '#A07148',
        ], $overrides);
    }

    private function booking(array $attributes = []): PickupRequest
    {
        return PickupRequest::query()->create($attributes + [
            'reference_no' => 'PU-'.uniqid(),
            'branch_id' => $this->branch->id,
            'contact_name' => 'Juan Dela Cruz',
            'contact_phone' => '09171234567',
            'pickup_address' => 'Purok 3, Kabankalan City',
            'pickup_date' => now()->addDay()->toDateString(),
            'pickup_slot' => '08_09',
            'delivery_preference' => 'deliver',
            'status' => 'pending',
            'customer_id' => Customer::query()->firstOrCreate(
                ['phone' => '09171234567'],
                ['name' => 'Juan Dela Cruz', 'branch_id' => $this->branch->id, 'billing_type' => 'regular', 'unpaid_limit' => 0, 'is_active' => true]
            )->id,
        ]);
    }

    public function test_the_weight_lines_follow_settings_and_hide_when_blank(): void
    {
        $this->settings->update(['booking_minimum_kilos' => 6, 'free_delivery_minimum_kilos' => 7.5]);

        $this->get(route('landing'))
            ->assertOk()
            ->assertDontSee('Minimum 6 kg per pickup')
            ->assertSee('Pickup &amp; delivery available. Free for orders 7.5 kg and above.', false)
            ->assertDontSee('Minimum 5 kg');

        $this->settings->update(['booking_minimum_kilos' => null, 'free_delivery_minimum_kilos' => null]);

        $this->get(route('landing'))
            ->assertOk()
            ->assertDontSee('kg per pickup')
            ->assertDontSee('Free for orders', false);
    }

    public function test_a_branch_without_its_own_windows_keeps_the_original_five(): void
    {
        $this->assertSame([
            '08_09' => '8:00 AM - 9:00 AM',
            '09_10' => '9:00 AM - 10:00 AM',
            '10_11' => '10:00 AM - 11:00 AM',
            '11_12' => '11:00 AM - 12:00 PM',
            '13_14' => '1:00 PM - 2:00 PM',
        ], Booking::slots($this->branch->id));
    }

    public function test_staff_set_a_branchs_pickup_windows_and_bookings_follow_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'access' => ['settings']]);

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->settingsPayload([
                'pickup_windows' => [
                    ['start' => '10:30', 'end' => '12:00'],
                    ['start' => '08:00', 'end' => '09:30'],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame([
            '08_0930' => '8:00 AM - 9:30 AM',
            '1030_12' => '10:30 AM - 12:00 PM',
        ], Booking::slots($this->branch->id));

        // The booking form offers the branch's windows. Staff are sent to the
        // dashboard, so look as a customer would.
        auth('web')->logout();
        $this->get(route('landing'))->assertOk()->assertSee('10:30 AM - 12:00 PM');

        // A booking made under a window keeps its label after the window is gone.
        $this->assertSame('1:00 PM - 2:00 PM', $this->booking(['pickup_slot' => '13_14'])->pickupSlotLabel());
        $this->assertSame('8:00 AM - 9:30 AM', $this->booking(['pickup_slot' => '08_0930'])->pickupSlotLabel());
    }

    public function test_a_booking_cannot_pick_a_window_its_branch_does_not_run(): void
    {
        BranchSetting::query()->create(['branch_id' => $this->branch->id, 'pickup_windows' => [['start' => '08:00', 'end' => '09:00']]]);
        $service = LaundryService::query()->firstOrFail();

        $this->post(route('booking.store'), [
            'branch_id' => $this->branch->id,
            'items' => [['key' => 'service:'.$service->id, 'quantity' => 8]],
            'contact_name' => 'Juan Dela Cruz',
            'contact_phone' => '09171234567',
            'pickup_address' => 'Purok 3, Kabankalan City',
            'pickup_date' => now()->addDays(2)->toDateString(),
            'pickup_slot' => '13_14',
            'delivery_preference' => 'deliver',
            'payment_method' => 'cash',
        ])->assertSessionHasErrors('pickup_slot');
    }

    public function test_pickup_windows_must_end_by_two_and_cannot_overlap(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'access' => ['settings']]);

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->settingsPayload([
                'pickup_windows' => [['start' => '13:00', 'end' => '15:00']],
            ]))
            ->assertSessionHasErrors('pickup_windows.0.end');

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->settingsPayload([
                'pickup_windows' => [['start' => '10:00', 'end' => '09:00']],
            ]))
            ->assertSessionHasErrors('pickup_windows.0.end');

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->settingsPayload([
                'pickup_windows' => [['start' => '08:00', 'end' => '10:00'], ['start' => '09:00', 'end' => '11:00']],
            ]))
            ->assertSessionHasErrors('pickup_windows');

        $this->assertNull(BranchSetting::query()->where('branch_id', $this->branch->id)->value('pickup_windows'));

        // The settings page itself renders the editor.
        $this->actingAs($admin)
            ->get(route('admin.settings.edit', ['branch_id' => $this->branch->id]))
            ->assertOk()
            ->assertSee('Pickup &amp; Delivery Windows', false);
    }

    public function test_renaming_the_add_ons_category_keeps_its_items_as_add_ons(): void
    {
        $category = LaundryServiceCategory::query()->create(['name' => 'Add-ons', 'visibility' => 'all', 'is_addon' => true, 'is_active' => true]);
        $detergent = LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'service_category_id' => $category->id,
            'name' => 'Ariel',
            'pricing_type' => 'custom',
            'price' => 20,
            'is_active' => true,
            'show_on_landing' => true,
        ]);

        $category->update(['name' => 'Extras']);

        $this->assertContains('service:'.$detergent->id, Booking::addonOfferings()->pluck('key'));
        $this->assertNotContains('service:'.$detergent->id, Booking::offerings()->pluck('key'));
    }

    public function test_the_category_form_saves_the_add_ons_flag(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'access' => ['service_categories']]);

        $this->actingAs($admin)
            ->post(route('admin.service-categories.store'), ['name' => 'Extras', 'visibility' => 'all', 'is_addon' => 1])
            ->assertSessionHasNoErrors();

        $this->assertTrue(LaundryServiceCategory::query()->where('name', 'Extras')->value('is_addon'));
    }

    public function test_the_feed_reports_new_bookings_for_the_staff_members_own_branch(): void
    {
        $other = Branch::query()->create(['name' => 'Himamaylan', 'code' => 'HIM', 'is_active' => true]);
        $cashier = User::factory()->create(['role' => 'cashier', 'branch_id' => $this->branch->id, 'access' => ['pickup_requests']]);

        $seen = $this->booking();
        $this->actingAs($cashier)
            ->getJson(route('admin.pickup-requests.feed'))
            ->assertOk()
            ->assertJson(['latest_id' => $seen->id, 'pending' => 1, 'bookings' => []]);

        $mine = $this->booking(['contact_name' => 'New Customer']);
        $this->booking(['branch_id' => $other->id, 'contact_name' => 'Other Branch']);
        $this->booking(['status' => 'cancelled', 'contact_name' => 'Changed Mind']);

        $this->actingAs($cashier)
            ->getJson(route('admin.pickup-requests.feed', ['after' => $seen->id]))
            ->assertOk()
            ->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.id', $mine->id)
            ->assertJsonPath('bookings.0.contact_name', 'New Customer')
            ->assertJsonPath('pending', 2);

        // Staff without the Pickup Bookings module neither poll nor see the bell.
        $clerk = User::factory()->create(['role' => 'cashier', 'branch_id' => $this->branch->id, 'access' => ['dashboard']]);
        $this->actingAs($clerk)->getJson(route('admin.pickup-requests.feed'))->assertForbidden();
    }

    public function test_the_pos_refuses_a_preset_that_includes_an_inactive_service(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'access' => ['job_orders']]);
        $this->branch->update(['branch_type' => 'full_service']);
        $customer = Customer::query()->create(['branch_id' => $this->branch->id, 'name' => 'Walk-in', 'billing_type' => 'regular', 'unpaid_limit' => 0, 'is_active' => true]);
        $wash = LaundryService::query()->create(['branch_id' => $this->branch->id, 'name' => 'Wash', 'pricing_type' => 'load', 'price' => 80, 'is_active' => true]);
        $dry = LaundryService::query()->create(['branch_id' => $this->branch->id, 'name' => 'Dry', 'pricing_type' => 'load', 'price' => 70, 'is_active' => false]);
        $preset = ServicePreset::query()->create(['branch_id' => $this->branch->id, 'name' => 'Wash Dry Set', 'is_active' => true]);
        $preset->items()->create(['laundry_service_id' => $wash->id, 'quantity' => 1]);
        $preset->items()->create(['laundry_service_id' => $dry->id, 'quantity' => 1]);

        $this->actingAs($admin)
            ->post(route('admin.job-orders.store'), [
                'branch_id' => $this->branch->id,
                'processing_branch_id' => $this->branch->id,
                'customer_id' => $customer->id,
                'items' => [['service_preset_id' => $preset->id, 'description' => $preset->name, 'quantity' => 1, 'unit_price' => 150]],
                'discount' => 0,
                'paid_amount' => 0,
                'payment_type' => 'unpaid',
                'transaction_type' => 'walk_in',
            ])
            ->assertSessionHasErrors('items');

        // Nor is it offered in the POS catalog.
        $this->actingAs($admin)
            ->get(route('admin.job-orders.create', ['branch_id' => $this->branch->id]))
            ->assertOk()
            ->assertViewHas('servicePresets', fn ($presets) => collect($presets)->where('name', 'Wash Dry Set')->isEmpty());
    }
}
