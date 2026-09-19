<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SystemSetting;
use App\Support\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function showRegister(Request $request)
    {
        return view('customer.register', [
            'settings' => SystemSetting::current(),
            'branches' => Booking::branches(),
            // Someone who just booked as a guest is offered an account with
            // their details already filled in. The booking itself is placed
            // either way, so this is a convenience and never a gate.
            'recentBooking' => $request->session()->get(Booking::RECENT_SESSION_KEY),
        ]);
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'password' => ['required', 'confirmed', Password::min(8)],
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => 'Please accept the service terms to continue.',
            'branch_id.exists' => 'Please choose one of our active branches.',
        ]);

        $phone = Customer::normalizePhone($validated['phone']);

        if ($phone === '') {
            return back()->withInput()->withErrors(['phone' => 'Please enter a valid mobile number.']);
        }

        $existing = Customer::query()->matchingPhone($phone)->first();

        // A number already tied to a portal account cannot be registered twice.
        if ($existing && $existing->hasPortalAccount()) {
            return back()
                ->withInput()
                ->withErrors(['phone' => 'That mobile number already has an account. Please sign in instead.']);
        }

        $customer = DB::transaction(function () use ($existing, $validated, $phone) {
            if ($existing) {
                // A walk-in record created at the counter. Claiming it keeps the
                // person's existing order history attached to their new login.
                $existing->forceFill([
                    'name' => $existing->name ?: $validated['name'],
                    'phone' => $phone,
                    'email' => ($validated['email'] ?? null) ?: $existing->email,
                    'address' => ($validated['address'] ?? null) ?: $existing->address,
                    'password' => Hash::make($validated['password']),
                    'registered_at' => now(),
                    'is_active' => true,
                ])->save();

                return $existing;
            }

            return Customer::create([
                'branch_id' => $validated['branch_id'],
                'name' => $validated['name'],
                'phone' => $phone,
                'email' => $validated['email'] ?? null,
                'address' => $validated['address'] ?? null,
                'billing_type' => 'regular',
                'is_active' => true,
                'password' => $validated['password'],
                'registered_at' => now(),
            ]);
        });

        Auth::guard('customer')->login($customer, true);
        $request->session()->regenerate();

        $customer->forceFill(['last_login_at' => now()])->save();

        return $this->afterAuthentication(
            $request,
            $customer,
            $existing
                ? 'Welcome back. We linked your account to your existing laundry history.'
                : 'Your account is ready. Welcome to '.(SystemSetting::current()->business_name ?: config('app.name')).'.'
        );
    }

    public function logout(Request $request)
    {
        Auth::guard('customer')->logout();

        // Only the customer half of the session is discarded; a staff session in
        // the same browser is left alone.
        $request->session()->forget(Booking::RECENT_SESSION_KEY);
        $request->session()->regenerateToken();

        return redirect()->route('landing')->with('success', 'You have been signed out.');
    }

    /**
     * Shared landing spot after register/login. Bookings made as a guest are
     * already on the account by this point: they hang off the customer record
     * this mobile number resolved to, which registering has just claimed.
     */
    private function afterAuthentication(Request $request, Customer $customer, string $message)
    {
        return redirect()->route('customer.bookings.index')->with('success', $message);
    }
}
