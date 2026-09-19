@extends('layouts.public')

@section('page_title', 'Sign in')
@section('back_url', route('landing'))

@section('content')
<section class="px-4 pt-8 sm:pt-14">
    <div class="mx-auto w-full max-w-md">
        <div class="text-center">
            <x-brand-mark class="mx-auto h-20 w-20" />
            <h1 class="cc-title mt-5">Welcome back</h1>
            <p class="cc-subtitle mt-1.5">Sign in to your {{ $appBusinessName ?: config('app.name') }} account.</p>
        </div>

        <div class="cc-card mt-6 p-5 sm:p-8"
             x-data="{ accountType: @js(old('account_type', request()->query('as') === 'customer' ? 'customer' : 'staff')), showPassword: false }">
            <div class="mb-6 grid grid-cols-2 gap-1 rounded-xl bg-cc-soft p-1" role="tablist" aria-label="Account type">
                <button type="button" role="tab" @click="accountType = 'customer'"
                        :aria-selected="accountType === 'customer'"
                        :class="accountType === 'customer' ? 'bg-cc-surface text-cc-deep shadow-sm' : 'text-cc-muted'"
                        class="min-h-11 rounded-lg px-3 text-sm font-bold transition">Customer</button>
                <button type="button" role="tab" @click="accountType = 'staff'"
                        :aria-selected="accountType === 'staff'"
                        :class="accountType === 'staff' ? 'bg-cc-surface text-cc-deep shadow-sm' : 'text-cc-muted'"
                        class="min-h-11 rounded-lg px-3 text-sm font-bold transition">Staff</button>
            </div>

            <form method="POST" action="{{ route('login.submit') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="account_type" :value="accountType">

                <div>
                    <label for="login" class="cc-label" x-text="accountType === 'staff' ? 'Username or email' : 'Mobile number or email'">Mobile number or email</label>
                    <input id="login" type="text" name="login" required autofocus autocomplete="username"
                           autocapitalize="none" autocorrect="off" spellcheck="false"
                           :placeholder="accountType === 'staff' ? 'Username or email' : '09XX XXX XXXX or email'"
                           value="{{ old('login') }}" class="cc-input mt-1.5">
                    @error('login') <p class="cc-error">{{ $message }}</p> @enderror
                    @error('account_type') <p class="cc-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="password" class="cc-label">Password</label>
                    <div class="relative mt-1.5">
                        <input id="password" name="password" required autocomplete="current-password"
                               :type="showPassword ? 'text' : 'password'" type="password" class="cc-input pr-12">
                        <button type="button" @click="showPassword = ! showPassword"
                                class="absolute top-1/2 right-1 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-cc-muted hover:text-cc-brown"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'">
                            <span data-lucide="eye" class="h-4.5 w-4.5" x-show="! showPassword"></span>
                            <span data-lucide="eyeOff" class="h-4.5 w-4.5" x-show="showPassword" x-cloak></span>
                        </button>
                    </div>
                    @error('password') <p class="cc-error">{{ $message }}</p> @enderror
                </div>

                @if($branches->count() > 1)
                    <div x-show="accountType === 'staff'" x-cloak>
                        <label for="branch_id" class="cc-label">Branch <span class="font-normal text-cc-muted">(optional)</span></label>
                        <select id="branch_id" name="branch_id" class="cc-input mt-1.5">
                            <option value="">Use my assigned branch</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((int) old('branch_id') === (int) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        <p class="cc-help mt-1">Working at another branch today? Choose it here.</p>
                        @error('branch_id') <p class="cc-error">{{ $message }}</p> @enderror
                    </div>
                @endif

                <label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm font-semibold text-cc-ink">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="cc-checkbox">
                    Keep me signed in
                </label>

                <button type="submit" class="cc-btn w-full">
                    <span data-lucide="login" class="h-4.5 w-4.5"></span>
                    Sign in
                </button>
            </form>

            <p class="mt-6 border-t border-cc-line pt-5 text-center text-sm text-cc-muted" x-show="accountType === 'customer'">
                New here?
                <a href="{{ route('customer.register') }}" class="font-bold text-cc-brown hover:underline">Create an account</a>
            </p>
        </div>

        <p class="mt-5 text-center text-xs leading-relaxed text-cc-muted">
            Booked at the counter before? Use the same mobile number when you create your account to link your laundry history.
        </p>
    </div>
</section>
@endsection
