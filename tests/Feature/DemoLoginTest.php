<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_login_is_hidden_and_refused_when_the_flag_is_off(): void
    {
        config(['app.demo' => false]);

        $this->get(route('login'))->assertOk()->assertDontSee('Demo login');
        $this->post(route('login.demo'))->assertNotFound();
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['username' => 'demo']);
    }

    public function test_demo_login_signs_in_as_one_demo_super_admin_when_the_flag_is_on(): void
    {
        config(['app.demo' => true]);

        $this->get(route('login'))->assertOk()->assertSee('Demo login');

        $this->post(route('login.demo'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertTrue(auth()->user()->isSuperAdmin());
        $this->assertSame('demo', auth()->user()->username);

        // A second use reuses the same account.
        auth()->logout();
        $this->post(route('login.demo'))->assertRedirect(route('dashboard'));
        $this->assertSame(1, User::where('username', 'demo')->count());
    }

    public function test_demo_login_is_refused_from_a_public_address_even_with_the_flag_on(): void
    {
        config(['app.demo' => true]);

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->post(route('login.demo'))->assertNotFound();
        $this->assertGuest();

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])->post(route('login.demo'))->assertRedirect(route('dashboard'));
    }

    public function test_the_demo_account_has_no_usable_password(): void
    {
        config(['app.demo' => true]);
        $this->post(route('login.demo'));
        auth()->logout();

        foreach (['demo', 'password', ''] as $guess) {
            $this->post(route('login.submit'), ['login' => 'demo', 'password' => $guess]);
            $this->assertGuest();
        }
    }
}
