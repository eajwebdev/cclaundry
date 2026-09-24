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

    // Each branch runs its own pickup windows. The form starts on the default
    // branch's and swaps them when the customer picks another branch.
    $slots = $slotsByBranch[(int) $defaultBranchId] ?? \App\Support\Booking::slots();
    $slotCutoffs = collect($slotsByBranch)
        ->reduce(fn (array $cutoffs, array $branchSlots) => $cutoffs + \App\Support\Booking::slotCutoffs($branchSlots), \App\Support\Booking::slotCutoffs($slots));

    // The weight promises, from Settings. Blank hides the line.
    $kilos = fn ($amount) => rtrim(rtrim(number_format((float) $amount, 2), '0'), '.');
    $freeDeliveryKilos = filled($settings?->free_delivery_minimum_kilos) ? $kilos($settings->free_delivery_minimum_kilos) : null;

    $stepLabels = [1 => 'Laundry Details', 2 => 'Your Details', 3 => 'Pickup Schedule', 4 => 'Review & Confirm'];

    // Second line under each step in the desktop side panel.
    $stepHints = [1 => 'What you are sending', 2 => 'Contact and address', 3 => 'Choose your preferred date and time', 4 => 'Check your order before submitting'];

    // Which step holds the first thing the server complained about, so a bounced
    // submission reopens where the problem is instead of back at step one.
    $stepFields = [
        1 => ['items'],
        2 => ['contact_name', 'contact_phone', 'contact_email', 'branch_id', 'pickup_address', 'pickup_landmark', 'pickup_latitude', 'pickup_longitude'],
        3 => ['pickup_date', 'pickup_slot', 'delivery_preference', 'delivery_address', 'delivery_latitude', 'delivery_longitude', 'delivery_date', 'delivery_slot', 'payment_method', 'notes'],
    ];

    // A complaint about one line arrives as "items.0.quantity", which belongs to
    // whichever step owns "items" rather than to no step at all.
    $errorKeys = collect($errors->keys());
    $stepHasError = fn (array $fields) => $errorKeys->contains(fn (string $key) => collect($fields)
        ->contains(fn (string $field) => $key === $field || str_starts_with($key, $field.'.')));

    $initialStep = 1;
    foreach ($stepFields as $step => $fields) {
        if ($stepHasError($fields)) {
            $initialStep = $step;
            break;
        }
    }

    // Whatever the server said about the chosen services, in one message.
    $itemsError = $errors->first('items')
        ?: $errorKeys->filter(fn (string $key) => str_starts_with($key, 'items.'))
            ->map(fn (string $key) => $errors->first($key))
            ->first();

    // Everything bookable in one shape for the script: the services, then the
    // add-ons. `unitNoun` is what the customer is asked for — kilos for what we
    // weigh, pairs for steaming, loads for detergent or conditioner.
    $toMeta = fn (array $offering, bool $isAddon) => [
        'key' => $offering['key'],
        'label' => $offering['name'],
        'price' => $offering['price'],
        'pricingType' => $offering['pricing_type'],
        'minimumKilos' => $isAddon ? null : ($offering['minimum_kilos'] ?? null),
        'kilosPerLoad' => $offering['kilos_per_load'] ?? null,
        'unit' => $offering['unit'],
        'unitNoun' => $isAddon ? 'load' : \App\Support\Booking::unitFor($offering['pricing_type']),
        'isAddon' => $isAddon,
        'addonGroup' => $isAddon ? ($offering['report_category'] ?? null) : null,
    ];

    // Mirrors App\Support\Booking::estimate so the live figure and the stored
    // estimate cannot disagree.
    $bookableMeta = $offerings->map(fn ($offering) => $toMeta($offering, false))
        ->concat($addons->map(fn ($addon) => $toMeta($addon, true)))
        ->values();

    // What the visitor had already chosen, when a submission bounced back.
    $initialLines = collect(old('items', []))
        ->map(fn ($item) => [
            'key' => (string) ($item['key'] ?? ''),
            'quantity' => (string) ($item['quantity'] ?? ''),
        ])
        ->filter(fn (array $item) => $item['key'] !== '')
        ->values();

    // The review screen: [icon, label, Alpine expression, step to edit, shown when].
    $reviewRows = [
        ['user', 'Customer', 'form.contact_name', 2, null],
        ['phone', 'Phone', 'form.contact_phone', 2, null],
        ['map-pin', 'Pickup Address', 'addressLabel', 2, null],
        ['laundry', "What we’re cleaning", 'serviceLine', 1, null],
        ['scale', 'Total weight (estimated)', 'kilosLabel', 1, 'kilosLabel'],
        ['calendar-days', 'Pickup Schedule', 'pickupLabel', 3, null],
        ['truck', 'Pickup & Delivery', 'returnLabel', 3, null],
    ];

    if ($multipleBranches) {
        $reviewRows[] = ['store', 'Branch', 'branchLabel', 2, null];
    }

    $reviewRows[] = ['wallet', 'Payment', 'paymentLabel', 3, null];
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
                bookables: {{ Js::from($bookableMeta) }},
                initialLines: {{ Js::from($initialLines) }},
                branches: {{ Js::from($branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])) }},
                slots: {{ Js::from($slots) }},
                slotsByBranch: {{ Js::from((object) $slotsByBranch) }},
                slotCutoffs: {{ Js::from((object) $slotCutoffs) }},
                today: @js(now()->toDateString()),
                tokenUrl: @js(route('csrf.token')),
                earliestPickupDate: @js($earliestPickupDate),
                latestPickupDate: @js($latestPickupDate),
                requiresBranch: {{ $multipleBranches ? 'true' : 'false' }},
                initial: {
                    branch_id: @js((string) $defaultBranchId),
                    contact_name: @js((string) $value('contact_name', $bookingCustomer?->name ?? '')),
                    contact_phone: @js((string) $value('contact_phone', $bookingCustomer?->phone ?? '')),
                    contact_email: @js((string) $value('contact_email', $bookingCustomer?->email ?? '')),
                    pickup_address: @js((string) $value('pickup_address', $bookingCustomer?->address ?? '')),
                    pickup_landmark: @js((string) $value('pickup_landmark', '')),
                    pickup_date: @js((string) $value('pickup_date', $earliestPickupDate)),
                    pickup_slot: @js((string) $value('pickup_slot', array_key_first($slots))),
                    delivery_preference: @js((string) $value('delivery_preference', 'deliver')),
                    payment_method: @js((string) $value('payment_method', 'cash')),
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
                <p class="text-xs font-bold tracking-[0.3em] text-cc-brown uppercase">Book a Service</p>
                <h2 class="cc-title mt-2">Let us take laundry off your to-do list.</h2>
                <p class="cc-subtitle mt-2">Fill out the form and our team will confirm your pickup. Nothing is charged until your bag is weighed at the branch.</p>

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
                            ['laundry', 'Laundry', 'serviceLine'],
                            ['scale', 'Total weight', 'kilosLabel'],
                            ['calendar-days', 'Pickup', 'pickupLabel'],
                            ['truck', 'Return', 'returnLabel'],
                        ] as [$icon, $label, $expression])
                            <div class="flex items-start gap-3">
                                <span data-lucide="{{ $icon }}" class="mt-0.5 h-4.5 w-4.5 shrink-0 text-cc-brown"></span>
                                <div class="min-w-0">
                                    <dt class="text-[11px] font-semibold text-cc-muted">{{ $label }}</dt>
                                    <dd class="text-sm font-bold wrap-break-word text-cc-deep" x-text="({{ $expression }}) || 'Not set'"></dd>
                                </div>
                            </div>
                        @endforeach
                    </dl>

                    <div class="mt-4 flex items-end justify-between gap-3 border-t border-cc-line pt-4">
                        <span>
                            <span class="block text-sm font-bold text-cc-deep">Estimated total</span>
                            <span class="block text-[11px] text-cc-muted">Confirmed after weighing</span>
                        </span>
                        <span class="font-display text-[2.2rem] leading-none font-bold text-cc-deep" x-text="money(estimate)"></span>
                    </div>
                </div>

                <ul class="mt-5 space-y-2 px-1 text-xs font-semibold text-cc-muted">
                    @if($freeDeliveryKilos)
                        <li class="flex items-center gap-2">
                            <span data-lucide="truck" class="h-4 w-4 shrink-0 text-cc-brown"></span>
                            Pickup &amp; delivery available. Free for orders {{ $freeDeliveryKilos }} kg and above.
                        </li>
                    @endif
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
                            Booking as <span class="font-bold text-cc-brown">{{ $bookingCustomer->name }}</span>. We&rsquo;ve filled in the details we already have.
                        @else
                            Tell us what you need and we&rsquo;ll take care of the rest.
                        @endif
                    </p>

                    {{-- What actually posts: one pair of hidden fields per chosen
                         line. The boxes on the cards are Alpine-only, so nothing
                         is submitted twice. --}}
                    <template x-for="(line, index) in lines" :key="line.key">
                        <span>
                            <input type="hidden" :name="`items[${index}][key]`" :value="line.key">
                            <input type="hidden" :name="`items[${index}][quantity]`" :value="line.quantity">
                        </span>
                    </template>

                    <fieldset class="mt-6">
                        <legend class="cc-label text-[15px]">1. What would you like us to clean? <span class="text-cc-brown">*</span></legend>
                        <p class="cc-help mt-1">Tick everything you&rsquo;re sending. A regular load and a comforter can travel in one booking, and each one asks how much.</p>

                        <div class="mt-3 space-y-2.5 lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0">
                            @foreach ($offerings as $offering)
                                @include('partials.booking-offering', ['offering' => $offering, 'isAddon' => false])
                            @endforeach
                        </div>

                        @if($itemsError)
                            <p class="cc-error">{{ $itemsError }}</p>
                        @else
                            <p x-show="errors.items" x-cloak class="cc-error" x-text="errors.items"></p>
                            <p x-show="errors.items_service" x-cloak class="cc-error" x-text="errors.items_service"></p>
                            <p x-show="errors.items_quantity" x-cloak class="cc-error" x-text="errors.items_quantity"></p>
                        @endif
                    </fieldset>

                    @if($addons->isNotEmpty())
                        {{-- Below the services, because they go with a wash rather
                             than instead of one. --}}
                        <fieldset class="mt-7">
                            <legend class="cc-label text-[15px]">2. Optional Add-ons</legend>
                            <p class="cc-help mt-1">Choose your preferred detergent, fabric conditioner, and finishing spray for this load.</p>

                            <div class="mt-3 space-y-2.5 lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0">
                                @foreach ($addons as $addon)
                                    @include('partials.booking-offering', ['offering' => $addon, 'isAddon' => true])
                                @endforeach
                            </div>
                        </fieldset>
                    @endif

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
                                        <option value="{{ $branch->id }}">{{ $branch->name }}@if($branch->address) ({{ Str::limit($branch->address, 40) }})@endif</option>
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
                                {{-- The chosen branch's windows. Hidden rather than merely
                                     disabled, so a window whose van has gone is not offered. --}}
                                <template x-for="(slotLabel, slotKey) in slots" :key="slotKey">
                                    <option :value="slotKey" x-text="slotLabel" x-show="slotAvailable(slotKey)"
                                            :disabled="! slotAvailable(slotKey)" :selected="slotKey === form.pickup_slot"></option>
                                </template>
                            </select>
                            <p class="cc-help mt-1" x-show="form.pickup_date === today" x-cloak>
                                Booking for today. We&rsquo;ll collect in the next window that is still open.
                            </p>
                            @error('pickup_slot')<p class="cc-error">{{ $message }}</p>@else<p x-show="errors.pickup_slot" x-cloak class="cc-error" x-text="errors.pickup_slot"></p>@enderror
                        </div>
                    </div>

                    <ul class="cc-soft mt-5 space-y-3 px-4 py-4 lg:grid lg:grid-cols-2 lg:gap-4 lg:space-y-0 lg:px-5">
                        @foreach (array_filter([
                            $freeDeliveryKilos ? ['truck', 'Pickup & delivery available', 'Free for orders '.$freeDeliveryKilos.' kg and above'] : null,
                            ['wallet', 'Please have payment ready', 'Our rider collects it at pickup'],
                            ['time', 'We’ll confirm the exact time', $settings?->sms_enabled ? 'By SMS before the rider heads over' : 'With a call before the rider heads over'],
                        ]) as [$icon, $title, $body])
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
                        <legend class="cc-label text-[15px]">How would you like to receive your laundry? <span class="text-cc-brown">*</span></legend>

                        <div class="mt-3 grid grid-cols-2 gap-2.5">
                            @foreach ($deliveryPreferences as $prefKey => $prefLabel)
                                <label class="cc-option flex-col gap-2 p-3.5"
                                       :class="{ 'cc-option-active': form.delivery_preference === '{{ $prefKey }}' }">
                                    <input type="radio" name="delivery_preference" value="{{ $prefKey }}" x-model="form.delivery_preference" class="sr-only">
                                    <span class="cc-icon-tile h-10 w-10"><span data-lucide="{{ $prefKey === 'deliver' ? 'truck' : 'store' }}" class="h-5 w-5"></span></span>
                                    <span>
                                        <span class="block text-sm leading-snug font-bold text-cc-deep">{{ $prefLabel }}</span>
                                        <span class="mt-0.5 block text-xs text-cc-muted">{{ $prefKey === 'deliver' ? 'Payment will be collected by our rider at pickup.' : 'Pick it up yourself.' }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('delivery_preference')<p class="cc-error">{{ $message }}</p>@else<p x-show="errors.delivery_preference" x-cloak class="cc-error" x-text="errors.delivery_preference"></p>@enderror

                        <div x-show="form.delivery_preference === 'deliver'" x-cloak class="mt-4 space-y-4">
                            <label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm font-semibold text-cc-ink">
                                <input type="checkbox" x-model="sameAddress" class="cc-checkbox">
                                Deliver to the same address we collected from
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
                                        <template x-for="(slotLabel, slotKey) in slots" :key="slotKey">
                                            <option :value="slotKey" x-text="slotLabel" :selected="slotKey === form.delivery_slot"></option>
                                        </template>
                                    </select>
                                    @error('delivery_slot') <p class="cc-error">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>
                    </fieldset>

                    {{-- Asked here rather than at the door, so the rider sets off
                         knowing whether to expect cash or a GCash transfer. --}}
                    <fieldset class="mt-7">
                        <legend class="cc-label text-[15px]">Payment Method <span class="text-cc-brown">*</span></legend>

                        <div class="mt-3 grid grid-cols-2 gap-2.5">
                            @foreach ($paymentMethods as $methodKey => $methodLabel)
                                <label class="cc-option items-center gap-3 p-3.5"
                                       :class="{ 'cc-option-active': form.payment_method === '{{ $methodKey }}' }">
                                    <input type="radio" name="payment_method" value="{{ $methodKey }}" x-model="form.payment_method" class="sr-only">
                                    <span class="cc-icon-tile h-10 w-10 shrink-0"><span data-lucide="{{ $methodKey === 'cash' ? 'wallet' : 'smartphone' }}" class="h-5 w-5"></span></span>
                                    <span class="text-sm leading-snug font-bold text-cc-deep">{{ $methodLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('payment_method')<p class="cc-error">{{ $message }}</p>@else<p x-show="errors.payment_method" x-cloak class="cc-error" x-text="errors.payment_method"></p>@enderror
                    </fieldset>

                    <div class="mt-6">
                        <label for="notes" class="cc-label">Special Instructions <span class="font-semibold text-cc-muted">(optional)</span></label>
                        <textarea id="notes" name="notes" rows="3" x-model="form.notes"
                                  placeholder="Separate whites, call before entering the subdivision…"
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
                                    <p class="mt-0.5 text-sm font-bold wrap-break-word text-cc-deep" x-text="({{ $expression }}) || 'Not set'"></p>
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
                            Final total confirmed after weighing.
                        </p>
                    </div>

                    @unless($bookingCustomer)
                        <div class="mt-4 flex items-start gap-3 rounded-2xl border border-cc-line bg-cc-surface px-4 py-3.5">
                            <span data-lucide="lock" class="mt-0.5 h-4 w-4 shrink-0 text-cc-brown"></span>
                            <p class="text-[13px] leading-relaxed text-cc-muted">
                                <span class="font-bold text-cc-deep">Your booking will be saved to your account</span>
                                so you can easily track it. We&rsquo;ll ask you to set a password after you confirm, and
                                everything you filled in here is kept.
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

                <p class="mt-4 text-center text-xs text-cc-muted lg:text-right">No payment needed to book &middot; Cancel before collection</p>
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
            bookables: config.bookables,
            // The keys ticked, in the order they were ticked.
            picked: [],
            // Every bookable key => the amount typed against it.
            qty: {},
            branches: config.branches,
            defaultSlots: config.slots,
            slotsByBranch: config.slotsByBranch,
            slotCutoffs: config.slotCutoffs,
            today: config.today,
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
                // Every key starts present, so typing in a box is reactive from
                // the first keystroke whether or not it was chosen before.
                for (const item of this.bookables) this.qty[item.key] = '';

                // What was chosen before a bounced submission handed the form back.
                for (const line of config.initialLines) {
                    if (! this.bookable(line.key)) continue;

                    this.picked.push(line.key);
                    this.qty[line.key] = line.quantity;
                }

                // Keep the hidden delivery field in step with the pickup address
                // for as long as the customer wants one address.
                this.$watch('sameAddress', (same) => {
                    if (same) this.form.delivery_address = '';
                });

                // Switching to today can leave a window selected whose van has
                // already gone; move to the first one still open.
                this.$watch('form.pickup_date', () => this.keepSlotsValid());

                // Another branch runs other windows, so a time picked for the
                // first may not exist at the second.
                this.$watch('form.branch_id', () => this.keepSlotsValid());
            },

            /** The chosen branch's pickup windows, key => label. */
            get slots() {
                return this.slotsByBranch[this.form.branch_id] || this.defaultSlots;
            },

            keepSlotsValid() {
                if (! (this.form.pickup_slot in this.slots) || ! this.slotAvailable(this.form.pickup_slot)) {
                    this.form.pickup_slot = this.openSlots[0] || '';
                }

                if (this.form.delivery_slot && ! (this.form.delivery_slot in this.slots)) {
                    this.form.delivery_slot = '';
                }
            },

            /** Same-day pickup, as long as that window has not closed yet. */
            slotAvailable(slot) {
                if (! slot || this.form.pickup_date !== this.today) return true;

                const cutoff = this.slotCutoffs[slot];
                if (! cutoff) return true;

                const now = new Date();
                const time = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');

                return time < cutoff;
            },

            get openSlots() {
                return Object.keys(this.slots).filter((slot) => this.slotAvailable(slot));
            },

            bookable(key) {
                return this.bookables.find((item) => item.key === key);
            },

            isPicked(key) {
                return this.picked.includes(key);
            },

            toggle(key) {
                if (this.isPicked(key)) {
                    this.picked = this.picked.filter((picked) => picked !== key);
                    this.qty[key] = '';
                    return;
                }

                const item = this.bookable(key);
                if (['fabcon', 'finishing_spray'].includes(item?.addonGroup)) {
                    for (const pickedKey of this.picked.filter((pickedKey) => this.bookable(pickedKey)?.addonGroup === item.addonGroup)) {
                        this.qty[pickedKey] = '';
                    }
                    this.picked = this.picked.filter((pickedKey) => this.bookable(pickedKey)?.addonGroup !== item.addonGroup);
                }

                this.picked.push(key);
                if (item?.isAddon) {
                    this.qty[key] = '1';
                } else if (item?.pricingType === 'kilo' && Number(item.minimumKilos || 0) > 0) {
                    this.qty[key] = String(item.minimumKilos);
                }

                // Straight into "how much", which is the whole point of ticking it.
                this.$nextTick(() => {
                    const field = this.$el.querySelector('#qty-' + key.replace(/[^a-z0-9]+/gi, '-'));
                    if (field) field.focus();
                });
            },

            hasAmount(key) {
                const amount = parseFloat(this.qty[key]);

                return amount >= 0.5 && amount <= 200;
            },

            /** What posts: the chosen keys with the amount typed against each. */
            get lines() {
                return this.picked
                    .filter((key) => this.bookable(key))
                    .map((key) => ({ key, quantity: String(this.qty[key] ?? '').trim() }));
            },

            /** Mirrors App\Support\Booking::billableQuantity. */
            billableQuantity(item, amount) {
                // Each load-priced service says what one load holds: at 10 kg a
                // load, 1 to 10 kg is one load and 11 to 20 kg is two.
                if (item.pricingType === 'load') {
                    const perLoad = Number(item.kilosPerLoad) > 0 ? Number(item.kilosPerLoad) : {{ (int) \App\Support\Booking::DEFAULT_KILOS_PER_LOAD }};

                    return Math.max(1, Math.ceil(Math.round((amount / perLoad) * 1e6) / 1e6));
                }

                // Three kilos against a five-kilo minimum is charged as five.
                if (item.pricingType === 'kilo' && item.minimumKilos) {
                    return Math.max(amount, item.minimumKilos);
                }

                return amount;
            },

            /** Mirrors App\Support\Booking::lineTotal. */
            lineTotal(item, amount) {
                // A bundle is a whole-package price; the amount does not multiply it.
                if (item.pricingType === 'preset') return item.price;

                return item.price * this.billableQuantity(item, amount);
            },

            /** True once the amount typed is under the service's minimum. */
            belowMinimum(key) {
                const item = this.bookable(key);
                const amount = parseFloat(this.qty[key]);

                return !!(item && item.minimumKilos && amount > 0 && amount < item.minimumKilos);
            },

            minimumLabel(key) {
                const item = this.bookable(key);

                return item?.minimumKilos ? item.minimumKilos + ' kg' : '';
            },

            lineTotalFor(key) {
                const item = this.bookable(key);
                const amount = parseFloat(this.qty[key]);

                return item && amount > 0 ? this.lineTotal(item, amount) : 0;
            },

            amountLabel(item, quantity) {
                const amount = String(quantity ?? '').trim() || '?';

                if (item.unitNoun === 'kg') return amount + ' kg';
                if (item.unitNoun === 'pc') return amount + (amount === '1' ? ' pair' : ' pairs');

                if (item.isAddon) return amount + (Number(amount) === 1 ? ' load' : ' loads');

                return amount + 'x';
            },

            get serviceLine() {
                return this.lines
                    .map((line) => {
                        const item = this.bookable(line.key);

                        return item.label + ' · ' + this.amountLabel(item, line.quantity);
                    })
                    .join(', ');
            },

            /** Everything we weigh, added up: what the branch checks on the scale. */
            get kilosLabel() {
                const total = this.lines.reduce((sum, line) => {
                    const amount = parseFloat(line.quantity);

                    return this.bookable(line.key).unitNoun === 'kg' && amount > 0 ? sum + amount : sum;
                }, 0);

                return total > 0 ? (Math.round(total * 100) / 100) + ' kg' : '';
            },

            get branchLabel() {
                const branch = this.branches.find((b) => String(b.id) === String(this.form.branch_id));
                return branch ? branch.name : '';
            },

            get addressLabel() {
                if (! this.form.pickup_address) return '';
                return this.form.pickup_address + (this.form.pickup_landmark ? ' (' + this.form.pickup_landmark + ')' : '');
            },

            get pickupLabel() {
                if (! this.form.pickup_date) return '';
                return this.formatDate(this.form.pickup_date) + ' • ' + (this.slots[this.form.pickup_slot] || '');
            },

            get paymentLabel() {
                return {{ Js::from(\App\Support\Booking::paymentMethods()) }}[this.form.payment_method] || '';
            },

            get returnLabel() {
                if (this.form.delivery_preference !== 'deliver') {
                    // Named only when there is a choice of branches. With one,
                    // "Claim at Main Branch" hints at others that do not exist.
                    return 'Claim at ' + ((this.requiresBranch && this.branchLabel) || 'the branch');
                }

                let label = 'Delivery back to you';
                if (this.form.delivery_date) label += ' • ' + this.formatDate(this.form.delivery_date);
                if (this.form.delivery_slot) label += ' • ' + (this.slots[this.form.delivery_slot] || '');
                return label;
            },

            /** Mirrors App\Support\Booking::estimate so the two never disagree. */
            get estimate() {
                const lines = this.lines;
                if (! lines.length) return 0;

                const total = lines.reduce((sum, line) => sum + this.lineTotalFor(line.key), 0);

                return Math.round(total * 100) / 100;
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

            /** Tapping a service on the landing page adds it to the booking. */
            pick(offeringKey) {
                if (this.bookable(offeringKey) && ! this.isPicked(offeringKey)) {
                    this.toggle(offeringKey);
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
                        ['items', 'Choose what you would like us to clean.', () => this.lines.length > 0],
                        ['items_service', 'Add-ons go with a wash, so please choose a laundry service too.', () =>
                            this.lines.length === 0 || this.lines.some((line) => ! this.bookable(line.key).isAddon)],
                        ['items_quantity', 'Tell us how much for each one you picked, 8 for 8 kg say.', () =>
                            this.lines.every((line) => this.hasAmount(line.key))],
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
                        ['pickup_slot', 'Choose a collection time that has not passed yet.', () =>
                            filled(this.form.pickup_slot) && this.slotAvailable(this.form.pickup_slot)],
                        ['delivery_preference', 'Tell us how you want your laundry back.', () => filled(this.form.delivery_preference)],
                        ['payment_method', 'Tell us how you would like to pay.', () => filled(this.form.payment_method)],
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
