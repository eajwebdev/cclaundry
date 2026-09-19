@extends('layouts.public')

@section('page_title', 'Create your account')
@section('back_url', route('landing'))

@php
    $businessName = $appBusinessName ?: config('app.name');

    // Set when the visitor just booked as a guest: the booking is already
    // placed, so this page only offers to keep it somewhere they can manage it.
    $recent = $recentBooking ?? null;
@endphp

@section('content')
<section class="px-4 pt-6 sm:pt-10">
    <div class="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[1fr_1.1fr] lg:items-start lg:gap-10">

        {{-- ─────────── What the account is for ─────────── --}}
        <div class="lg:pt-4">
            <div class="text-center lg:text-left">
                <h1 class="cc-title">Create your account</h1>
                <p class="cc-subtitle mx-auto mt-2 max-w-md lg:mx-0">
                    @if($recent)
                        Your pickup is booked already. This just puts it somewhere you can track,
                        cancel and rebook it in one tap.
                    @else
                        It takes about thirty seconds, and it is what lets you book pickups, track them and rebook in one tap.
                    @endif
                </p>
            </div>

            {{-- The booking they already have. Nothing here is waiting on them. --}}
            @if($recent)
                <div class="cc-card mt-6 flex items-start gap-3.5 p-5">
                    <span class="cc-icon-tile h-11 w-11 shrink-0"><span data-lucide="check" class="h-5 w-5"></span></span>
                    <div class="min-w-0">
                        <p class="font-bold text-cc-deep">Booking {{ data_get($recent, 'reference_no') }} is placed</p>
                        <p class="mt-1 text-sm leading-relaxed text-cc-muted">
                            We will call {{ data_get($recent, 'contact_phone') }} to confirm before the rider heads over.
                            You do not need an account for that to happen.
                        </p>
                    </div>
                </div>
            @else
                <ul class="mt-6 hidden space-y-3 lg:block">
                    @foreach ([
                        ['truck', 'Book in a minute', 'Your address and preferences are remembered for next time.'],
                        ['search', 'Track every order', 'See where your laundry is without calling the branch.'],
                        ['jobOrders', 'Your full history', 'Past orders, totals and balances in one place.'],
                    ] as [$icon, $title, $body])
                        <li class="cc-card flex gap-4 p-4">
                            <span class="cc-icon-tile h-11 w-11"><span data-lucide="{{ $icon }}" class="h-5 w-5"></span></span>
                            <span>
                                <span class="block font-bold text-cc-deep">{{ $title }}</span>
                                <span class="mt-0.5 block text-sm leading-relaxed text-cc-muted">{{ $body }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- ─────────── The form ─────────── --}}
        <div class="cc-card p-5 sm:p-8">
            <h2 class="font-display text-2xl font-bold text-cc-deep">Sign up</h2>
            <p class="mt-1 text-sm text-cc-muted">
                Already have one?
                <a href="{{ route('login', ['as' => 'customer']) }}" class="font-bold text-cc-brown hover:underline">Sign in instead</a>
            </p>

            <form method="POST" action="{{ route('customer.register.submit') }}" class="mt-6 space-y-4" x-data="{ show: false }">
                @csrf

                <div>
                    <label for="name" class="cc-label">Full Name <span class="text-cc-brown">*</span></label>
                    <input id="name" type="text" name="name" required autocomplete="name" placeholder="e.g. Juan Dela Cruz"
                           value="{{ old('name', data_get($recent, 'contact_name')) }}" class="cc-input mt-1.5">
                    @error('name') <p class="cc-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="phone" class="cc-label">Mobile Number <span class="text-cc-brown">*</span></label>
                        <input id="phone" type="tel" name="phone" required inputmode="tel" autocomplete="tel" placeholder="09XX XXX XXXX"
                               value="{{ old('phone', data_get($recent, 'contact_phone')) }}" class="cc-input mt-1.5">
                        <p class="cc-help mt-1">This is what you will sign in with.</p>
                        @error('phone') <p class="cc-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="branch_id" class="cc-label">Home Branch <span class="text-cc-brown">*</span></label>
                        <select id="branch_id" name="branch_id" required class="cc-input mt-1.5">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((string) old('branch_id', data_get($recent, 'branch_id')) === (string) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('branch_id') <p class="cc-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="email" class="cc-label">Email <span class="font-semibold text-cc-muted">(optional)</span></label>
                    <input id="email" type="email" name="email" inputmode="email" autocomplete="email" placeholder="you@example.com"
                           value="{{ old('email', data_get($recent, 'contact_email')) }}" class="cc-input mt-1.5">
                    @error('email') <p class="cc-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="address" class="cc-label">Default Pickup Address <span class="font-semibold text-cc-muted">(optional)</span></label>
                    <textarea id="address" name="address" rows="2" autocomplete="street-address" placeholder="House / Street / Barangay"
                              class="cc-input mt-1.5">{{ old('address', data_get($recent, 'pickup_address')) }}</textarea>
                    @error('address') <p class="cc-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="password" class="cc-label">Password <span class="text-cc-brown">*</span></label>
                        <div class="relative mt-1.5">
                            <input id="password" name="password" required autocomplete="new-password"
                                   :type="show ? 'text' : 'password'" type="password" class="cc-input pr-12">
                            <button type="button" @click="show = ! show"
                                    class="absolute top-1/2 right-1 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-cc-muted hover:text-cc-brown"
                                    :aria-label="show ? 'Hide password' : 'Show password'">
                                <span data-lucide="eye" class="h-4.5 w-4.5" x-show="! show"></span>
                                <span data-lucide="eyeOff" class="h-4.5 w-4.5" x-show="show" x-cloak></span>
                            </button>
                        </div>
                        <p class="cc-help mt-1">At least 8 characters.</p>
                        @error('password') <p class="cc-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="cc-label">Confirm Password <span class="text-cc-brown">*</span></label>
                        <input id="password_confirmation" name="password_confirmation" required autocomplete="new-password"
                               :type="show ? 'text' : 'password'" type="password" class="cc-input mt-1.5">
                    </div>
                </div>

                <label class="flex cursor-pointer items-start gap-3 text-sm">
                    <input type="checkbox" name="terms" value="1" @checked(old('terms')) class="cc-checkbox mt-0.5">
                    <span class="leading-relaxed text-cc-muted">
                        I agree to {{ $businessName }}&rsquo;s service terms, and understand my final total is confirmed
                        once my laundry is weighed at the branch.
                    </span>
                </label>
                @error('terms') <p class="cc-error">{{ $message }}</p> @enderror

                <button type="submit" class="cc-btn w-full">
                    <span data-lucide="user" class="h-4.5 w-4.5"></span>
                    Create my account
                </button>
            </form>

        </div>
    </div>
</section>
@endsection
