@extends('layouts.public')

@section('page_title', 'Sign in')
@section('back_url', route('landing'))

@php($businessName = $appBusinessName ?: config('app.name'))

@section('content')
<section class="px-4 pt-8 sm:pt-14">
    <div class="mx-auto w-full max-w-md">
        <div class="text-center">
            <x-brand-mark class="mx-auto h-20 w-20" />
            <h1 class="cc-title mt-5">Welcome back</h1>
            <p class="cc-subtitle mt-1.5">Sign in to book a pickup or check on your laundry.</p>
        </div>

        <div class="cc-card mt-6 p-5 sm:p-8">
            <form method="POST" action="{{ route('customer.login.submit') }}" class="space-y-4" x-data="{ show: false }">
                @csrf

                <div>
                    <label for="login" class="cc-label">Mobile number or email</label>
                    <input id="login" type="text" name="login" required autofocus autocomplete="username"
                           placeholder="09XX XXX XXXX" value="{{ old('login') }}" class="cc-input mt-1.5">
                    @error('login') <p class="cc-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="password" class="cc-label">Password</label>
                    <div class="relative mt-1.5">
                        <input id="password" name="password" required autocomplete="current-password"
                               :type="show ? 'text' : 'password'" type="password" class="cc-input pr-12">
                        <button type="button" @click="show = ! show"
                                class="absolute top-1/2 right-1 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-cc-muted hover:text-cc-brown"
                                :aria-label="show ? 'Hide password' : 'Show password'">
                            <span data-lucide="eye" class="h-4.5 w-4.5" x-show="! show"></span>
                            <span data-lucide="eyeOff" class="h-4.5 w-4.5" x-show="show" x-cloak></span>
                        </button>
                    </div>
                    @error('password') <p class="cc-error">{{ $message }}</p> @enderror
                </div>

                <label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm font-semibold text-cc-ink">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="cc-checkbox">
                    Keep me signed in
                </label>

                <button type="submit" class="cc-btn w-full">
                    <span data-lucide="login" class="h-4.5 w-4.5"></span>
                    Sign in
                </button>
            </form>

            <p class="mt-6 border-t border-cc-line pt-5 text-center text-sm text-cc-muted">
                New here?
                <a href="{{ route('customer.register') }}" class="font-bold text-cc-brown hover:underline">Create an account</a>
            </p>
        </div>

        <p class="mt-5 text-center text-xs leading-relaxed text-cc-muted">
            Booked at the counter before? Use
            <a href="{{ route('customer.register') }}" class="font-bold text-cc-brown hover:underline">sign up</a>
            with the same mobile number and we will link your history.
        </p>

        {{-- This page is for customers only; staff accounts live behind /login. --}}
        <div class="mt-6 flex items-start gap-3 rounded-2xl border border-cc-line bg-cc-surface/80 px-4 py-3.5">
            <span data-lucide="lock" class="mt-0.5 h-4 w-4 shrink-0 text-cc-muted"></span>
            <p class="text-[13px] leading-relaxed text-cc-muted">
                Work at {{ $businessName }}? This page is for customers. Staff and branch employees sign in to the
                laundry system <a href="{{ route('login') }}" class="font-bold text-cc-brown hover:underline">here</a>.
            </p>
        </div>
    </div>
</section>
@endsection
