<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerRequiredDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::create([
            'business_name' => 'Laundry', 'contact_number' => '09171234567',
            'business_address' => 'Manila', 'currency' => 'PHP',
            'job_order_prefix' => 'JO', 'invoice_prefix' => 'INV',
            'primary_color' => '#2E7D32', 'is_completed' => true,
        ]);
        $branch = Branch::create(['name' => 'Branch A', 'code' => 'A', 'is_active' => true]);
        $this->actingAs(User::factory()->create([
            'role' => 'branch_manager', 'branch_id' => $branch->id,
            'access' => ['customers', 'job_orders'],
        ]));
    }

    public static function incompleteCustomers(): array
    {
        $cases = [];
        foreach (['admin.customers.store', 'admin.job-orders.customers.store'] as $route) {
            foreach (['name', 'phone', 'address'] as $field) {
                foreach (['missing', 'empty', 'whitespace'] as $kind) {
                    $cases[$route.' '.$field.' '.$kind] = [$route, $field, $kind];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('incompleteCustomers')]
    public function test_customer_cannot_be_created_without_each_required_detail(string $route, string $field, string $kind): void
    {
        $data = ['name' => 'Juan Cruz', 'phone' => '09171234567', 'address' => '123 Main Street'];
        if ($kind === 'missing') {
            unset($data[$field]);
        } else {
            $data[$field] = $kind === 'empty' ? '' : '   ';
        }
        $this->postJson(route($route), $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_customers_and_pos_accept_complete_details_without_email(): void
    {
        foreach (['admin.customers.store', 'admin.job-orders.customers.store'] as $index => $route) {
            $this->post(route($route), [
                'name' => 'Customer '.$index, 'phone' => '09171234567',
                'address' => '123 Main Street', 'redirect_to' => $index ? 'pos' : null,
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseHas('customers', [
                'name' => 'Customer '.$index, 'phone' => '09171234567',
                'address' => '123 Main Street', 'email' => null,
            ]);
        }
    }

    public function test_edit_cannot_clear_required_customer_details(): void
    {
        $customer = Customer::create([
            'branch_id' => auth()->user()->branch_id, 'name' => 'Juan Cruz',
            'phone' => '09171234567', 'address' => '123 Main Street', 'is_active' => true,
        ]);
        $this->putJson(route('admin.customers.update', $customer), [
            'name' => 'Juan Cruz', 'phone' => '', 'address' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors(['phone', 'address']);
        $this->assertSame('09171234567', $customer->fresh()->phone);
        $this->assertSame('123 Main Street', $customer->fresh()->address);
    }
    public function test_validation_reopens_the_customer_form_and_does_not_replace_other_customer_details(): void
    {
        $customer = Customer::create(['branch_id' => auth()->user()->branch_id, 'name' => 'Original Customer', 'phone' => '09171234567', 'address' => 'Original Address', 'is_active' => true]);
        $this->from(route('admin.customers.index'))->post(route('admin.customers.store'), [
            '_customer_form' => 'create', 'name' => 'Attempted Customer', 'phone' => '', 'address' => '',
        ])->assertSessionHasErrors(['phone', 'address']);
        $response = $this->get(route('admin.customers.index'))->assertOk()->assertSee('createOpen: true', false);
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame('Attempted Customer', $xpath->query('//*[@id="customer-new-name"]')->item(0)->getAttribute('value'));
        $this->assertSame('Original Customer', $xpath->query('//*[@id="customer-'.$customer->id.'-name"]')->item(0)->getAttribute('value'));
        $this->from(route('admin.customers.index'))->put(route('admin.customers.update', $customer), [
            '_customer_form' => (string) $customer->id, 'name' => 'Edited Customer', 'phone' => '', 'address' => '',
        ])->assertSessionHasErrors(['phone', 'address']);
        $this->get(route('admin.customers.index'))->assertOk()->assertSee('editOpen: '.$customer->id, false);
    }

}
