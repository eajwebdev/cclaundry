@php
    /**
     * Public pickup & delivery booking form, laid out as the phone screens a
     * customer steps through: service & weight, their details, the schedule,
     * then a review before anything is sent. Field names and validation are
     * unchanged; only the order and the presentation follow the mockups.
     *
     * Prefill order: what the visitor just typed (a validation bounce) beats
     * the signed-in customer's saved details, which beats empty. No account is
     * needed to book, so a guest simply starts from empty.
     */
    $bookingCustomer = auth('customer')->user();

    $value = fn (string $key, $fallback = '') => old($key, $fallback);

    $defaultBranchId = $value('branch_id', $bookingCustomer?->branch_id ?: $branches->first()?->id);
    $multipleBranches = $branches->count() > 1;

    $stepLabels = [1 => 'Service & weight', 2 => 'Your details', 3 => 'Pickup schedule', 4 => 'Review'];

    // Second line under each step in the desktop side panel.
    $stepHints = [1 => 'What and how much', 2 => 'Contact and address', 3 => 'Date, time and return', 4 => 'Check, then confirm'];

    // Which step holds the first thing the server complained about, so a bounced
    // submission reopens where the problem is instead of back at step one.
    $stepFields = [
        1 => ['offering', 'estimated_kilos', 'is_rush'],
        2 => ['contact_name', 'contact_phone', 'contact_email', 'branch_id', 'pickup_address', 'pickup_landmark', 'pickup_latitude', 'pickup_longitude'],
        3 => ['pickup_date', 'pickup_slot', 'delivery_preference', 'delivery_address', 'delivery_latitude', 'delivery_longitude', 'delivery_date', 'delivery_slot', 'notes'],
    ];

    $initialStep = 1;
    foreach ($stepFields as $step => $fields) {
        if ($errors->hasAny($fields)) {
            $initialStep = $step;
            break;
        }
    }

    // Quick picks for the weight question. Each stores the top of its range so
    // the estimate leans towards what the customer will actually pay.
    $weightOptions = [
        ['label' => '5–8 kg', 'value' => '8'],
        ['label' => '9–12 kg', 'value' => '12'],
        ['label' => '13–16 kg', 'value' => '16'],
        ['label' => '17+ kg', 'value' => '17'],
    ];

    // Mirrors App\Support\Booking::estimate so the live figure and the stored
    // estimate cannot disagree.
    $offeringMeta = $offerings->map(fn ($offering) => [
        'key' => $offering['key'],
        'label' => $offering['name'],
        'price' => $offering['price'],
        'pricingType' => $offering['pricing_type'],
        'unit' => $offering['unit'],
    ])->values();

    $defaultOffering = $value('offering', $offerings->first()['key'] ?? '');

    $peso = fn ($amount) => '₱'.number_format((float) $amount, fmod((float) $amount, 1) ? 2 : 0);

    // The review screen: [icon, label, Alpine expression, step to edit, shown when].
    $reviewRows = [
        ['user', 'Customer', 'form.contact_name', 2, null],
        ['phone', 'Phone', 'form.contact_phone', 2, null],
        ['map-pin', 'Pickup Address', 'addressLabel', 2, null],
        ['scale', 'Weight (estimated)', 'weightLabel', 1, null],
        ['laundry', 'Service', 'serviceLine', 1, null],
        ['calendar-days', 'Pickup Schedule', 'pickupLabel', 3, null],
        ['truck', 'Pickup & Delivery', 'returnLabel', 3, null],
    ];

    if ($multipleBranches) {
        $reviewRows[] = ['store', 'Branch', 'branchLabel', 2, null];
    }

    $reviewRows[] = ['zap', 'Rush service', "'+' + money(rushSurcharge)", 1, 'form.is_rush'];
    $reviewRows[] = ['sticky-note', 'Special instructions', 'form.notes', 3, 'form.notes'];
@endphp

<section id="book" class="scroll-mt-20 px-4 pt-16 sm:pt-20 lg:scroll-mt-24 lg:pt-28">
    <div class="mx-auto max-w-xl {{ $offerings->isEmpty() ? '' : 'lg:max-w-6xl' }}">

        @if($offerings->isEmpty())
            <div class="cc-card px-6 py-12 text-center">
                <span class="cc-icon-tile mx-auto h-14 w-14"><span data-lucide="timer" class="h-6 w-6"></span></span>
                <h2 class="cc-title mt-5 text-2xl sm:text-3xl">Online booking is paused</h2>
                <p class="cc-subtitle mx-auto mt-2 max-w-sm">
                    No services are published for online booking yet. Please call the branch and we will arrange your pickup.
                </p>
                @if($settings?->contact_number)
                    <a href="tel:{{ preg_replace('/\s+/', '', $settings->contact_number) }}" class="cc-btn mt-6">
                        <span data-lucide="phone" class="h-4 w-4"></span>
                        {{ $settings->contact_number }}
                    </a>
                @endif
            </div>
        @else
        <form
            method="POST"
            action="{{ route('booking.store') }}"
            x-data="bookingForm({
                step: {{ $initialStep }},
                stepLabels: {{ Js::from($stepLabels) }},
                offerings: {{ Js::from($offeringMeta) }},
                weights: {{ Js::from($weightOptions) }},
                rushSurcharge: {{ (int) $rushSurcharge }},
                branches: {{ Js::from($branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])) }},
                slots: {{ Js::from($slots) }},
                tokenUrl: @js(route('csrf.token')),
                earliestPickupDate: @js($earliestPickupDate),
                latestPickupDate: @js($latestPickupDate),
                requiresBranch: {{ $multipleBranches ? 'true' : 'false' }},
                initial: {
                    offering: @js((string) $defaultOffering),
                    estimated_kilos: @js((string) $value('estimated_kilos', '')),
                    is_rush: {{ $value('is_rush') ? 'true' : 'false' }},
                    branch_id: @js((string) $defaultBranchId),
                    contact_name: @js((string) $value('contact_name', $bookingCustomer?->name ?? '')),
                    contact_phone: @js((string) $value('contact_phone', $bookingCustomer?->phone ?? '')),
                    contact_email: @js((string) $value('contact_email', $bookingCustomer?->email ?? '')),
                    pickup_address: @js((string) $value('pickup_address', $bookingCustomer?->address ?? '')),
                    pickup_landmark: @js((string) $value('pickup_landmark', '')),
                    pickup_date: @js((string) $value('pickup_date', $earliestPickupDate)),
                    pickup_slot: @js((string) $value('pickup_slot', 'morning')),
                    delivery_preference: @js((string) $value('delivery_preference', 'deliver')),
                    delivery_address: @js((string) $value('delivery_address', '')),
                    delivery_date: @js((string) $value('delivery_date', '')),
                    delivery_slot: @js((string) $value('delivery_slot', '')),
                    notes: @js((string) $value('notes', '')),
                }
            })"
            @preselect-offering.window="pick($event.detail)"
            @preselect-branch.window="form.branch_id = String($event.detail); openStep(2)"
            @keydown.enter="onEnter($event)"
            @submit.prevent="submit()"
            novalidate
            class="lg:grid lg:grid-cols-[minmax(0,21rem)_minmax(0,1fr)] lg:items-start lg:gap-10"
        >
            @csrf

            {{-- ─────────── Desktop side panel ─────────── --}}
            {{-- Phones get the progress bar inside the card. A wide screen has room
                 for the steps by name and a running summary that stays in view. --}}
            <aside class="hidden lg:sticky lg:top-28 lg:block">
                <p class="text-xs font-bold tracking-[0.3em] text-cc-brown uppercase">Book a pickup</p>
                <h2 class="cc-title mt-2">Laundry day, handled in a minute.</h2>
                <p class="cc-subtitle mt-2">Four quick steps. Nothing is charged until your bag is weighed at the branch.</p>

                <ol class="mt-7 space-y-1.5">
                    @foreach ($stepLabels as $n => $label)
                        <li>
                            <button type="button" @click="goTo({{ $n }})" :disabled="step <= {{ $n }}"
                                    class="flex w-full items-center gap-3.5 rounded-2xl px-3 py-2.5 text-left transition disabled:cursor-default"
                                    :class="step === {{ $n }} ? 'bg-cc-surface ring-1 ring-cc-line shadow-[0_14px_30px_-24px_rgba(74,47,31,0.6)]' : (step > {{ $n }} ? 'hover:bg-cc-soft' : '')">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 text-sm font-bold transition-colors"
                                      :class="step > {{ $n }} ? 'border-cc-brown bg-cc-brown text-white' : (step === {{ $n }} ? 'border-cc-brown bg-cc-surface text-cc-brown' : 'border-cc-line text-cc-muted')">
                                    <span x-show="step > {{ $n }}" x-cloak class="flex"><span data-lucide="check" class="h-4 w-4"></span></span>
                                    <span x-show="step <= {{ $n }}">{{ $n }}</span>
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-bold" :class="step >= {{ $n }} ? 'text-cc-deep' : 'text-cc-muted'">{{ $label }}</span>
                                    <span class="block text-xs text-cc-muted">{{ $stepHints[$n] }}</span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ol>

                <div class="cc-card mt-6 p-5">
                    <p class="text-xs font-bold tracking-[0.2em] text-cc-brown uppercase">Your booking so far</p>
                    <dl class="mt-3 space-y-3">
                        @foreach ([
                            ['laundry', 'Service', "service ? service.label : ''"],
                            ['scale', 'Weight', 'weightLabel'],
                            ['calendar-days', 'Pickup', 'pickupLabel'],
                            ['truck', 'Return', 'returnLabel'],
                        ] as [$icon, $label, $expression])
                            <div class="flex items-start gap-3">
                                <span data-lucide="{{ $icon }}" class="mt-0.5 h-4.5 w-4.5 shrink-0 text-cc-brown"></span>
                                <div class="min-w-0">
                                    <dt class="text-[11px] font-semibold text-cc-muted">{{ $label }}</dt>
                                    <dd class="text-sm font-bold wrap-break-word text-cc-deep" x-text="({{ $expression }}) || '—'"></dd>
                                </div>
                            </div>
                        @endforeach
                    </dl>

                    <div class="mt-4 flex items-end justify-between gap-3 border-t border-cc-line pt-4">
                        <span>
                            <span class="block text-sm font-bold text-cc-deep">Estimated total</span>
                            <span class="block text-[11px] text-cc-muted"
                                  x-text="form.is_rush ? 'Includes rush +' + money(rushSurcharge) : 'Confirmed after weighing'">Confirmed after weighing</span>
                        </span>
                        <span class="font-display text-[2.2rem] leading-none font-bold text-cc-deep" x-text="money(estimate)"></span>
                    </div>
                </div>

                <ul class="mt-5 space-y-2 px-1 text-xs font-semibold text-cc-muted">
                    <li class="flex items-center gap-2">
                        <span data-lucide="truck" class="h-4 w-4 shrink-0 text-cc-brown"></span>
                        Free pickup &amp; delivery from 5 kg
                    </li>
                    <li class="flex items-center gap-2">
                        <span data-lucide="shieldCheck" class="h-4 w-4 shrink-0 text-cc-brown"></span>
                        No payment needed to book
                    </li>
                </ul>
            </aside>

            <div x-ref="card" class="cc-card scroll-mt-20 p-5 sm:p-8 lg:scroll-mt-28 lg:p-10">

                {{-- ─────────── Progress ─────────── --}}
                <div class="lg:hidden">
                    <div class="flex items-center justify-between gap-3 text-xs font-bold">
                        <span class="text-cc-muted" x-text="'Step ' + step + ' of ' + totalSteps">Step {{ $initialStep }} of 4</span>
                        <span class="text-cc-brown" x-text="stepLabels[step]">{{ $stepLabels[$initialStep] }}</span>
                    </div>
                    <div class="mt-2 grid grid-cols-4 gap-1.5">
                        @foreach ($stepLabels as $n => $label)
                            <button type="button" @click="goTo({{ $n }})" aria-label="{{ $label }}"
                                    class="h-1.5 rounded-full transition-colors"
                                    :class="step >= {{ $n }} ? 'bg-cc-brown' : 'bg-cc-line'"></button>
                        @endforeach
                    </div>
                </div>

                {{-- ─────────── What still needs fixing ─────────── --}}
                {{-- Repeated at the top of the card because on a phone the field
                     itself can be off-screen when Continue is tapped. --}}
                <div x-show="hasErrors" x-cloak role="alert"
                     class="mt-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3.5 text-sm text-red-900">
                    <p class="flex items-center gap-2 font-bold">
                        <span data-lucide="alertTriangle" class="h-4 w-4 shrink-0"></span>
                        Please check these before you continue
                    </p>
                    <ul class="mt-1.5 list-disc space-y-0.5 pl-5">
                        <template x-for="message in errorList" :key="message">
                            <li x-text="message"></li>
                        </template>
                    </ul>
                </div>

                {{-- ═══ Step 1: service & weight ═══ --}}
                <div data-step="1" x-show="step === 1" class="mt-6 lg:mt-0">
                    <h2 class="cc-title text-[1.7rem] sm:text-3xl">Book Your Laundry Pickup</h2>
                    <p class="cc-subtitle mt-1.5">
                        @if($bookingCustomer)
                            Booking as <span class="font-bold text-cc-brown">{{ $bookingCustomer->name }}</span>. We have filled in what we know.
                        @else
                            Tell us what you need and we&rsquo;ll take care of the rest.
                        @endif
                    </p>

                    <fieldset class="mt-6">
                        <legend class="cc-label text-[15px]">1. How much laundry do you have?</legend>
                        <p class="cc-help mt-1">Choose an estimated weight &mdash; we&rsquo;ll confirm the exact weight upon pickup.</p>

                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($weightOptions as $option)
                                <button type="button" @click="form.estimated_kilos = '{{ $option['value'] }}'"
                                        class="cc-chip" :class="{ 'cc-chip-active': isWeight('{{ $option['value'] }}') }"
                                        :aria-pressed="isWeight('{{ $option['value'] }}')">{{ $option['label'] }}</button>
                            @endforeach
                            <button type="button" @click="form.estimated_kilos = ''"
                                    class="cc-chip" :class="{ 'cc-chip-active': ! hasWeight }"
                                    :aria-pressed="! hasWeight">I&rsquo;m not sure</button>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
                            <label for="estimated_kilos" class="cc-help font-semibold">Or type the exact weight</label>
                            <div class="relative w-32">
                                <input id="estimated_kilos" type="number" name="estimated_kilos" inputmode="decimal"
                                       step="0.5" min="1" max="200" x-model="form.estimated_kilos" placeholder="e.g. 7"
                                       class="cc-input min-h-11 py-2 pr-10 @error('estimated_kilos') cc-input-invalid @enderror"
                                       :class="errors.estimated_kilos && 'cc-input-invalid'">
                                <span class="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-xs font-bold text-cc-muted">kg</span>
                            </div>
                        </div>
                        @error('estimated_kilos')<p class="cc-error">{{ $message }}</p>@else<p x-show="errors.estimated_kilos" x-cloak class="cc-error" x-text="errors.estimated_kilos"></p>@enderror
                    </fieldset>

                    <fieldset class="mt-7">
                        <legend class="cc-label text-[15px]">2. What would you like us to clean? <span class="text-cc-brown">*</span></legend>
                        <p class="cc-help mt-1">Pick the closest match &mdash; we&rsquo;ll sort the rest when we weigh your bag.</p>

                        <div class="mt-3 space-y-2.5 lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0">
                            @foreach ($offerings as $offering)
                                <label class="cc-option" :class="{ 'cc-option-active': form.offering === '{{ $offering['key'] }}' }">
                                    <input type="radio" name="offering" value="{{ $offering['key'] }}" x-model="form.offering" class="sr-only">
                                    <span class="cc-check" aria-hidden="true"><span data-lucide="check" class="h-3.5 w-3.5"></span></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                                            <span class="text-[15px] font-bold text-cc-deep">
                                                {{ $offering['name'] }}
                                                @if($offering['type'] === 'preset')
                                                    <span class="ml-1 rounded-full bg-cc-soft px-1.5 py-0.5 align-middle text-[9px] font-bold tracking-wide text-cc-brown uppercase">Bundle</span>
                                                @endif
                                            </span>
                                            <span class="text-sm font-bold whitespace-nowrap text-cc-brown">
                                                {{ $peso($offering['price']) }} <span class="font-semibold text-cc-muted">{{ $offering['unit'] }}</span>
                                            </span>
                                        </span>
                                        @if($offering['includes'])
                                            <span class="mt-0.5 block text-xs leading-relaxed text-cc-muted">{{ implode(' + ', $offering['includes']) }}</span>
                                        @elseif($offering['blurb'])
                                            <span class="mt-0.5 block text-xs leading-relaxed text-cc-muted">{{ $offering['blurb'] }}</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('offering')<p class="cc-error">{{ $message }}</p>@else<p x-show="errors.offering" x-cloak class="cc-error" x-text="errors.offering"></p>@enderror
                    </fieldset>

                    <label class="cc-option mt-5" :class="{ 'cc-option-active': form.is_rush }">
                        <input type="checkbox" name="is_rush" value="1" x-model="form.is_rush" class="cc-checkbox mt-0.5">
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1.5 text-[15px] font-bold text-cc-deep">
                                <span data-lucide="zap" class="h-4 w-4 text-cc-brown"></span>
                                Need it sooner?
                            </span>
                            <span class="mt-0.5 block text-xs text-cc-muted">Rush service jumps the queue for same-day handling (+{{ $peso($rushSurcharge) }}).</span>
                        </span>
                    </label>

                    <div class="cc-soft mt-5 flex items-center justify-between gap-3 px-4 py-3">
                        <span class="text-sm font-bold text-cc-muted">Estimated total</span>
                        <span class="font-display text-2xl leading-none font-bold text-cc-deep" x-text="money(estimate)"></span>
                    </div>
                </div>

                {{-- ═══ Step 2: customer details ═══ --}}
                <div data-step="2" x-show="step === 2" x-cloak class="mt-6 lg:mt-0">
                    <h2 class="cc-title text-[1.7rem] sm:text-3xl">Customer Details</h2>
                    <p class="cc-subtitle mt-1.5">Please provide your details so we can contact you.</p>

                    <div class="mt-6 space-y-4">
                        <div>
                            <label for="contact_name" class="cc-label">Full Name <span class="text-cc-brown">*</span></label>
                            <input id="contact_name" type="text" name="contact_name" required autocomplete="name"
                                   x-model="form.contact_name" placeholder="e.g. Juan Dela Cruz"
                                   class="cc-input mt-1.5 @error('contact_name') cc-input-invalid @enderror"
                                   :class="errors.contact_name && 'cc-input-invalid'">
                            @error('contact_name')<p class="cc-error">{{ $message }}</p>@else<p x-show="errors.contact_name" x-cloak class="cc-error" x-text="errors.contact_name"></p>@enderror
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="contact_phone" class="cc-label">Mobile Number <span class="text-cc-brown">*</span></label>
                                <input id="contact_phone" type="tel" name="contact_phone" required inputmode="tel" autocomplete="tel"
                                       x-model="form.contact_phone" placeholder="e.g. 09XX XXX XXXX"
                                       class="cc-input mt-1.5 @error('contact_phone') cc-input-invalid @enderror"
                                       :class="errors.contact_phone && 'cc-input-invalid'">
                                @error('contact_phone')<p class="cc-error">{{ $message }}</p>@else<p x-show="errors.contact_phone" x-cloak class="cc-error" x-text="errors.contact_phone"></p>@enderror
                            </div>

                            <div>
                                <label for="contact_email" class="cc-label">Email <span class="font-semibold text-cc-muted">(optional)</span></label>
                                <input id="contact_email" type="email" name="contact_email" inputmode="email" autocomplete="email"
                                       x-model="form.contact_email" placeholder="you@example.com"
                                       class="cc-input mt-1.5 @error('contact_email') cc-input-invalid @enderror"
                                       :class="errors.contact_email && 'cc-input-invalid'">
                                @error('contact_email')<p class="cc-error">{{ $message }}</p>@else<p x-show="errors.contact_email" x-cloak class="cc-error" x-text="errors.contact_email"></p>@enderror
                            </div>
                        </div>

                        @if($multipleBranches)
                            <div>
                                <label for="branch_id" class="cc-label">Nearest Branch <span class="text-cc-brown">*</span></label>
                                <select id="branch_id" name="branch_id" required x-model="form.branch_id"
                                        class="cc-input mt-1.5 @error('branch_id') cc-input-invalid @enderror"
                                        :class="errors.branch_id && 'cc-input-invalid'">
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->name }}@if($branch->address) &mdash; {{ Str::limit($branch->address, 40) }}@endif</option>
                                    @endforeach
                                </select>
                                @error('branch_id')<p class="cc-error">{{ $message }}</p>@else<p x-show="errors.branch_id" x-cloak class="cc-error" x-text="errors.branch_id"></p>@enderror
                            </div>
                        @else
                            <input type="hidden" name="branch_id" x-model="form.branch_id">
                            @error('branch_id') <p class="cc-error">{{ $message }}</p> @enderror
                        @endif

                        <div>
                            <label for="pickup_address" class="cc-label">Pickup Address <span class="text-cc-brown">*</span></label>
                            <textarea id="pickup_address" name="pickup_address" rows="2" required autocomplete="street-address"
                                      x-model="form.pickup_address" placeholder="House / Street / Barangay"
                                      class="cc-input mt-1.5 @error('pickup_address') cc-input-invalid @enderror"
                                      :class="errors.pickup_address && 'cc-input-invalid'"></textarea>
                            @error('pickup_address')<p class="cc-error">{{ $message }}</p>@else<p x-show="errors.pickup_address" x-cloak class="cc-error" x-text="errors.pickup_address"></p>@enderror
                        </div>

                        <x-map-picker
                            name="pickup"
                            label="Pin your exact spot (optional)"
                            address-field="pickup_address"
                            height="h-56 sm:h-64"
                            :latitude="old('pickup_latitude', $bookingCustomer?->latitude)"
                            :longitude="old('pickup_longitude', $bookingCustomer?->longitude)"
                        />

                        <div>
                            <label for="pickup_landmark" class="cc-label">Landmark / Notes <span class="font-semibold text-cc-muted">(optional)</span></label>
                            <input id="pickup_landmark" type="text" name="pickup_landmark" x-model="form.pickup_landmark"
                                   placeholder="e.g. near the school, beside the church" class="cc-input mt-1.5">
                            @error('pickup_landmark') <p class="cc-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                {{-- ═══ Step 3: pickup schedule & return ═══ --}}
                <div data-step="3" x-show="step === 3" x-cloak class="mt-6 lg:mt-0">
                    <h2 class="cc-title text-[1.7rem] sm:text-3xl">Choose Your Pickup Schedule</h2>
                    <p class="cc-subtitle mt-1.5">Select your preferred date and time.</p>

                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="pickup_date" class="cc-label">Date <span class="text-cc-brown">*</span></label>
                            <div class="relative mt-1.5">
                                <span data-lucide="calendar-days" class="pointer-events-none absolute top-1/2 left-4 h-4.5 w-4.5 -translate-y-1/2 text-cc-brown"></span>
                                <input id="pickup_date" type="date" name="pickup_date" required x-model="form.pickup_date"
                                       min="{{ $earliestPickupDate }}" max="{{ $latestPickupDate }}"
                                       class="cc-input pl-11 @error('pickup_date') cc-input-invalid @enderror"
                                       :class="errors.pickup_date && 'cc-input-invalid'">
                            </div>
                            @error('pickup_date')<p class="cc-error">{{ $message }}</p>@else<p x-show="errors.pickup_date" x-cloak class="cc-error" x-text="errors.pickup_date"></p>@enderror
                        </div>

                        <div>
                            <label for="pickup_slot" class="cc-label">Preferred Time <span class="text-cc-brown">*</span></label>
                            <select id="pickup_slot" name="pickup_slot" required x-model="form.pickup_slot" class="cc-input mt-1.5">
                                @foreach ($slots as $slotKey => $slotLabel)
                                    <option value="{{ $slotKey }}">{{ $slotLabel }}</option>
                                @endforeach
                            </select>
                            @error('pickup_slot')<p class="cc-error">{{ $message }}</p>@else<p x-show="errors.pickup_slot" x-cloak class="cc-error" x-text="errors.pickup_slot"></p>@enderror
                        </div>
                    </div>

                    <ul class="cc-soft mt-5 space-y-3 px-4 py-4 lg:grid lg:grid-cols-2 lg:gap-4 lg:space-y-0 lg:px-5">
                        @foreach ([
                            ['truck', 'Free pickup & delivery', 'For 5 kg and above'],
                            ['scale', 'Minimum 5 kg', 'Per pickup'],
                            ['wallet', 'Please have payment ready', 'Our rider collects it when they pick up'],
                            ['time', 'We’ll confirm the exact time', $settings?->sms_enabled ? 'By SMS before the rider heads over' : 'With a call before the rider heads over'],
                        ] as [$icon, $title, $body])
                            <li class="flex items-center gap-3">
                                <span class="cc-icon-tile h-9 w-9 bg-none bg-cc-surface"><span data-lucide="{{ $icon }}" class="h-4.5 w-4.5"></span></span>
                                <span>
                                    <span class="block text-sm font-bold text-cc-deep">{{ $title }}</span>
                                    <span class="block text-xs text-cc-muted">{{ $body }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    <fieldset class="mt-7">
                        <legend class="cc-label text-[15px]">How should we return it? <span class="text-cc-brown">*</span></legend>

                        <div class="mt-3 grid grid-cols-2 gap-2.5">
                            @foreach ($deliveryPreferences as $prefKey => $prefLabel)
                                <label class="cc-option flex-col gap-2 p-3.5"
                                       :class="{ 'cc-option-active': form.delivery_preference === '{{ $prefKey }}' }">
                                    <input type="radio" name="delivery_preference" value="{{ $prefKey }}" x-model="form.delivery_preference" class="sr-only">
                                    <span class="cc-icon-tile h-10 w-10"><span data-lucide="{{ $prefKey === 'deliver' ? 'truck' : 'store' }}" class="h-5 w-5"></span></span>
                                    <span>
                                        <span class="block text-sm leading-snug font-bold text-cc-deep">{{ $prefLabel }}</span>
                                        <span class="mt-0.5 block text-xs text-cc-muted">{{ $prefKey === 'deliver' ? 'Free, right to your door' : 'Pick it up yourself' }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('delivery_preference')<p class="cc-error">{{ $message }}</p>@else<p x-show="errors.delivery_preference" x-cloak class="cc-error" x-text="errors.delivery_preference"></p>@enderror

                        <div x-show="form.delivery_preference === 'deliver'" x-cloak class="mt-4 space-y-4">
                            <label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm font-semibold text-cc-ink">
                                <input type="checkbox" x-model="sameAddress" class="cc-checkbox">
                                Deliver to the same address we collect from
                            </label>

                            <div x-show="! sameAddress" x-cloak class="space-y-4">
                                <div>
                                    <label for="delivery_address" class="cc-label">Delivery Address</label>
                                    <textarea id="delivery_address" name="delivery_address" rows="2" x-model="form.delivery_address"
                                              placeholder="House / Street / Barangay" class="cc-input mt-1.5"></textarea>
                                    @error('delivery_address') <p class="cc-error">{{ $message }}</p> @enderror
                                </div>

                                <x-map-picker
                                    name="delivery"
                                    label="Pin the drop-off spot (optional)"
                                    address-field="delivery_address"
                                    height="h-56 sm:h-64"
                                    :latitude="old('delivery_latitude')"
                                    :longitude="old('delivery_longitude')"
                                />
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="delivery_date" class="cc-label">Delivery Date <span class="font-semibold text-cc-muted">(optional)</span></label>
                                    <input id="delivery_date" type="date" name="delivery_date" x-model="form.delivery_date"
                                           :min="form.pickup_date" max="{{ $latestPickupDate }}"
                                           class="cc-input mt-1.5 @error('delivery_date') cc-input-invalid @enderror"
                                           :class="errors.delivery_date && 'cc-input-invalid'">
                                    @error('delivery_date')<p class="cc-error">{{ $message }}</p>@else<p x-show="errors.delivery_date" x-cloak class="cc-error" x-text="errors.delivery_date"></p>@enderror
                                </div>

                                <div>
                                    <label for="delivery_slot" class="cc-label">Delivery Time <span class="font-semibold text-cc-muted">(optional)</span></label>
                                    <select id="delivery_slot" name="delivery_slot" x-model="form.delivery_slot" class="cc-input mt-1.5">
                                        <option value="">No preference</option>
                                        @foreach ($slots as $slotKey => $slotLabel)
                                            <option value="{{ $slotKey }}">{{ $slotLabel }}</option>
                                        @endforeach
                                    </select>
                                    @error('delivery_slot') <p class="cc-error">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>
                    </fieldset>

                    <div class="mt-6">
                        <label for="notes" class="cc-label">Special Instructions <span class="font-semibold text-cc-muted">(optional)</span></label>
                        <textarea id="notes" name="notes" rows="3" x-model="form.notes"
                                  placeholder="Separate the whites, call before entering the subdivision…"
                                  class="cc-input mt-1.5"></textarea>
                        @error('notes') <p class="cc-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- ═══ Step 4: review ═══ --}}
                <div data-step="4" x-show="step === 4" x-cloak class="mt-6 lg:mt-0">
                    <h2 class="cc-title text-[1.7rem] sm:text-3xl">Review Your Booking</h2>
                    <p class="cc-subtitle mt-1.5">Please check your details before confirming.</p>

                    <ul class="mt-5 divide-y divide-cc-line rounded-2xl border border-cc-line bg-white/60 px-4">
                        @foreach ($reviewRows as [$icon, $label, $expression, $editStep, $visibleWhen])
                            <li class="flex items-start gap-3 py-3" @if($visibleWhen) x-show="{{ $visibleWhen }}" x-cloak @endif>
                                <span data-lucide="{{ $icon }}" class="mt-0.5 h-5 w-5 shrink-0 text-cc-brown"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-semibold text-cc-muted">{{ $label }}</p>
                                    <p class="mt-0.5 text-sm font-bold wrap-break-word text-cc-deep" x-text="({{ $expression }}) || '—'"></p>
                                </div>
                                <button type="button" @click="goTo({{ $editStep }})"
                                        class="-mr-2 shrink-0 rounded-full px-3 py-1.5 text-xs font-bold text-cc-brown transition hover:bg-cc-soft">Edit</button>
                            </li>
                        @endforeach
                    </ul>

                    <div class="cc-soft mt-4 px-4 py-4">
                        <p class="text-sm font-bold text-cc-deep">Estimated Total</p>
                        <p class="mt-1.5 font-display text-[2.6rem] leading-none font-bold text-cc-deep" x-text="money(estimate)"></p>
                        <p class="mt-2 flex items-start gap-1.5 text-xs text-cc-muted">
                            <span data-lucide="info" class="mt-px h-3.5 w-3.5 shrink-0"></span>
                            Final amount will be based on the actual laundry weight.
                        </p>
                    </div>

                    @unless($bookingCustomer)
                        <div class="mt-4 flex items-start gap-3 rounded-2xl border border-cc-line bg-cc-surface px-4 py-3.5">
                            <span data-lucide="lock" class="mt-0.5 h-4 w-4 shrink-0 text-cc-brown"></span>
                            <p class="text-[13px] leading-relaxed text-cc-muted">
                                <span class="font-bold text-cc-deep">One last step after this.</span>
                                Bookings are tied to an account so you can track and cancel them. We&rsquo;ll ask you to set a
                                password next &mdash; everything you filled in here is kept.
                            </p>
                        </div>
                    @endunless
                </div>

                {{-- ─────────── Navigation ─────────── --}}
                <div class="mt-7 flex items-center gap-3 lg:mt-8 lg:justify-end lg:border-t lg:border-cc-line lg:pt-6">
                    <button type="button" @click="back()" x-show="step > 1" x-cloak
                            class="cc-btn-outline w-12 shrink-0 px-0 lg:mr-auto lg:w-auto lg:px-5" aria-label="Back to the previous step">
                        <span data-lucide="arrow-left" class="h-5 w-5"></span>
                        <span class="hidden lg:inline">Back</span>
                    </button>

                    <button type="button" @click="next()" x-show="step < totalSteps" class="cc-btn flex-1 lg:flex-none lg:px-10">
                        Continue
                        <span data-lucide="arrow-right" class="h-4 w-4"></span>
                    </button>

                    <button type="submit" x-show="step === totalSteps" x-cloak
                            :disabled="submitting" :class="submitting && 'pointer-events-none opacity-70'"
                            class="cc-btn flex-1 lg:flex-none lg:px-10">
                        <span x-text="submitting ? 'Booking…' : 'Confirm Pickup'">Confirm Pickup</span>
                        <span data-lucide="arrow-right" class="h-4 w-4"></span>
                    </button>
                </div>

                <p class="mt-4 text-center text-xs text-cc-muted lg:text-right">No payment needed to book &middot; Cancel any time before collection</p>
            </div>
        </form>
        @endif
    </div>
</section>

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('bookingForm', (config) => ({
            step: config.step,
            stepLabels: config.stepLabels,
            totalSteps: Object.keys(config.stepLabels).length,
            offerings: config.offerings,
            weights: config.weights,
            branches: config.branches,
            slots: config.slots,
            rushSurcharge: config.rushSurcharge,
            tokenUrl: config.tokenUrl,
            earliest: config.earliestPickupDate,
            latest: config.latestPickupDate,
            requiresBranch: config.requiresBranch,
            // Field name => message, for whatever the customer has not filled in
            // correctly yet. Server-side errors are rendered by Blade instead.
            errors: {},
            submitting: false,
            form: { ...config.initial },
            // Default to a single address; unticking reveals a separate one.
            sameAddress: ! config.initial.delivery_address,

            init() {
                // Keep the hidden delivery field in step with the pickup address
                // for as long as the customer wants one address.
                this.$watch('sameAddress', (same) => {
                    if (same) this.form.delivery_address = '';
                });
            },

            get service() {
                return this.offerings.find((o) => o.key === this.form.offering) || this.offerings[0];
            },

            get serviceLine() {
                const service = this.service;
                return service ? service.label + ' · ' + this.money(service.price) + ' ' + service.unit : '';
            },

            get branchLabel() {
                const branch = this.branches.find((b) => String(b.id) === String(this.form.branch_id));
                return branch ? branch.name : '';
            },

            get hasWeight() {
                return parseFloat(this.form.estimated_kilos) > 0;
            },

            isWeight(value) {
                return this.hasWeight && parseFloat(this.form.estimated_kilos) === parseFloat(value);
            },

            get weightLabel() {
                if (! this.hasWeight) return 'Not sure yet — we will weigh it';
                const preset = this.weights.find((weight) => this.isWeight(weight.value));
                return preset ? preset.label : parseFloat(this.form.estimated_kilos) + ' kg';
            },

            get addressLabel() {
                if (! this.form.pickup_address) return '';
                return this.form.pickup_address + (this.form.pickup_landmark ? ' (' + this.form.pickup_landmark + ')' : '');
            },

            get pickupLabel() {
                if (! this.form.pickup_date) return '';
                return this.formatDate(this.form.pickup_date) + ' • ' + (this.slots[this.form.pickup_slot] || '');
            },

            get returnLabel() {
                if (this.form.delivery_preference !== 'deliver') {
                    return 'Claim at ' + (this.branchLabel || 'the branch');
                }

                let label = 'Free delivery back to you';
                if (this.form.delivery_date) label += ' • ' + this.formatDate(this.form.delivery_date);
                if (this.form.delivery_slot) label += ' • ' + (this.slots[this.form.delivery_slot] || '');
                return label;
            },

            /** Mirrors App\Support\Booking::estimate so the two never disagree. */
            get estimate() {
                const service = this.service;
                if (! service) return 0;

                const kilos = parseFloat(this.form.estimated_kilos);
                let total;

                // A bundle is a whole-package price; weight does not multiply it.
                if (service.pricingType === 'preset') {
                    total = service.price;
                } else if (service.pricingType === 'kilo') {
                    total = service.price * Math.max(1, kilos || 1);
                } else if (service.pricingType === 'load') {
                    const perLoad = {{ (int) \App\Support\Booking::KILOS_PER_LOAD }};
                    total = service.price * Math.max(1, Math.ceil((kilos || perLoad) / perLoad));
                } else {
                    // Piece and custom pricing cannot be inferred from a weight
                    // guess, so we quote one unit and the branch confirms.
                    total = service.price;
                }

                return Math.round((this.form.is_rush ? total + this.rushSurcharge : total) * 100) / 100;
            },

            money(value) {
                const amount = Number(value) || 0;
                return '₱' + amount.toLocaleString('en-PH', {
                    minimumFractionDigits: amount % 1 ? 2 : 0,
                    maximumFractionDigits: 2,
                });
            },

            formatDate(value) {
                const date = new Date(value + 'T00:00:00');
                if (isNaN(date)) return value;
                return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
            },

            pick(offeringKey) {
                if (this.offerings.some((o) => o.key === offeringKey)) {
                    this.form.offering = offeringKey;
                }
                this.step = 1;
            },

            openStep(target) {
                this.step = target;
                this.focusSection();
            },

            goTo(target) {
                // Only allow jumping back to a step already completed.
                if (target < this.step) this.openStep(target);
            },

            back() {
                if (this.step > 1) this.openStep(this.step - 1);
            },

            next() {
                if (! this.validateStep()) {
                    this.focusFirstError();
                    return;
                }

                if (this.step < this.totalSteps) this.openStep(this.step + 1);
            },

            /**
             * Submit is ours rather than the browser's: native validation cannot
             * report a problem sitting on a step that is currently display:none,
             * so it would silently refuse to submit with nothing shown.
             */
            async submit() {
                for (const step of [1, 2, 3]) {
                    if (! this.validateStep(step)) {
                        this.openStep(step);
                        this.focusFirstError();
                        return;
                    }
                }

                if (this.submitting) return;
                this.submitting = true;

                // A form left open outlives its CSRF token (and another tab
                // signing in rotates it), which is what turns a finished booking
                // into a blank 419 "Page Expired". Take a fresh one first.
                try {
                    const response = await fetch(this.tokenUrl, {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    });

                    if (response.ok) {
                        const { token } = await response.json();
                        const field = this.$el.querySelector('input[name="_token"]');

                        if (token && field) field.value = token;
                    }
                } catch {
                    // Offline or blocked: submit with the token we have and let
                    // the server's own 419 recovery hand the form back.
                }

                this.$el.submit();
            },

            /**
             * What each step needs before it can be left, mirroring the rules in
             * BookingController::validateBooking so the two cannot disagree.
             */
            get checks() {
                const filled = (value) => String(value ?? '').trim() !== '';

                return {
                    1: [
                        ['offering', 'Choose what you would like us to clean.', () => filled(this.form.offering)],
                        ['estimated_kilos', 'Enter a weight between 1 and 200 kg, or tap “I’m not sure”.', () => {
                            if (! filled(this.form.estimated_kilos)) return true;
                            const kilos = parseFloat(this.form.estimated_kilos);
                            return kilos >= 1 && kilos <= 200;
                        }],
                    ],
                    2: [
                        ['contact_name', 'Enter your full name so we know who to ask for.', () => filled(this.form.contact_name)],
                        ['contact_phone', 'Enter a mobile number we can reach you on, like 0917 123 4567.', () => this.isMobile(this.form.contact_phone)],
                        ['contact_email', 'That email address does not look right.', () => ! filled(this.form.contact_email) || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.form.contact_email.trim())],
                        ['branch_id', 'Choose the branch nearest you.', () => ! this.requiresBranch || filled(this.form.branch_id)],
                        ['pickup_address', 'Tell us where to collect: house, street and barangay.', () => filled(this.form.pickup_address)],
                    ],
                    3: [
                        ['pickup_date', 'Choose a pickup date from ' + this.formatDate(this.earliest) + ' onwards.', () =>
                            filled(this.form.pickup_date) && this.form.pickup_date >= this.earliest && this.form.pickup_date <= this.latest],
                        ['pickup_slot', 'Choose the time that suits you.', () => filled(this.form.pickup_slot)],
                        ['delivery_preference', 'Tell us how you want your laundry back.', () => filled(this.form.delivery_preference)],
                        ['delivery_date', 'Delivery cannot be earlier than the pickup.', () =>
                            ! filled(this.form.delivery_date) || this.form.delivery_date >= this.form.pickup_date],
                    ],
                    4: [],
                };
            },

            /** Mirrors Customer::normalizePhone, so the two never disagree. */
            isMobile(value) {
                let digits = String(value ?? '').replace(/\D+/g, '');

                if (digits.startsWith('63') && digits.length === 12) digits = '0' + digits.slice(2);
                if (digits.startsWith('9') && digits.length === 10) digits = '0' + digits;

                return /^09\d{9}$/.test(digits);
            },

            validateStep(step = this.step) {
                const checks = this.checks[step] ?? [];

                // Only this step's messages are rebuilt, so a problem waiting on
                // another step is not wiped before the customer has fixed it.
                for (const [field] of checks) delete this.errors[field];

                for (const [field, message, passes] of checks) {
                    if (! passes()) this.errors[field] = message;
                }

                return checks.every(([field]) => ! this.errors[field]);
            },

            get hasErrors() {
                return Object.keys(this.errors).length > 0;
            },

            get errorList() {
                return Object.values(this.errors);
            },

            focusFirstError() {
                this.$nextTick(() => {
                    const field = Object.keys(this.errors)[0];
                    const el = field && this.$el.querySelector('[name="' + field + '"]:not([type="hidden"])');

                    if (el && el.offsetParent !== null) el.focus();
                });
            },

            /**
             * Enter on a phone keyboard means "next", not "submit a booking that
             * is three screens from finished". Map search keeps Enter to itself.
             */
            onEnter(event) {
                const target = event.target;
                if (target.tagName === 'TEXTAREA' || target.tagName === 'BUTTON') return;
                if (this.step >= this.totalSteps) return;

                event.preventDefault();
                if (target.type !== 'search') this.next();
            },

            focusSection() {
                this.$nextTick(() => {
                    window.renderLucideIcons?.();

                    const card = this.$refs.card;
                    if (! card) return;

                    const top = card.getBoundingClientRect().top;
                    if (top < 0 || top > window.innerHeight * 0.5) {
                        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            },
        }));
    });
</script>
@endpush
