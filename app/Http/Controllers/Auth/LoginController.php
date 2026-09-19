<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\AttendanceEmployee;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Support\Activity;

class LoginController extends Controller
{
    public function showLogin()
    {
        return view('auth.login', [
            'branches' => Branch::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function showAttendanceLogin()
    {
        return view('auth.attendance-login');
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'account_type' => ['nullable', 'in:customer,staff'],
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        // Old customer sign-in forms still post to /customer/login. Existing
        // staff integrations that post to /login without a type stay valid.
        $accountType = $request->routeIs('customer.login.submit')
            ? 'customer'
            : ($validated['account_type'] ?? 'staff');

        if ($accountType === 'customer') {
            return $this->loginCustomer($request, trim($validated['login']), $validated['password']);
        }

        return $this->loginStaff($request, trim($validated['login']), $validated['password']);
    }

    private function loginCustomer(Request $request, string $login, string $password)
    {
        $customer = filter_var($login, FILTER_VALIDATE_EMAIL)
            ? Customer::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($login)])->first()
            : Customer::query()->matchingPhone($login)->first();

        if (! $customer || ! $customer->hasPortalAccount() || ! Hash::check($password, $customer->password)) {
            return redirect()->route('login', ['as' => 'customer'])
                ->withErrors(['login' => 'We could not match that mobile number/email and password.'])
                ->onlyInput('login', 'account_type');
        }

        if (! $customer->is_active) {
            return redirect()->route('login', ['as' => 'customer'])
                ->withErrors(['login' => 'This account is on hold. Please contact your branch.'])
                ->onlyInput('login', 'account_type');
        }

        Auth::guard('customer')->login($customer, $request->boolean('remember'));
        $request->session()->regenerate();
        $customer->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('customer.bookings.index')
            ->with('success', 'Signed in. Good to see you again, '.$customer->name.'.');
    }

    private function loginStaff(Request $request, string $login, string $password)
    {
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $user = User::where($field, $login)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return back()
                ->withErrors(['login' => 'Invalid username/email or password.'])
                ->onlyInput('login', 'branch_id', 'account_type');
        }

        if ($user->status !== 'active') {
            return back()
                ->withErrors(['login' => 'Your account is inactive. Please contact administrator.'])
                ->onlyInput('login', 'branch_id', 'account_type');
        }

        // Admins work across every branch, so their branch is never reassigned here.
        // Branch staff may be working out of another branch today, so the branch they
        // pick at login becomes their branch and every module scopes to it from there.
        $previousBranchId = $user->branch_id;
        $branchId = $previousBranchId;

        if (! $user->isAdmin() && $request->filled('branch_id')) {
            $branch = Branch::where('is_active', true)->find($request->integer('branch_id'));

            if (! $branch) {
                return back()
                    ->withErrors(['branch_id' => 'That branch is unavailable. Please pick another.'])
                    ->onlyInput('login', 'branch_id', 'account_type');
            }

            $branchId = $branch->id;
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        $user->update([
            'last_login_at' => now(),
            'branch_id' => $branchId,
        ]);

        if ((int) $branchId !== (int) $previousBranchId) {
            Activity::log($request, 'user_branch_switched', $user, [
                'from_branch_id' => $previousBranchId,
                'to_branch_id' => $branchId,
            ], $branchId);
        }

        return match ($user->role) {
            // A rider has no dashboard to land on: their whole job lives in the
            // phone console.
            'rider' => redirect()->route('rider.index'),
            default => redirect()->route('dashboard'),
        };
    }

    public function attendanceLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('attendance.login')
                ->withErrors($validator)
                ->withInput($request->only('login'));
        }

        $employee = AttendanceEmployee::query()
            ->where('username', $request->login)
            ->where('status', 'active')
            ->first();

        if (! $employee || ! Hash::check($request->password, $employee->password)) {
            return redirect()
                ->route('attendance.login')
                ->withErrors(['login' => 'Invalid employee username or password.'])
                ->onlyInput('login');
        }

        $request->session()->regenerate();
        $request->session()->put('attendance_employee_id', $employee->id);
        $employee->update(['last_login_at' => now()]);

        return redirect()->route('attendance.kiosk');
    }

    public function attendanceLogout(Request $request)
    {
        $request->session()->forget('attendance_employee_id');
        $request->session()->regenerateToken();

        return redirect()->route('attendance.login');
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

}
