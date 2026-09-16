<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LaundryService;
use App\Models\LaundryServiceCategory;
use App\Models\ServicePreset;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaundryServiceManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::query()->create([
            'business_name' => 'Spin Klean Laundry',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'primary_color' => '#2E7D32',
            'is_completed' => true,
        ]);

        $this->admin = User::factory()->create(['role' => 'admin', 'access' => ['services']]);
        $this->branch = Branch::query()->create(['name' => 'Branch A', 'code' => 'A', 'is_active' => true]);
    }

    private function service(array $attributes = []): LaundryService
    {
        return LaundryService::query()->create($attributes + [
            'branch_id' => $this->branch->id,
            'name' => 'Wash',
            'pricing_type' => 'load',
            'price' => 80,
            'is_active' => true,
        ]);
    }

    public function test_a_failed_save_reopens_its_form_with_the_errors(): void
    {
        $this->service();
        $indexUrl = route('admin.services.index', ['branch_id' => $this->branch->id]);

        $this->actingAs($this->admin)
            ->from($indexUrl)
            ->post(route('admin.services.store'), [
                '_form' => 'service-create',
                'branch_id' => $this->branch->id,
                'name' => 'Wash',
                'pricing_type' => 'load',
                'price' => 90,
            ])
            ->assertRedirect($indexUrl)
            ->assertSessionHasErrors('name');

        $this->actingAs($this->admin)
            ->get($indexUrl)
            ->assertOk()
            ->assertSee('createOpen: true', false)
            ->assertSee('The name has already been taken.');

        $this->assertSame(1, LaundryService::query()->count());
    }

    public function test_service_names_are_unique_per_branch_only(): void
    {
        $other = Branch::query()->create(['name' => 'Branch B', 'code' => 'B', 'is_active' => true]);
        $this->service();
        $this->service(['name' => 'Dry'])->delete();

        $payload = ['pricing_type' => 'load', 'price' => 70];

        // Same name in another branch, and reusing a deleted service's name, are both fine.
        $this->actingAs($this->admin)
            ->post(route('admin.services.store'), $payload + ['branch_id' => $other->id, 'name' => 'Wash'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)
            ->post(route('admin.services.store'), $payload + ['branch_id' => $this->branch->id, 'name' => 'Dry'])
            ->assertSessionHasNoErrors();

        // Saving a service under its own name is not a duplicate.
        $wash = LaundryService::query()->where('branch_id', $this->branch->id)->where('name', 'Wash')->first();
        $this->actingAs($this->admin)
            ->put(route('admin.services.update', $wash), $payload + ['name' => 'Wash', 'is_active' => 1])
            ->assertSessionHasNoErrors();
    }

    public function test_updating_a_service_never_moves_it_to_another_branch(): void
    {
        $other = Branch::query()->create(['name' => 'Branch B', 'code' => 'B', 'is_active' => true]);
        $wash = $this->service();

        $this->actingAs($this->admin)
            ->put(route('admin.services.update', $wash), [
                'branch_id' => $other->id,
                'name' => 'Wash',
                'pricing_type' => 'load',
                'price' => 85,
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->branch->id, $wash->refresh()->branch_id);
        $this->assertSame('85.00', $wash->price);
    }

    public function test_a_load_service_keeps_its_load_size_and_other_types_drop_it(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.services.store'), [
                'branch_id' => $this->branch->id,
                'name' => 'Bed Sheets and Towels',
                'pricing_type' => 'load',
                'price' => 350,
                'kilos_per_load' => 8,
            ])
            ->assertSessionHasNoErrors();

        $linens = LaundryService::query()->where('name', 'Bed Sheets and Towels')->firstOrFail();
        $this->assertSame(8.0, $linens->kilosPerLoad());

        $this->actingAs($this->admin)
            ->get(route('admin.services.index', ['branch_id' => $this->branch->id]))
            ->assertSee('Max 8 kg per load');

        // Switched to per kilo, the load size is cleared rather than left to
        // come back unnoticed.
        $this->actingAs($this->admin)
            ->put(route('admin.services.update', $linens), [
                'name' => 'Bed Sheets and Towels',
                'pricing_type' => 'kilo',
                'price' => 40,
                'kilos_per_load' => 8,
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($linens->refresh()->kilos_per_load);

        // Left blank on a load service, a load holds 10 kg.
        $this->assertSame(10.0, $this->service(['name' => 'Wash Only'])->kilosPerLoad());
    }

    public function test_a_service_inside_a_preset_cannot_be_deleted(): void
    {
        $wash = $this->service();
        $preset = ServicePreset::query()->create(['branch_id' => $this->branch->id, 'name' => 'Full Service', 'is_active' => true]);
        $preset->items()->create(['laundry_service_id' => $wash->id, 'quantity' => 1]);

        $this->actingAs($this->admin)
            ->delete(route('admin.services.destroy', $wash))
            ->assertRedirect(route('admin.services.index', ['branch_id' => $this->branch->id]))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($wash);

        $preset->delete();

        $this->actingAs($this->admin)
            ->delete(route('admin.services.destroy', $wash))
            ->assertSessionHas('success');

        $this->assertSoftDeleted($wash);
    }

    public function test_another_branchs_private_category_is_rejected(): void
    {
        $other = Branch::query()->create(['name' => 'Branch B', 'code' => 'B', 'is_active' => true]);
        $private = LaundryServiceCategory::query()->create(['name' => 'B only', 'visibility' => 'branch', 'branch_id' => $other->id, 'is_active' => true]);

        $this->actingAs($this->admin)
            ->post(route('admin.services.store'), [
                'branch_id' => $this->branch->id,
                'service_category_id' => $private->id,
                'name' => 'Wash',
                'pricing_type' => 'load',
                'price' => 80,
            ])
            ->assertSessionHasErrors('service_category_id');

        $this->actingAs($this->admin)
            ->get(route('admin.services.index', ['branch_id' => $this->branch->id]))
            ->assertDontSee('B only');
    }

    public function test_a_preset_with_an_inactive_service_is_flagged_and_kept_off_the_landing_page(): void
    {
        $wash = $this->service(['show_on_landing' => true]);
        $fold = $this->service(['name' => 'Fold', 'price' => 30]);
        $preset = ServicePreset::query()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Full Service',
            'is_active' => true,
            'show_on_landing' => true,
        ]);
        $preset->items()->create(['laundry_service_id' => $wash->id, 'quantity' => 1]);
        $preset->items()->create(['laundry_service_id' => $fold->id, 'quantity' => 1]);

        $this->assertContains('preset:'.$preset->id, Booking::offeringKeys());

        $fold->update(['is_active' => false]);

        $this->assertNotContains('preset:'.$preset->id, Booking::offeringKeys());

        // The admin sees why, and the inactive service stays in the preset form
        // so saving the preset does not silently drop it.
        $this->actingAs($this->admin)
            ->get(route('admin.services.index', ['branch_id' => $this->branch->id]))
            ->assertOk()
            ->assertSee('Includes Fold, which is inactive or removed.')
            ->assertSee('name="items['.$fold->id.']"', false);
    }
}
