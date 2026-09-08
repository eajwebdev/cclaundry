@extends('layouts.public')

@section('page_title', 'Sign in')

@php($businessName = $appBusinessName ?: config('app.name'))

@section('content')
<section class="relative overflow-hidden py-16 sm:py-24">
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
        <div class="absolute -top-32 -left-24 h-[28rem] w-[28rem] rounded-full bg-primary/10 blur-3xl"></div>
        <div class="absolute -right-32 -bottom-24 h-[24rem] w-[24rem] rounded-full bg-accent/12 blur-3xl"></div>
    </div>

    <div class="mx-auto w-full max-w-md px-4 sm:px-6">
        <div class="text-center">
            <x-brand-mark class="mx-auto h-20 w-20" />
            <h1 class="mt-6 font-serif text-3xl font-medium text-primary-deep dark:text-cane">Welcome back</h1>
            <p class="mt-2 text-sm text-muted">
                @if($hasPendingBooking)
                    Sign in and we will place the pickup you just filled out.
                @else
                    Sign in to book a pickup or check on an order.
                @endif
            </p>
        </div>

        @if($hasPendingBooking)
            <div class="mt-7 flex items-start gap-3 rounded-2xl border border-primary/25 bg-primary/6 px-4 py-3.5">
                <span data-lucide="truck" class="mt-0.5 h-4 w-4 shrink-0 text-primary"></span>
                <p class="text-[13px] leading-relaxed text-muted">
                    <span class="font-medium text-primary-deep dark:text-cane">Your booking is waiting.</span>
                    It will be confirmed the moment you sign in.
                </p>
            </div>
        @endif

        <div class="mt-7 rounded-3xl border border-border bg-white p-7 shadow-xl shadow-dark/5 sm:p-8 dark:border-white/10 dark:bg-[#241a13]">
            <form method="POST" action="{{ route('customer.login.submit') }}" class="space-y-5" x-data="{ show: false }">
                @csrf

                <div>
                    <label for="login" class="block text-sm font-medium">Mobile number or email</label>
                    <input id="login" type="text" name="login" required autofocus autocomplete="username"
                           placeholder="09XX XXX XXXX" value="{{ old('login') }}"
                           class="mt-2 h-12 w-full rounded-xl border border-border bg-cream px-4 text-sm outline-none transition placeholder:text-muted/60 focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#1c1510]">
                    @error('login') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium">Password</label>
                    <div class="relative mt-2">
                        <input id="password" name="password" required autocomplete="current-password"
                               :type="show ? 'text' : 'password'"
                               class="h-12 w-full rounded-xl border border-border bg-cream pr-11 pl-4 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#1c1510]">
                        <button type="button" @click="show = ! show" class="absolute top-1/2 right-3 -translate-y-1/2 text-muted transition hover:text-primary" aria-label="Show password">
                            <span data-lucide="eye" class="h-4 w-4" x-show="! show"></span>
                            <span data-lucide="eyeOff" class="h-4 w-4" x-show="show" x-cloak></span>
                        </button>
                    </div>
                    @error('password') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-muted">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember'))
                           class="h-4 w-4 rounded border-border text-primary focus:ring-primary/30">
                    Keep me signed in
                </label>

                <button type="submit"
                        class="inline-flex h-13 w-full items-center justify-center gap-2 rounded-xl bg-primary text-[15px] font-semibold text-white shadow-lg shadow-primary/25 transition hover:bg-primary-deep">
                    <span data-lucide="login" class="h-4.5 w-4.5"></span>
                    Sign in
                </button>
            </form>

            <p class="mt-6 border-t border-border pt-5 text-center text-sm text-muted dark:border-white/10">
                New here?
                <a href="{{ route('customer.register') }}" class="font-medium text-primary hover:underline">Create an account</a>
            </p>
        </div>

        <p class="mt-6 text-center text-xs text-muted">
            Booked at the counter before? Use
            <a href="{{ route('customer.register') }}" class="font-medium text-primary hover:underline">sign up</a>
            with the same mobile number and we will link your history.
        </p>

        {{-- This page is for customers only; staff accounts live behind /login. --}}
        <div class="mt-8 flex items-start gap-3 rounded-2xl border border-border bg-white/60 px-4 py-3.5 dark:border-white/10 dark:bg-white/5">
            <span data-lucide="lock" class="mt-0.5 h-4 w-4 shrink-0 text-muted"></span>
            <p class="text-[13px] leading-relaxed text-muted">
                Work at {{ $appBusinessName ?: config('app.name') }}?
                This page is for customers. Staff and branch employees sign in to the laundry system
                <a href="{{ route('login') }}" class="font-medium text-primary hover:underline">here</a>.
            </p>
        </div>
    </div>
</section>
@endsection
