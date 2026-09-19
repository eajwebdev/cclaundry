<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_old_customer_url_leads_to_the_one_login_page(): void
    {
        $this->get(route('customer.login'))->assertRedirect(route('login', ['as' => 'customer']));
        $this->get(route('customer.bookings.index'))->assertRedirect(route('login', ['as' => 'customer']));

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('action="'.route('login.submit').'"', false)
            ->assertSee('Customer')
            ->assertSee('Staff')
            ->assertSee('name="account_type"', false);
    }

    public function test_customer_and_staff_credentials_use_their_own_guards(): void
    {
        $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);
        $otherBranch = Branch::query()->create(['name' => 'Second', 'code' => 'SECOND', 'is_active' => true]);
        $customer = Customer::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Juan Customer',
            'phone' => '09171234567',
            'email' => 'shared@example.com',
            'password' => 'customer-secret',
            'is_active' => true,
        ]);
        $staff = User::factory()->create([
            'username' => 'juan-staff',
            'email' => 'shared@example.com',
            'password' => 'staff-secret',
            'branch_id' => $branch->id,
        ]);

        $this->post(route('login.submit'), [
            'account_type' => 'customer',
            'login' => '+63 917 123 4567',
            'password' => 'customer-secret',
        ])->assertRedirect(route('customer.bookings.index'));
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertGuest('web');

        $this->post(route('customer.logout'));

        $this->post(route('login.submit'), [
            'account_type' => 'staff',
            'login' => 'juan-staff',
            'password' => 'staff-secret',
            'branch_id' => $otherBranch->id,
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($staff, 'web');
        $this->assertGuest('customer');
        $this->assertSame($otherBranch->id, $staff->fresh()->branch_id);
    }

    public function test_the_account_choice_prevents_cross_sign_in_even_with_a_shared_email(): void
    {
        $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);
        Customer::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Juan Customer',
            'phone' => '09171234567',
            'email' => 'shared@example.com',
            'password' => 'customer-secret',
            'is_active' => true,
        ]);
        User::factory()->create([
            'email' => 'shared@example.com',
            'password' => 'staff-secret',
        ]);

        $this->post(route('login.submit'), [
            'account_type' => 'customer',
            'login' => 'shared@example.com',
            'password' => 'staff-secret',
        ])->assertSessionHasErrors('login');

        $this->post(route('login.submit'), [
            'account_type' => 'staff',
            'login' => 'shared@example.com',
            'password' => 'customer-secret',
        ])->assertSessionHasErrors('login');

        $this->assertGuest('web');
        $this->assertGuest('customer');
    }

    public function test_older_posts_still_reach_the_correct_login(): void
    {
        $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);
        $customer = Customer::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Juan Customer',
            'phone' => '09171234567',
            'password' => 'customer-secret',
            'is_active' => true,
        ]);

        $this->post(route('customer.login.submit'), [
            'login' => '09171234567',
            'password' => 'customer-secret',
        ])->assertRedirect(route('customer.bookings.index'));
        $this->assertAuthenticatedAs($customer, 'customer');
    }
}
