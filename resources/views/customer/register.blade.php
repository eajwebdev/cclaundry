@extends('layouts.public')

@section('page_title', 'Create your account')

@php
    $businessName = $appBusinessName ?: config('app.name');
    $pending = $pendingBooking ?? null;
@endphp

@section('content')
<section class="relative overflow-hidden py-14 sm:py-20">
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
        <div class="absolute -top-32 -right-24 h-[28rem] w-[28rem] rounded-full bg-primary/10 blur-3xl"></div>
        <div class="absolute -bottom-24 -left-32 h-[24rem] w-[24rem] rounded-full bg-accent/12 blur-3xl"></div>
    </div>

    <div class="mx-auto grid max-w-6xl gap-10 px-4 sm:px-6 lg:grid-cols-[1fr_1.1fr] lg:gap-14 lg:px-8">

        {{-- ─────────── Left: what the account is for ─────────── --}}
        <div class="lg:pt-6">
            <a href="{{ route('landing') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-primary">
                <span data-lucide="arrow-left" class="h-3.5 w-3.5"></span>
                Back to home
            </a>

            <h1 class="mt-6 font-serif text-3xl leading-tight font-medium text-primary-deep sm:text-4xl dark:text-cane">
                @if($pending)
                    One step and your pickup is booked
                @else
                    Create your {{ $businessName }} account
                @endif
            </h1>

            <p class="mt-4 max-w-md text-[15px] leading-relaxed text-muted">
                @if($pending)
                    We have kept everything you filled in. Set a password and we will place the booking straight away.
                @else
                    It takes about thirty seconds, and it is what lets you book pickups, track them and rebook in one tap.
                @endif
            </p>

            {{-- The booking waiting to be placed --}}
            @if($pending)
                <div class="mt-8 overflow-hidden rounded-2xl border border-primary/25 bg-white dark:border-primary/25 dark:bg-[#241a13]">
                    <div class="flex items-center gap-2.5 border-b border-border bg-primary/6 px-5 py-3.5 dark:border-white/10">
                        <span data-lucide="truck" class="h-4 w-4 text-primary"></span>
                        <span class="text-sm font-semibold text-primary-deep dark:text-cane">Your pending booking</span>
                    </div>
                    <dl class="divide-y divide-border px-5 dark:divide-white/8">
                        @php
                            $slotLabels = $slots ?? [];
                            $summary = [
                                'Service' => data_get($serviceTypes, data_get($pending, 'service_type').'.label', '--'),
                                'Pickup' => trim(
                                    (data_get($pending, 'pickup_date') ? \Illuminate\Support\Carbon::parse(data_get($pending, 'pickup_date'))->format('M j, Y') : '--')
                                    .' · '.($slotLabels[data_get($pending, 'pickup_slot')] ?? '')
                                , ' ·'),
                                'Address' => \Illuminate\Support\Str::limit((string) data_get($pending, 'pickup_address'), 70),
                                'Return' => data_get($pending, 'delivery_preference') === 'deliver' ? 'Delivered back to you' : 'Claim at branch',
                            ];
                        @endphp
                        @foreach ($summary as $label => $detail)
                            <div class="flex items-start justify-between gap-4 py-3">
                                <dt class="shrink-0 text-sm text-muted">{{ $label }}</dt>
                                <dd class="text-right text-sm font-medium">{{ $detail ?: '--' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @else
                <ul class="mt-8 space-y-4">
                    @foreach ([
                        ['truck', 'Book in a minute', 'Your address and preferences are remembered for next time.'],
                        ['search', 'Track every order', 'See where your laundry is without calling the branch.'],
                        ['jobOrders', 'Your full history', 'Past orders, totals and balances in one place.'],
                    ] as [$icon, $title, $body])
                        <li class="flex gap-4">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                <span data-lucide="{{ $icon }}" class="h-4.5 w-4.5"></span>
                            </span>
                            <span>
                                <span class="block font-medium text-primary-deep dark:text-cane">{{ $title }}</span>
                                <span class="mt-0.5 block text-sm leading-relaxed text-muted">{{ $body }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- ─────────── Right: the form ─────────── --}}
        <div class="rounded-3xl border border-border bg-white p-7 shadow-xl shadow-dark/5 sm:p-9 dark:border-white/10 dark:bg-[#241a13]">
            <h2 class="font-serif text-2xl font-medium text-primary-deep dark:text-cane">Sign up</h2>
            <p class="mt-1.5 text-sm text-muted">
                Already have one?
                <a href="{{ route('customer.login') }}" class="font-medium text-primary hover:underline">Sign in instead</a>
            </p>
            <p class="mt-1 text-sm text-muted">
                Staff member? <a href="{{ route('login') }}" class="font-medium text-primary hover:underline">Sign in to the laundry system</a>.
            </p>

            <form method="POST" action="{{ route('customer.register.submit') }}" class="mt-7 space-y-5" x-data="{ show: false }">
                @csrf

                <div>
                    <label for="name" class="block text-sm font-medium">Full name</label>
                    <input id="name" type="text" name="name" required autocomplete="name"
                           value="{{ old('name', data_get($pending, 'contact_name')) }}"
                           class="mt-2 h-12 w-full rounded-xl border border-border bg-cream px-4 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#1c1510]">
                    @error('name') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="phone" class="block text-sm font-medium">Mobile number</label>
                        <input id="phone" type="tel" name="phone" required autocomplete="tel" placeholder="09XX XXX XXXX"
                               value="{{ old('phone', data_get($pending, 'contact_phone')) }}"
                               class="mt-2 h-12 w-full rounded-xl border border-border bg-cream px-4 text-sm outline-none transition placeholder:text-muted/60 focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#1c1510]">
                        <p class="mt-1.5 text-xs text-muted">This is what you will sign in with.</p>
                        @error('phone') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="branch_id" class="block text-sm font-medium">Home branch</label>
                        <select id="branch_id" name="branch_id" required
                                class="mt-2 h-12 w-full rounded-xl border border-border bg-cream px-4 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#1c1510]">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((string) old('branch_id', data_get($pending, 'branch_id')) === (string) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('branch_id') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium">Email <span class="font-normal text-muted">(optional)</span></label>
                    <input id="email" type="email" name="email" autocomplete="email"
                           value="{{ old('email', data_get($pending, 'contact_email')) }}"
                           class="mt-2 h-12 w-full rounded-xl border border-border bg-cream px-4 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#1c1510]">
                    @error('email') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="address" class="block text-sm font-medium">Default pickup address <span class="font-normal text-muted">(optional)</span></label>
                    <textarea id="address" name="address" rows="2"
                              class="mt-2 w-full rounded-xl border border-border bg-cream px-4 py-3 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#1c1510]">{{ old('address', data_get($pending, 'pickup_address')) }}</textarea>
                    @error('address') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="password" class="block text-sm font-medium">Password</label>
                        <div class="relative mt-2">
                            <input id="password" name="password" required autocomplete="new-password"
                                   :type="show ? 'text' : 'password'"
                                   class="h-12 w-full rounded-xl border border-border bg-cream pr-11 pl-4 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#1c1510]">
                            <button type="button" @click="show = ! show" class="absolute top-1/2 right-3 -translate-y-1/2 text-muted transition hover:text-primary" aria-label="Show password">
                                <span data-lucide="eye" class="h-4 w-4" x-show="! show"></span>
                                <span data-lucide="eyeOff" class="h-4 w-4" x-show="show" x-cloak></span>
                            </button>
                        </div>
                        <p class="mt-1.5 text-xs text-muted">At least 8 characters.</p>
                        @error('password') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium">Confirm password</label>
                        <input id="password_confirmation" name="password_confirmation" required autocomplete="new-password"
                               :type="show ? 'text' : 'password'"
                               class="mt-2 h-12 w-full rounded-xl border border-border bg-cream px-4 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#1c1510]">
                    </div>
                </div>

                <label class="flex cursor-pointer items-start gap-3 text-sm">
                    <input type="checkbox" name="terms" value="1" @checked(old('terms'))
                           class="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-primary/30">
                    <span class="leading-relaxed text-muted">
                        I agree to {{ $businessName }}&rsquo;s service terms, and understand my final total is
                        confirmed once my laundry is weighed at the branch.
                    </span>
                </label>
                @error('terms') <p class="-mt-2 text-xs text-red-600">{{ $message }}</p> @enderror

                <button type="submit"
                        class="inline-flex h-13 w-full items-center justify-center gap-2 rounded-xl bg-primary text-[15px] font-semibold text-white shadow-lg shadow-primary/25 transition hover:bg-primary-deep">
                    <span data-lucide="{{ $pending ? 'check' : 'user' }}" class="h-4.5 w-4.5"></span>
                    {{ $pending ? 'Create account & confirm booking' : 'Create my account' }}
                </button>
            </form>
        </div>
    </div>
</section>
@endsection
