@extends('layouts.public')

@section('page_title', 'Booking ' . $pickupRequest->reference_no)
@section('back_url', route('customer.bookings.index'))

@php
    $businessName = $appBusinessName ?: config('app.name');
    $status = $pickupRequest->customerProgressStatus();
    $cancelled = $status === 'cancelled';

    // Reachable without an account, so the account-only actions (the booking
    // list, cancelling) are shown only to the customer who owns this one.
    $viewer = auth('customer')->user();
    $isOwner = $viewer && $viewer->id === $pickupRequest->customer_id;
    $hasAccount = $pickupRequest->customer?->hasPortalAccount() ?? false;

    [$headline, $headlineIcon] = match ($status) {
        'cancelled' => ['Booking Cancelled', 'x'],
        'picked_up' => ['We Have Your Laundry', 'laundry'],
        'washing' => ['Your Laundry Is Washing', 'laundry'],
        'drying' => ['Your Laundry Is Drying', 'wind'],
        'folding' => ['Your Laundry Is Being Folded', 'shirt'],
        'ironing' => ['Your Laundry Is Being Steamed', 'shirt'],
        'ready_for_pickup' => ['Ready for Pickup', 'package-check'],
        'ready_for_delivery' => ['Ready for Delivery', 'package-check'],
        'out_for_delivery' => ['Out for Delivery', 'truck'],
        'completed' => ['All Done!', 'check-check'],
        default => ['Pickup Booked!', 'check'],
    };

    // Every weighed line on the booking added up: what the rider's scale is
    // checked against when the bag is collected.
    $declaredKilos = $pickupRequest->declaredKilos();
    $kilos = $declaredKilos
        ? rtrim(rtrim(number_format($declaredKilos, 2), '0'), '.').' kg'
        : 'To be weighed';

    $money = fn ($amount) => '₱'.number_format((float) $amount, 2);

    $details = [
        ['laundry', 'Service', $pickupRequest->serviceSummary() ?: $pickupRequest->serviceTypeLabel()],
        ...($pickupRequest->tag_code
            ? [['tag', 'Laundry tag', $pickupRequest->tag_code.', written on your bag']]
            : []),
        ['wallet', 'Payment', $pickupRequest->collected_amount !== null
            ? '₱'.number_format((float) $pickupRequest->collected_amount, 2).' paid at pickup'
            : $pickupRequest->paymentMethodLabel().', collected by our rider when they pick up your laundry'],
        ['scale', 'Weight (estimated)', $kilos],
        ['store', 'Branch', $pickupRequest->branch?->name ?? '--'],
        ['map-pin', 'Pickup address', $pickupRequest->pickup_address.($pickupRequest->pickup_landmark ? ' (Landmark: '.$pickupRequest->pickup_landmark.')' : '')],
        ['truck', 'Pickup & delivery', $pickupRequest->wantsDelivery()
            ? 'Delivery'
                .($pickupRequest->delivery_date ? ' · '.$pickupRequest->delivery_date->format('M j, Y') : '')
                .($pickupRequest->deliverySlotLabel() ? ' · '.$pickupRequest->deliverySlotLabel() : '')
            : 'Claim at '.($pickupRequest->branch?->name ?? 'the branch')],
    ];

    if ($pickupRequest->wantsDelivery() && $pickupRequest->delivery_address && $pickupRequest->delivery_address !== $pickupRequest->pickup_address) {
        $details[] = ['packageCheck', 'Delivery address', $pickupRequest->delivery_address];
    }

    $details[] = ['user', 'Contact', $pickupRequest->contact_name.' · '.$pickupRequest->contact_phone];

    if ($pickupRequest->is_rush) {
        $details[] = ['zap', 'Rush service', 'Yes, prioritised for same-day handling'];
    }

    if ($pickupRequest->notes) {
        $details[] = ['sticky-note', 'Your instructions', $pickupRequest->notes];
    }
@endphp

@section('content')
<section class="px-4 pt-6 sm:pt-10" x-data="{ accountModalOpen: true }">
    <div class="mx-auto grid max-w-5xl gap-6 lg:grid-cols-2 lg:items-start lg:gap-8">

        {{-- ─────────── Confirmation ─────────── --}}
        <div>
            <div class="text-center">
                <span class="mx-auto flex h-18 w-18 items-center justify-center rounded-full
                    {{ $cancelled ? 'bg-red-100 text-red-700' : 'bg-cc-brown text-white shadow-[0_16px_30px_-16px_rgba(74,47,31,0.85)]' }}">
                    <span data-lucide="{{ $headlineIcon }}" class="h-8 w-8"></span>
                </span>
                <h1 class="cc-title mt-5 text-[2.1rem] sm:text-[2.6rem]">{{ $headline }}</h1>
                <p class="cc-subtitle mx-auto mt-2 max-w-xs">
                    @if($cancelled)
                        Nothing further will happen with this request.
                    @else
                        Thank you for choosing<br>{{ $businessName }}.
                    @endif
                </p>
            </div>

            <div class="cc-card mt-6 overflow-hidden">
                <div class="border-b border-cc-line px-5 py-3.5 text-center">
                    <p class="font-display text-xl font-bold wrap-break-word text-cc-deep sm:text-2xl">Booking #{{ $pickupRequest->reference_no }}</p>
                </div>
                <div class="space-y-3 px-5 py-4">
                    <p class="text-sm text-cc-muted">{{ $cancelled ? 'This pickup was scheduled for:' : 'Your pickup is scheduled for:' }}</p>
                    <p class="flex items-center gap-3 text-[15px] font-bold text-cc-deep">
                        <span data-lucide="calendar-days" class="h-5 w-5 shrink-0 text-cc-brown"></span>
                        {{ $pickupRequest->pickup_date->format('F j, Y') }}
                    </p>
                    <p class="flex items-center gap-3 text-[15px] font-bold text-cc-deep">
                        <span data-lucide="time" class="h-5 w-5 shrink-0 text-cc-brown"></span>
                        {{ $pickupRequest->pickupSlotLabel() }}
                    </p>
                    <div class="pt-1">
                        @include('partials.booking-status', ['status' => $status])
                    </div>
                </div>
            </div>

            <div class="mt-5 space-y-3">
                @unless($cancelled)
                    <a href="#status" class="cc-btn w-full">
                        <span data-lucide="truck" class="h-5 w-5"></span>
                        Track My Laundry
                    </a>
                    <p class="text-center text-xs text-cc-muted">
                        @if($settings?->sms_enabled)
                            You&rsquo;ll receive a confirmation via SMS at {{ $pickupRequest->contact_phone }} shortly.
                        @else
                            We&rsquo;ll call {{ $pickupRequest->contact_phone }} to confirm before the rider heads over.
                        @endif
                    </p>
                @endunless

                @if($isOwner)
                    <a href="{{ route('customer.bookings.index') }}" class="cc-btn-outline w-full">
                        <span data-lucide="jobOrders" class="h-4.5 w-4.5"></span>
                        View all my bookings
                    </a>
                @elseif(! $cancelled)
                    <div class="cc-soft px-4 py-4 text-center">
                        <p class="text-sm font-bold text-cc-deep">Account Required</p>
                        <p class="mx-auto mt-1 max-w-xs text-xs leading-relaxed text-cc-muted">
                            Please create an account or sign in with {{ $pickupRequest->contact_phone }} to track and manage booking #{{ $pickupRequest->reference_no }}.
                        </p>
                        <button type="button" @click="accountModalOpen = true" class="cc-btn cc-btn-sm mt-3">
                            <span data-lucide="user" class="h-4 w-4"></span>
                            Create Account / Sign In
                        </button>
                    </div>
                @endif

                <div class="flex flex-wrap items-center justify-center gap-x-6 gap-y-1 pt-1 text-sm font-bold">
                    <a href="{{ route('landing') }}#book" class="inline-flex min-h-11 items-center gap-1.5 text-cc-brown hover:underline">
                        <span data-lucide="plus" class="h-4 w-4"></span>
                        Book another
                    </a>

                    @if($isOwner && $pickupRequest->isCancellable())
                        <form method="POST" action="{{ route('customer.bookings.cancel', $pickupRequest) }}"
                              onsubmit="return confirm('Cancel booking {{ $pickupRequest->reference_no }}? This cannot be undone.');">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="inline-flex min-h-11 items-center gap-1.5 text-red-700 hover:underline">
                                <span data-lucide="x" class="h-4 w-4"></span>
                                Cancel this booking
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- ─────────── Status & details ─────────── --}}
        <div id="status" class="scroll-mt-20 space-y-5">
            <div class="cc-card p-5 sm:p-6">
                <h2 class="cc-title text-[1.6rem] sm:text-3xl">Laundry Status</h2>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-cc-muted">
                    <span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
                    <span id="tracking-refresh-label" role="status">Checking for updates automatically every 5 seconds</span>
                    <button id="tracking-refresh-button" type="button" class="rounded-full border border-cc-line px-2.5 py-1 font-semibold text-cc-brown hover:bg-cc-surface">Check now</button>
                </div>

                <div class="cc-soft mt-4 px-4 py-3">
                    <p class="text-sm font-bold wrap-break-word text-cc-deep">Booking #{{ $pickupRequest->reference_no }}</p>
                    <p class="mt-0.5 text-xs text-cc-muted">{{ $kilos }} &middot; {{ $pickupRequest->serviceTypeLabel() }}</p>
                    <p class="mt-1 flex items-center gap-1.5 text-xs text-cc-muted">
                        <span data-lucide="calendar-days" class="h-3.5 w-3.5 text-cc-brown"></span>
                        Pickup: {{ $pickupRequest->pickup_date->format('M j, Y') }}
                    </p>
                </div>

                <div class="mt-5">
                    @include('partials.booking-timeline')
                </div>
            </div>

            <div class="cc-card p-5 sm:p-6">
                <h2 class="font-display text-2xl font-bold text-cc-deep">Booking Details</h2>

                {{-- What was booked, line by line, so the amounts the customer
                     entered are the same ones the counter weighs against. --}}
                @if($pickupRequest->items->isNotEmpty())
                    <ul class="mt-3 divide-y divide-cc-line overflow-hidden rounded-2xl border border-cc-line">
                        @foreach ($pickupRequest->items as $item)
                            <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <span class="min-w-0">
                                    <span class="block text-sm font-bold wrap-break-word text-cc-deep">
                                        {{ $item->service_name }}
                                        @if($item->is_addon)
                                            <span class="ml-1 rounded-full bg-cc-soft px-1.5 py-0.5 align-middle text-[9px] font-bold tracking-wide text-cc-brown uppercase">Add-on</span>
                                        @endif
                                    </span>
                                    <span class="block text-xs text-cc-muted">{{ $item->quantityLabel() }}</span>
                                </span>
                                <span class="shrink-0 text-sm font-bold whitespace-nowrap text-cc-brown">{{ $money($item->line_total) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <ul class="mt-2 divide-y divide-cc-line">
                    @foreach ($details as [$icon, $label, $detail])
                        <li class="flex items-start gap-3 py-3">
                            <span data-lucide="{{ $icon }}" class="mt-0.5 h-5 w-5 shrink-0 text-cc-brown"></span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-cc-muted">{{ $label }}</p>
                                <p class="mt-0.5 text-sm font-bold wrap-break-word text-cc-deep">{{ $detail }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="cc-soft mt-3 px-4 py-4">
                    @if($pickupRequest->jobOrder)
                        <p class="text-xs font-semibold text-cc-muted">Now job order <span class="font-mono font-bold text-cc-brown">{{ $pickupRequest->jobOrder->job_order_number }}</span></p>
                        <div class="mt-2 grid grid-cols-2 gap-3">
                            <div>
                                <p class="text-xs text-cc-muted">Total</p>
                                <p class="font-display text-3xl leading-none font-bold text-cc-deep">{{ $money($pickupRequest->jobOrder->total) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-cc-muted">Balance</p>
                                <p class="font-display text-3xl leading-none font-bold {{ (float) $pickupRequest->jobOrder->balance > 0 ? 'text-red-700' : 'text-emerald-700' }}">
                                    {{ $money($pickupRequest->jobOrder->balance) }}
                                </p>
                            </div>
                        </div>
                    @else
                        <p class="text-sm font-bold text-cc-deep">Estimated Total</p>
                        @if($pickupRequest->estimated_total)
                            <p class="mt-1.5 font-display text-[2.6rem] leading-none font-bold text-cc-deep">{{ $money($pickupRequest->estimated_total) }}</p>
                        @else
                            <p class="mt-1 text-lg font-bold text-cc-deep">Set after weighing</p>
                        @endif
                        <p class="mt-2 flex items-start gap-1.5 text-xs text-cc-muted">
                            <span data-lucide="info" class="mt-px h-3.5 w-3.5 shrink-0"></span>
                            Final amount will be based on the actual laundry weight.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @unless($isOwner)
    <div x-data="{
            mode: @js($hasAccount || $errors->has('login') ? 'login' : 'register'),
            showPassword: false,
            clientError: '',
            submitting: false,
            switchMode(newMode) {
                this.mode = newMode;
                this.clientError = '';
                this.$nextTick(() => window.renderLucideIcons?.());
            },
            validateRegister() {
                this.clientError = '';
                const p1 = this.$refs.regPassword ? this.$refs.regPassword.value : '';
                const p2 = this.$refs.regPasswordConfirm ? this.$refs.regPasswordConfirm.value : '';
                if (!p1 || p1.length < 8) {
                    this.clientError = 'Password must be at least 8 characters.';
                    return false;
                }
                if (p1 !== p2) {
                    this.clientError = 'Passwords do not match.';
                    return false;
                }
                return true;
            }
         }"
         x-init="$nextTick(() => window.renderLucideIcons?.())"
         x-show="accountModalOpen"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 overflow-y-auto bg-cc-deep/80 backdrop-blur-sm"
         role="dialog"
         aria-modal="true"
         aria-labelledby="account-modal-title">

        <div class="relative w-full max-w-lg my-8 rounded-3xl bg-white p-6 sm:p-8 shadow-2xl border border-cc-line text-left"
             @click.stop>
            
            <div class="flex items-center justify-between gap-3 mb-4">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-cc-soft px-3 py-1 text-xs font-bold uppercase tracking-wider text-cc-brown">
                    <span data-lucide="check-circle" class="h-3.5 w-3.5"></span>
                    <span>Booking #{{ $pickupRequest->reference_no }}</span>
                </span>
            </div>

            {{-- Register Mode --}}
            <div x-show="mode === 'register'">
                <h2 id="account-modal-title" class="font-display text-2xl sm:text-3xl font-bold text-cc-deep">Create your account</h2>
                <p class="mt-1.5 text-sm text-cc-muted">
                    Your pickup is scheduled! Please set a password to finish creating your account. You will be automatically logged in to track and manage your laundry.
                </p>

                <form method="POST" action="{{ route('customer.register.submit') }}"
                      @submit="if (!validateRegister()) { $event.preventDefault(); return; } submitting = true;"
                      class="mt-5 space-y-4">
                    @csrf
                    <input type="hidden" name="redirect_to" value="{{ route('booking.confirmed', $pickupRequest->reference_no) }}">
                    <input type="hidden" name="branch_id" value="{{ $pickupRequest->branch_id }}">
                    <input type="hidden" name="name" value="{{ $pickupRequest->contact_name }}">
                    <input type="hidden" name="phone" value="{{ $pickupRequest->contact_phone }}">
                    <input type="hidden" name="email" value="{{ $pickupRequest->contact_email }}">
                    <input type="hidden" name="address" value="{{ $pickupRequest->pickup_address }}">

                    <div class="rounded-2xl border border-cc-line bg-cc-surface/70 p-3.5 space-y-1.5 text-xs text-cc-muted">
                        <div class="flex justify-between">
                            <span class="font-semibold text-cc-deep">Name:</span>
                            <span class="font-bold text-cc-deep">{{ $pickupRequest->contact_name }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="font-semibold text-cc-deep">Mobile:</span>
                            <span class="font-mono font-bold text-cc-deep">{{ $pickupRequest->contact_phone }}</span>
                        </div>
                        @if($pickupRequest->branch)
                            <div class="flex justify-between">
                                <span class="font-semibold text-cc-deep">Branch:</span>
                                <span class="text-cc-deep">{{ $pickupRequest->branch->name }}</span>
                            </div>
                        @endif
                    </div>

                    <div>
                        <label class="cc-label">Create Password <span class="text-cc-brown">*</span></label>
                        <div class="relative mt-1.5">
                            <input x-ref="regPassword" name="password" required autocomplete="new-password"
                                   :type="showPassword ? 'text' : 'password'" type="password"
                                   placeholder="At least 8 characters" class="cc-input pr-12">
                            <button type="button" @click="showPassword = !showPassword"
                                    class="absolute top-1/2 right-1 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-cc-muted hover:text-cc-brown"
                                    :aria-label="showPassword ? 'Hide password' : 'Show password'">
                                <span data-lucide="eye" class="h-4.5 w-4.5" x-show="!showPassword"></span>
                                <span data-lucide="eyeOff" class="h-4.5 w-4.5" x-show="showPassword" x-cloak></span>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="cc-label">Confirm Password <span class="text-cc-brown">*</span></label>
                        <input x-ref="regPasswordConfirm" name="password_confirmation" required autocomplete="new-password"
                               :type="showPassword ? 'text' : 'password'" type="password"
                               placeholder="Re-type your password" class="cc-input mt-1.5">
                    </div>

                    <label class="flex cursor-pointer items-start gap-3 text-xs leading-relaxed text-cc-muted">
                        <input type="checkbox" name="terms" value="1" checked required class="cc-checkbox mt-0.5">
                        <span>I agree to {{ $businessName }}&rsquo;s service terms.</span>
                    </label>

                    <template x-if="clientError">
                        <p class="cc-error text-xs font-semibold" x-text="clientError"></p>
                    </template>

                    @if($errors->any() && ! $errors->has('login'))
                        <div class="rounded-xl bg-red-50 p-3 border border-red-200 text-xs text-red-700 space-y-1">
                            @foreach($errors->all() as $err)
                                <p>{{ $err }}</p>
                            @endforeach
                        </div>
                    @endif

                    <button type="submit" :disabled="submitting" class="cc-btn w-full">
                        <span data-lucide="user-check" class="h-5 w-5"></span>
                        <span x-text="submitting ? 'Creating Account…' : 'Create Account & View Booking'">Create Account &amp; View Booking</span>
                    </button>

                    <p class="text-center text-xs text-cc-muted pt-1">
                        Already have an account?
                        <button type="button" @click="switchMode('login')" class="font-bold text-cc-brown hover:underline">Sign in instead</button>
                    </p>
                </form>
            </div>

            {{-- Login Mode --}}
            <div x-show="mode === 'login'" x-cloak>
                <h2 id="account-modal-title" class="font-display text-2xl sm:text-3xl font-bold text-cc-deep">Sign in to your account</h2>
                <p class="mt-1.5 text-sm text-cc-muted">
                    Please enter your password for <strong class="font-mono text-cc-deep">{{ $pickupRequest->contact_phone }}</strong> to view and track your booking.
                </p>

                <form method="POST" action="{{ route('login.submit') }}" class="mt-5 space-y-4">
                    @csrf
                    <input type="hidden" name="redirect_to" value="{{ route('booking.confirmed', $pickupRequest->reference_no) }}">

                    <div>
                        <label class="cc-label">Mobile Number</label>
                        <input type="text" name="login" value="{{ $pickupRequest->contact_phone }}" readonly
                               class="cc-input mt-1.5 bg-gray-50 text-cc-deep cursor-not-allowed">
                    </div>

                    <div>
                        <label class="cc-label">Password <span class="text-cc-brown">*</span></label>
                        <div class="relative mt-1.5">
                            <input name="password" required autocomplete="current-password"
                                   :type="showPassword ? 'text' : 'password'" type="password"
                                   placeholder="Your account password" class="cc-input pr-12">
                            <button type="button" @click="showPassword = !showPassword"
                                    class="absolute top-1/2 right-1 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-cc-muted hover:text-cc-brown"
                                    :aria-label="showPassword ? 'Hide password' : 'Show password'">
                                <span data-lucide="eye" class="h-4.5 w-4.5" x-show="!showPassword"></span>
                                <span data-lucide="eyeOff" class="h-4.5 w-4.5" x-show="showPassword" x-cloak></span>
                            </button>
                        </div>
                    </div>

                    @if($errors->has('login'))
                        <p class="cc-error text-xs font-semibold">{{ $errors->first('login') }}</p>
                    @endif

                    <button type="submit" class="cc-btn w-full">
                        <span data-lucide="log-in" class="h-5 w-5"></span>
                        <span>Sign In &amp; View Booking</span>
                    </button>

                    <p class="text-center text-xs text-cc-muted pt-1">
                        Need to create an account?
                        <button type="button" @click="switchMode('register')" class="font-bold text-cc-brown hover:underline">Create an account</button>
                    </p>
                </form>
            </div>

        </div>
    </div>
    @endunless
</section>
<form id="tracking-refresh-form" method="POST" action="{{ route('track.status') }}" class="hidden" aria-hidden="true">
    @csrf
    <input type="hidden" name="reference_no" value="{{ $pickupRequest->reference_no }}">
    <input type="hidden" name="phone" value="{{ $pickupRequest->contact_phone }}">
</form>
@endsection

@push('scripts')
    @include('partials.booking-live-refresh')
@endpush
