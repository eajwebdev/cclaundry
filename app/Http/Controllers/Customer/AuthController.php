<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SystemSetting;
use App\Models\User;
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
        $pending = $request->session()->get(Booking::PENDING_SESSION_KEY);

        return view('customer.register', [
            'settings' => SystemSetting::current(),
            'branches' => Booking::branches(),
            'pendingBooking' => $pending,
            'serviceTypes' => Booking::serviceTypes(),
            'slots' => Booking::slots(),
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

    public function showLogin(Request $request)
    {
        return view('customer.login', [
            'settings' => SystemSetting::current(),
            'hasPendingBooking' => $request->session()->has(Booking::PENDING_SESSION_KEY),
        ]);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string'],
        ]);

        $login = trim($validated['login']);

        // Customers sign in with the mobile number they book with, or with an
        // email if they gave us one.
        $customer = filter_var($login, FILTER_VALIDATE_EMAIL)
            ? Customer::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($login)])->first()
            : Customer::query()->matchingPhone($login)->first();

        if (! $customer || ! $customer->hasPortalAccount() || ! Hash::check($validated['password'], $customer->password)) {
            // Staff who land on the customer portal by mistake get pointed at
            // their own sign-in rather than a dead end. We only say so when the
            // credentials actually check out, so this cannot be used to probe
            // for valid staff accounts.
            if ($this->matchesStaffAccount($login, $validated['password'])) {
                return redirect()
                    ->route('login')
                    ->with('info', 'That is a staff account. Please sign in to the laundry system here.');
            }

            return back()
                ->withInput($request->only('login'))
                ->withErrors(['login' => 'We could not match that mobile number/email and password.']);
        }

        if (! $customer->is_active) {
            return back()
                ->withInput($request->only('login'))
                ->withErrors(['login' => 'This account is on hold. Please contact your branch.']);
        }

        Auth::guard('customer')->login($customer, $request->boolean('remember'));
        $request->session()->regenerate();

        $customer->forceFill(['last_login_at' => now()])->save();

        return $this->afterAuthentication($request, $customer, 'Signed in. Good to see you again, '.$customer->name.'.');
    }

    public function logout(Request $request)
    {
        Auth::guard('customer')->logout();

        // Only the customer half of the session is discarded; a staff session in
        // the same browser is left alone.
        $request->session()->forget(Booking::PENDING_SESSION_KEY);
        $request->session()->regenerateToken();

        return redirect()->route('landing')->with('success', 'You have been signed out.');
    }

    /**
     * Does this identifier and password belong to a staff user? Mirrors the
     * username-or-email lookup the staff LoginController uses.
     */
    private function matchesStaffAccount(string $login, string $password): bool
    {
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $user = User::query()->where($field, $login)->first();

        return $user !== null && Hash::check($password, $user->password);
    }

    /**
     * Shared landing spot after register/login: if a booking was parked before
     * sign-up, create it now and drop the customer straight on its confirmation.
     */
    private function afterAuthentication(Request $request, Customer $customer, string $message)
    {
        $pickupRequest = BookingController::flushPending($request, $customer);

        if ($pickupRequest) {
            return redirect()
                ->route('customer.bookings.show', $pickupRequest)
                ->with('success', 'Pickup booked. Reference '.$pickupRequest->reference_no.'.');
        }

        return redirect()->route('customer.bookings.index')->with('success', $message);
    }
}
