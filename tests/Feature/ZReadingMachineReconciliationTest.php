<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CycleRecord;
use App\Models\JobOrder;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\ZReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZReadingMachineReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_physical_counter_cycles_are_reconciled_with_job_order_and_non_job_cycles(): void
    {
        [$admin, $branch, $order] = $this->setupOperations();

        foreach (range(1, 2) as $cycleNumber) {
            CycleRecord::query()->create([
                'job_order_id' => $order->id,
                'user_id' => $admin->id,
                'cycle_type' => 'wash',
                'machine_number' => 1,
                'cycle_number' => $cycleNumber,
                'started_at' => now(),
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.z-readings.create', [
                'branch_id' => $branch->id,
                'business_date' => today()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Non-job Machine Cycle Reconciliation')
            ->assertSee('Tub cleaning')
            ->assertSee('Accidental start')
            ->assertSee('Testing / maintenance')
            ->assertSee('Actual Ending Wash 1', false);

        $payload = $this->payload($branch->id, [
            'wash' => [
                'beginning' => 100,
                'ending' => 105,
                'non_job_cycles' => [
                    'tub_cleaning' => 1,
                    'accidental_start' => 1,
                ],
                'notes' => 'Morning tub clean and one accidental start.',
            ],
            'dry' => [
                'beginning' => 200,
                'ending' => 200,
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.z-readings.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $reading = ZReading::query()->firstOrFail();
        $wash = $reading->machine_counters[1]['wash'];
        $this->assertSame(5, $wash['total']);
        $this->assertSame(2, $wash['system_cycles']);
        $this->assertSame(2, $wash['non_job_total']);
        $this->assertSame(1, $wash['unexplained_cycles']);
        $this->assertSame('mismatch', $wash['reconciliation_status']);

        $this->actingAs($admin)
            ->get(route('admin.z-readings.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertSee('1 unexplained')
            ->assertSee('2 documented non-job');

        $payload['machine_counters'][1]['wash']['non_job_cycles']['testing_maintenance'] = 1;
        $this->actingAs($admin)
            ->post(route('admin.z-readings.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $reading->refresh();
        $this->assertSame(3, $reading->machine_counters[1]['wash']['non_job_total']);
        $this->assertSame(0, $reading->machine_counters[1]['wash']['unexplained_cycles']);
        $this->assertSame('matched', $reading->machine_counters[1]['wash']['reconciliation_status']);
    }

    public function test_invalid_machine_counter_reconciliation_is_rejected(): void
    {
        [$admin, $branch] = $this->setupOperations();

        $tooManyReasons = $this->payload($branch->id, [
            'wash' => [
                'beginning' => 100,
                'ending' => 101,
                'non_job_cycles' => ['tub_cleaning' => 2],
            ],
            'dry' => ['beginning' => 200, 'ending' => 200],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.z-readings.store'), $tooManyReasons)
            ->assertSessionHasErrors('machine_counters.1.wash.non_job_cycles');

        $otherWithoutNotes = $this->payload($branch->id, [
            'wash' => [
                'beginning' => 100,
                'ending' => 101,
                'non_job_cycles' => ['other' => 1],
            ],
            'dry' => ['beginning' => 200, 'ending' => 200],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.z-readings.store'), $otherWithoutNotes)
            ->assertSessionHasErrors('machine_counters.1.wash.notes');

        $endingBelowBeginning = $this->payload($branch->id, [
            'wash' => ['beginning' => 100, 'ending' => 99],
            'dry' => ['beginning' => 200, 'ending' => 200],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.z-readings.store'), $endingBelowBeginning)
            ->assertSessionHasErrors('machine_counters.1.wash.ending');

        $this->assertDatabaseCount('z_readings', 0);
    }

    private function payload(int $branchId, array $machine): array
    {
        return [
            'branch_id' => $branchId,
            'business_date' => today()->toDateString(),
            'actual_gcash_amount' => 0,
            'actual_bank_amount' => 0,
            'machine_counters' => [1 => $machine],
        ];
    }

    private function setupOperations(): array
    {
        SystemSetting::query()->create([
            'business_name' => 'Cane & Cotton Laundry',
            'contact_number' => '09171234567',
            'business_address' => 'Manila',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'primary_color' => '#A07148',
            'is_completed' => true,
        ]);

        $admin = User::factory()->create(['role' => 'super_admin', 'access' => ['z_readings']]);
        $branch = Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'machine_count' => 1,
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Machine Customer',
            'billing_type' => 'regular',
            'is_active' => true,
        ]);
        $order = JobOrder::query()->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $admin->id,
            'job_order_number' => 'JO-MACHINE-001',
            'status' => 'washing',
            'subtotal' => 200,
            'total' => 200,
            'paid_amount' => 200,
            'balance' => 0,
        ]);

        return [$admin, $branch, $order];
    }
}
