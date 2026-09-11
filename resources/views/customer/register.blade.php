@extends('layouts.public')

@section('page_title', 'Create your account')
@section('back_url', route('landing'))

@php
    $businessName = $appBusinessName ?: config('app.name');
    $pending = $pendingBooking ?? null;
@endphp

@section('content')
<section class="px-4 pt-6 sm:pt-10">
    <div class="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[1fr_1.1fr] lg:items-start lg:gap-10">

        {{-- ─────────── What the account is for ─────────── --}}
        <div class="lg:pt-4">
            <div class="text-center lg:text-left">
                <h1 class="cc-title">
                    @if($pending)
                        One step and your pickup is booked
                    @else
                        Create your account
                    @endif
                </h1>
                <p class="cc-subtitle mx-auto mt-2 max-w-md lg:mx-0">
                    @if($pending)
                        We have kept everything you filled in. Set a password and we will place the booking straight away.
                    @else
                        It takes about thirty seconds, and it is what lets you book pickups, track them and rebook in one tap.
                    @endif
                </p>
            </div>

            {{-- The booking waiting to be placed --}}
            @if($pending)
                @php
                    $slotLabels = $slots ?? [];
                    $summary = [
                        ['laundry', 'Service', data_get($offerings->firstWhere('key', data_get($pending, 'offering')), 'name', '--')],
                        ['calendar-days', 'Pickup', trim(
                            (data_get($pending, 'pickup_date') ? \Illuminate\Support\Carbon::parse(data_get($pending, 'pickup_date'))->format('M j, Y') : '--')
                            .' · '.($slotLabels[data_get($pending, 'pickup_slot')] ?? '')
                        , ' ·')],
                        ['map-pin', 'Address', \Illuminate\Support\Str::limit((string) data_get($pending, 'pickup_address'), 70)],
                        ['truck', 'Return', data_get($pending, 'delivery_preference') === 'deliver' ? 'Free delivery back to you' : 'Claim at branch'],
                    ];
                @endphp
                <div class="cc-card mt-6 overflow-hidden">
                    <div class="flex items-center gap-2.5 border-b border-cc-line bg-cc-soft/70 px-5 py-3">
                        <span data-lucide="truck" class="h-4 w-4 text-cc-brown"></span>
                        <span class="text-sm font-bold text-cc-deep">Your pending booking</span>
                    </div>
                    <ul class="divide-y divide-cc-line px-5">
                        @foreach ($summary as [$icon, $label, $detail])
                            <li class="flex items-start gap-3 py-3">
                                <span data-lucide="{{ $icon }}" class="mt-0.5 h-4.5 w-4.5 shrink-0 text-cc-brown"></span>
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold text-cc-muted">{{ $label }}</p>
                                    <p class="mt-0.5 text-sm font-bold wrap-break-word text-cc-deep">{{ $detail ?: '--' }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
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
                <a href="{{ route('customer.login') }}" class="font-bold text-cc-brown hover:underline">Sign in instead</a>
            </p>

            <form method="POST" action="{{ route('customer.register.submit') }}" class="mt-6 space-y-4" x-data="{ show: false }">
                @csrf

                <div>
                    <label for="name" class="cc-label">Full Name <span class="text-cc-brown">*</span></label>
                    <input id="name" type="text" name="name" required autocomplete="name" placeholder="e.g. Juan Dela Cruz"
                           value="{{ old('name', data_get($pending, 'contact_name')) }}" class="cc-input mt-1.5">
                    @error('name') <p class="cc-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="phone" class="cc-label">Mobile Number <span class="text-cc-brown">*</span></label>
                        <input id="phone" type="tel" name="phone" required inputmode="tel" autocomplete="tel" placeholder="09XX XXX XXXX"
                               value="{{ old('phone', data_get($pending, 'contact_phone')) }}" class="cc-input mt-1.5">
                        <p class="cc-help mt-1">This is what you will sign in with.</p>
                        @error('phone') <p class="cc-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="branch_id" class="cc-label">Home Branch <span class="text-cc-brown">*</span></label>
                        <select id="branch_id" name="branch_id" required class="cc-input mt-1.5">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((string) old('branch_id', data_get($pending, 'branch_id')) === (string) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('branch_id') <p class="cc-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="email" class="cc-label">Email <span class="font-semibold text-cc-muted">(optional)</span></label>
                    <input id="email" type="email" name="email" inputmode="email" autocomplete="email" placeholder="you@example.com"
                           value="{{ old('email', data_get($pending, 'contact_email')) }}" class="cc-input mt-1.5">
                    @error('email') <p class="cc-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="address" class="cc-label">Default Pickup Address <span class="font-semibold text-cc-muted">(optional)</span></label>
                    <textarea id="address" name="address" rows="2" autocomplete="street-address" placeholder="House / Street / Barangay"
                              class="cc-input mt-1.5">{{ old('address', data_get($pending, 'pickup_address')) }}</textarea>
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
                    <span data-lucide="{{ $pending ? 'check' : 'user' }}" class="h-4.5 w-4.5"></span>
                    {{ $pending ? 'Create account & confirm booking' : 'Create my account' }}
                </button>
            </form>

            <p class="mt-5 border-t border-cc-line pt-4 text-center text-xs text-cc-muted">
                Staff member? <a href="{{ route('login') }}" class="font-bold text-cc-brown hover:underline">Sign in to the laundry system</a>.
            </p>
        </div>
    </div>
</section>
@endsection
