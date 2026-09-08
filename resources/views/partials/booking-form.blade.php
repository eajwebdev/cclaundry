@php
    /**
     * Public pickup & delivery booking form.
     *
     * Prefill order: what the visitor just typed (a validation bounce) beats a
     * booking parked before sign-up, which beats the signed-in customer's saved
     * details, which beats empty.
     */
    $bookingCustomer = auth('customer')->user();
    $pending = $pendingBooking ?? null;

    $value = function (string $key, $fallback = '') use ($pending) {
        return old($key, data_get($pending, $key, $fallback));
    };

    $defaultBranchId = $value('branch_id', $bookingCustomer?->branch_id ?: $branches->first()?->id);

    // Which step holds the first thing the server complained about, so a bounced
    // submission reopens where the problem is instead of back at step one.
    $stepFields = [
        1 => ['service_type', 'estimated_kilos', 'is_rush'],
        2 => ['branch_id', 'pickup_address', 'pickup_landmark', 'pickup_date', 'pickup_slot'],
        3 => ['delivery_preference', 'delivery_address', 'delivery_date', 'delivery_slot'],
        4 => ['contact_name', 'contact_phone', 'contact_email', 'notes'],
    ];

    $initialStep = 1;
    foreach ($stepFields as $step => $fields) {
        if ($errors->hasAny($fields)) {
            $initialStep = $step;
            break;
        }
    }

    $serviceMeta = collect($serviceTypes)->map(fn ($service, $key) => [
        'key' => $key,
        'label' => $service['label'],
        'from' => $service['from'],
        'unit' => $service['unit'],
        'perLoad' => in_array($key, ['wash_dry_fold', 'wash_only', 'dry_only'], true),
    ])->values();
@endphp

<section id="book" class="scroll-mt-24 border-y border-border bg-white py-20 sm:py-24 dark:border-white/10 dark:bg-[#241a13]">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

        <div class="mx-auto max-w-2xl text-center">
            <p class="text-[11px] font-semibold tracking-[0.22em] text-primary uppercase">Book a pickup</p>
            <h2 class="mt-3 font-serif text-3xl leading-tight font-medium text-primary-deep sm:text-4xl dark:text-cane">
                Tell us where and when
            </h2>
            <p class="mt-4 text-[15px] leading-relaxed text-muted">
                @if($bookingCustomer)
                    Booking as <span class="font-medium text-primary">{{ $bookingCustomer->name }}</span>. Everything below is prefilled from your last order.
                @else
                    Fill this in first &mdash; we only ask you to create an account at the very end, so nothing you type here is lost.
                @endif
            </p>
        </div>

        <form
            method="POST"
            action="{{ route('booking.store') }}"
            x-data="bookingForm({
                step: {{ $initialStep }},
                services: {{ Js::from($serviceMeta) }},
                rushSurcharge: {{ (int) $rushSurcharge }},
                branches: {{ Js::from($branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])) }},
                slots: {{ Js::from($slots) }},
                initial: {
                    service_type: @js($value('service_type', 'wash_dry_fold')),
                    estimated_kilos: @js($value('estimated_kilos', '')),
                    is_rush: {{ $value('is_rush') ? 'true' : 'false' }},
                    branch_id: @js((string) $defaultBranchId),
                    pickup_date: @js($value('pickup_date', $earliestPickupDate)),
                    pickup_slot: @js($value('pickup_slot', 'morning')),
                    delivery_preference: @js($value('delivery_preference', 'deliver')),
                    delivery_slot: @js($value('delivery_slot', '')),
                    delivery_date: @js($value('delivery_date', '')),
                    pickup_address: @js($value('pickup_address', $bookingCustomer?->address ?? '')),
                    delivery_address: @js($value('delivery_address', '')),
                }
            })"
            @preselect-service.window="pick($event.detail)"
            @preselect-branch.window="form.branch_id = String($event.detail); step = 2"
            class="mt-12 grid gap-6 lg:grid-cols-[1.55fr_1fr] lg:items-start"
        >
            @csrf

            {{-- ─────────── Steps ─────────── --}}
            <div class="overflow-hidden rounded-3xl border border-border bg-cream dark:border-white/10 dark:bg-[#1c1510]">

                {{-- Progress --}}
                <div class="border-b border-border px-6 py-5 dark:border-white/10">
                    <ol class="flex items-center gap-2">
                        @foreach (['Service', 'Pickup', 'Delivery', 'Contact'] as $i => $label)
                            @php($n = $i + 1)
                            <li class="flex flex-1 items-center gap-2">
                                <button type="button" @click="goTo({{ $n }})"
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border text-[12px] font-semibold transition"
                                        :class="step > {{ $n }}
                                            ? 'border-primary bg-primary text-white'
                                            : (step === {{ $n }}
                                                ? 'border-primary bg-primary/10 text-primary'
                                                : 'border-border text-muted')">
                                    <span x-show="step > {{ $n }}" x-cloak data-lucide="check" class="h-3.5 w-3.5"></span>
                                    <span x-show="step <= {{ $n }}">{{ $n }}</span>
                                </button>
                                <span class="hidden text-xs font-medium sm:block"
                                      :class="step >= {{ $n }} ? 'text-primary' : 'text-muted'">{{ $label }}</span>
                                @if($n < 4)
                                    <span class="h-px flex-1 transition-colors" :class="step > {{ $n }} ? 'bg-primary' : 'bg-border'"></span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </div>

                <div class="p-6 sm:p-8">

                    {{-- ═══ Step 1: service ═══ --}}
                    <div data-step="1" x-show="step === 1">
                        <h3 class="font-serif text-xl font-medium text-primary-deep dark:text-cane">What are we washing?</h3>
                        <p class="mt-1.5 text-sm text-muted">Pick the closest match. We will confirm the details when we weigh your bag.</p>

                        <div class="mt-6 grid gap-3 sm:grid-cols-2">
                            @foreach ($serviceTypes as $key => $service)
                                <label class="relative flex cursor-pointer gap-3 rounded-2xl border p-4 transition"
                                       :class="form.service_type === @js($key)
                                            ? 'border-primary bg-primary/6 ring-1 ring-primary/25'
                                            : 'border-border bg-white hover:border-primary/35 dark:bg-[#241a13]'">
                                    <input type="radio" name="service_type" value="{{ $key }}" x-model="form.service_type" class="sr-only">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition"
                                          :class="form.service_type === @js($key) ? 'bg-primary text-white' : 'bg-primary/10 text-primary'">
                                        <span data-lucide="{{ $service['icon'] }}" class="h-4.5 w-4.5"></span>
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium">{{ $service['label'] }}</span>
                                        <span class="mt-0.5 block text-xs text-muted">From &#8369;{{ number_format($service['from']) }} {{ $service['unit'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('service_type') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror

                        <div class="mt-6 grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="estimated_kilos" class="block text-sm font-medium">Roughly how many kilos?</label>
                                <p class="mt-1 text-xs text-muted">Optional &mdash; a guess is fine. One full laundry basket is about 7kg.</p>
                                <div class="relative mt-2">
                                    <input id="estimated_kilos" type="number" name="estimated_kilos" step="0.5" min="1" max="200"
                                           x-model="form.estimated_kilos" placeholder="7"
                                           class="h-12 w-full rounded-xl border border-border bg-white pr-12 pl-4 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#241a13]">
                                    <span class="absolute top-1/2 right-4 -translate-y-1/2 text-xs text-muted">kg</span>
                                </div>
                                @error('estimated_kilos') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <span class="block text-sm font-medium">Need it sooner?</span>
                                <p class="mt-1 text-xs text-muted">Rush jumps the queue for same-day handling.</p>
                                <label class="mt-2 flex h-12 cursor-pointer items-center gap-3 rounded-xl border px-4 transition"
                                       :class="form.is_rush ? 'border-amber-400 bg-amber-50 dark:bg-amber-500/10' : 'border-border bg-white dark:bg-[#241a13]'">
                                    <input type="checkbox" name="is_rush" value="1" x-model="form.is_rush"
                                           class="h-4 w-4 rounded border-border text-primary focus:ring-primary/30">
                                    <span data-lucide="zap" class="h-4 w-4" :class="form.is_rush ? 'text-amber-600' : 'text-muted'"></span>
                                    <span class="text-sm">Rush service <span class="text-muted">(+&#8369;{{ number_format($rushSurcharge) }})</span></span>
                                </label>
                            </div>
                        </div>
                    </div>

                    {{-- ═══ Step 2: pickup ═══ --}}
                    <div data-step="2" x-show="step === 2" x-cloak>
                        <h3 class="font-serif text-xl font-medium text-primary-deep dark:text-cane">Where and when do we collect?</h3>
                        <p class="mt-1.5 text-sm text-muted">Our rider will be there within the window you choose.</p>

                        <div class="mt-6 space-y-5">
                            <div>
                                <label for="branch_id" class="block text-sm font-medium">Nearest branch</label>
                                <select id="branch_id" name="branch_id" x-model="form.branch_id" required
                                        class="mt-2 h-12 w-full rounded-xl border border-border bg-white px-4 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#241a13]">
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->name }}@if($branch->address) &mdash; {{ Str::limit($branch->address, 45) }}@endif</option>
                                    @endforeach
                                </select>
                                @error('branch_id') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="pickup_address" class="block text-sm font-medium">Pickup address</label>
                                <textarea id="pickup_address" name="pickup_address" rows="2" required x-model="form.pickup_address"
                                          placeholder="House/unit number, street, barangay, city"
                                          class="mt-2 w-full rounded-xl border border-border bg-white px-4 py-3 text-sm outline-none transition placeholder:text-muted/60 focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#241a13]"></textarea>
                                @error('pickup_address') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="pickup_landmark" class="block text-sm font-medium">Landmark <span class="font-normal text-muted">(optional)</span></label>
                                <input id="pickup_landmark" type="text" name="pickup_landmark" value="{{ $value('pickup_landmark') }}"
                                       placeholder="Beside the blue gate, across the sari-sari store"
                                       class="mt-2 h-12 w-full rounded-xl border border-border bg-white px-4 text-sm outline-none transition placeholder:text-muted/60 focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#241a13]">
                            </div>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="pickup_date" class="block text-sm font-medium">Pickup date</label>
                                    <input id="pickup_date" type="date" name="pickup_date" required x-model="form.pickup_date"
                                           min="{{ $earliestPickupDate }}" max="{{ $latestPickupDate }}"
                                           class="mt-2 h-12 w-full rounded-xl border border-border bg-white px-4 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#241a13]">
                                    @error('pickup_date') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <span class="block text-sm font-medium">Pickup window</span>
                                    <div class="mt-2 grid gap-2">
                                        @foreach ($slots as $slotKey => $slotLabel)
                                            <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border px-3.5 py-2.5 text-sm transition"
                                                   :class="form.pickup_slot === @js($slotKey)
                                                        ? 'border-primary bg-primary/6'
                                                        : 'border-border bg-white hover:border-primary/35 dark:bg-[#241a13]'">
                                                <input type="radio" name="pickup_slot" value="{{ $slotKey }}" x-model="form.pickup_slot"
                                                       class="h-4 w-4 border-border text-primary focus:ring-primary/30">
                                                <span>{{ $slotLabel }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @error('pickup_slot') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- ═══ Step 3: delivery ═══ --}}
                    <div data-step="3" x-show="step === 3" x-cloak>
                        <h3 class="font-serif text-xl font-medium text-primary-deep dark:text-cane">How should we return it?</h3>
                        <p class="mt-1.5 text-sm text-muted">Delivery is charged by zone and added to your total at the branch.</p>

                        <div class="mt-6 grid gap-3 sm:grid-cols-2">
                            @foreach ($deliveryPreferences as $prefKey => $prefLabel)
                                <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-4 transition"
                                       :class="form.delivery_preference === @js($prefKey)
                                            ? 'border-primary bg-primary/6 ring-1 ring-primary/25'
                                            : 'border-border bg-white hover:border-primary/35 dark:bg-[#241a13]'">
                                    <input type="radio" name="delivery_preference" value="{{ $prefKey }}" x-model="form.delivery_preference" class="sr-only">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition"
                                          :class="form.delivery_preference === @js($prefKey) ? 'bg-primary text-white' : 'bg-primary/10 text-primary'">
                                        <span data-lucide="{{ $prefKey === 'deliver' ? 'truck' : 'store' }}" class="h-4.5 w-4.5"></span>
                                    </span>
                                    <span>
                                        <span class="block text-sm font-medium">{{ $prefLabel }}</span>
                                        <span class="mt-0.5 block text-xs text-muted">
                                            {{ $prefKey === 'deliver' ? 'We bring it back to your door' : 'No delivery charge' }}
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        <div x-show="form.delivery_preference === 'deliver'" x-cloak x-transition class="mt-6 space-y-5">
                            <label class="flex cursor-pointer items-center gap-2.5 text-sm">
                                <input type="checkbox" x-model="sameAddress" class="h-4 w-4 rounded border-border text-primary focus:ring-primary/30">
                                <span>Deliver to the same address we collect from</span>
                            </label>

                            <div x-show="! sameAddress" x-cloak x-transition>
                                <label for="delivery_address" class="block text-sm font-medium">Delivery address</label>
                                <textarea id="delivery_address" name="delivery_address" rows="2" x-model="form.delivery_address"
                                          placeholder="House/unit number, street, barangay, city"
                                          class="mt-2 w-full rounded-xl border border-border bg-white px-4 py-3 text-sm outline-none transition placeholder:text-muted/60 focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#241a13]"></textarea>
                                @error('delivery_address') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="delivery_date" class="block text-sm font-medium">Preferred delivery date <span class="font-normal text-muted">(optional)</span></label>
                                    <input id="delivery_date" type="date" name="delivery_date" x-model="form.delivery_date"
                                           :min="form.pickup_date" max="{{ $latestPickupDate }}"
                                           class="mt-2 h-12 w-full rounded-xl border border-border bg-white px-4 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#241a13]">
                                    @error('delivery_date') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label for="delivery_slot" class="block text-sm font-medium">Delivery window <span class="font-normal text-muted">(optional)</span></label>
                                    <select id="delivery_slot" name="delivery_slot" x-model="form.delivery_slot"
                                            class="mt-2 h-12 w-full rounded-xl border border-border bg-white px-4 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#241a13]">
                                        <option value="">No preference</option>
                                        @foreach ($slots as $slotKey => $slotLabel)
                                            <option value="{{ $slotKey }}">{{ $slotLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- ═══ Step 4: contact ═══ --}}
                    <div data-step="4" x-show="step === 4" x-cloak>
                        <h3 class="font-serif text-xl font-medium text-primary-deep dark:text-cane">Who do we look for?</h3>
                        <p class="mt-1.5 text-sm text-muted">The rider will call this number when they are outside.</p>

                        <div class="mt-6 space-y-5">
                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="contact_name" class="block text-sm font-medium">Full name</label>
                                    <input id="contact_name" type="text" name="contact_name" required
                                           value="{{ $value('contact_name', $bookingCustomer?->name ?? '') }}"
                                           class="mt-2 h-12 w-full rounded-xl border border-border bg-white px-4 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#241a13]">
                                    @error('contact_name') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label for="contact_phone" class="block text-sm font-medium">Mobile number</label>
                                    <input id="contact_phone" type="tel" name="contact_phone" required placeholder="09XX XXX XXXX"
                                           value="{{ $value('contact_phone', $bookingCustomer?->phone ?? '') }}"
                                           class="mt-2 h-12 w-full rounded-xl border border-border bg-white px-4 text-sm outline-none transition placeholder:text-muted/60 focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#241a13]">
                                    @error('contact_phone') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div>
                                <label for="contact_email" class="block text-sm font-medium">Email <span class="font-normal text-muted">(optional)</span></label>
                                <input id="contact_email" type="email" name="contact_email"
                                       value="{{ $value('contact_email', $bookingCustomer?->email ?? '') }}"
                                       class="mt-2 h-12 w-full rounded-xl border border-border bg-white px-4 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#241a13]">
                                @error('contact_email') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="notes" class="block text-sm font-medium">Special instructions <span class="font-normal text-muted">(optional)</span></label>
                                <textarea id="notes" name="notes" rows="3"
                                          placeholder="Separate the whites, no fabric softener on the baby clothes, call before entering the subdivision..."
                                          class="mt-2 w-full rounded-xl border border-border bg-white px-4 py-3 text-sm outline-none transition placeholder:text-muted/60 focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#241a13]">{{ $value('notes') }}</textarea>
                            </div>

                            @unless($bookingCustomer)
                                <div class="flex items-start gap-3 rounded-2xl border border-primary/25 bg-primary/6 px-4 py-3.5">
                                    <span data-lucide="lock" class="mt-0.5 h-4 w-4 shrink-0 text-primary"></span>
                                    <p class="text-[13px] leading-relaxed text-muted">
                                        <span class="font-medium text-primary-deep dark:text-cane">One last step after this.</span>
                                        Bookings are tied to an account so you can track and cancel them. When you press
                                        <em>Confirm booking</em> we will ask you to set a password &mdash; everything you filled in here is kept.
                                    </p>
                                </div>
                            @endunless
                        </div>
                    </div>

                    {{-- Navigation --}}
                    <div class="mt-8 flex items-center justify-between gap-3 border-t border-border pt-6 dark:border-white/10">
                        <button type="button" @click="back()" x-show="step > 1" x-cloak
                                class="inline-flex h-12 items-center gap-2 rounded-xl border border-border px-5 text-sm font-medium transition hover:border-primary/40 dark:border-white/12">
                            <span data-lucide="arrow-left" class="h-4 w-4"></span>
                            Back
                        </button>
                        <span x-show="step === 1" class="hidden sm:block"></span>

                        <button type="button" @click="next()" x-show="step < 4"
                                class="ml-auto inline-flex h-12 items-center gap-2 rounded-xl bg-primary px-6 text-sm font-semibold text-white shadow-lg shadow-primary/20 transition hover:bg-primary-deep">
                            Continue
                            <span data-lucide="arrow-right" class="h-4 w-4"></span>
                        </button>

                        <button type="submit" x-show="step === 4" x-cloak
                                class="ml-auto inline-flex h-12 items-center gap-2 rounded-xl bg-primary px-6 text-sm font-semibold text-white shadow-lg shadow-primary/20 transition hover:bg-primary-deep">
                            <span data-lucide="check" class="h-4 w-4"></span>
                            Confirm booking
                        </button>
                    </div>
                </div>
            </div>

            {{-- ─────────── Live summary ─────────── --}}
            <aside class="lg:sticky lg:top-24">
                <div class="overflow-hidden rounded-3xl border border-border bg-cream dark:border-white/10 dark:bg-[#1c1510]">
                    <div class="border-b border-border bg-primary/6 px-6 py-5 dark:border-white/10">
                        <h3 class="font-serif text-lg font-medium text-primary-deep dark:text-cane">Your booking</h3>
                        <p class="mt-1 text-xs text-muted">Updates as you fill the form.</p>
                    </div>

                    <dl class="divide-y divide-border px-6 dark:divide-white/8">
                        <div class="flex items-start justify-between gap-4 py-3.5">
                            <dt class="text-sm text-muted">Service</dt>
                            <dd class="text-right text-sm font-medium" x-text="serviceLabel"></dd>
                        </div>
                        <div class="flex items-start justify-between gap-4 py-3.5">
                            <dt class="text-sm text-muted">Estimated load</dt>
                            <dd class="text-right text-sm font-medium" x-text="form.estimated_kilos ? form.estimated_kilos + ' kg' : 'To be weighed'"></dd>
                        </div>
                        <div class="flex items-start justify-between gap-4 py-3.5">
                            <dt class="text-sm text-muted">Branch</dt>
                            <dd class="text-right text-sm font-medium" x-text="branchLabel"></dd>
                        </div>
                        <div class="flex items-start justify-between gap-4 py-3.5">
                            <dt class="text-sm text-muted">Pickup</dt>
                            <dd class="text-right text-sm font-medium" x-text="pickupLabel"></dd>
                        </div>
                        <div class="flex items-start justify-between gap-4 py-3.5">
                            <dt class="text-sm text-muted">Return</dt>
                            <dd class="text-right text-sm font-medium" x-text="returnLabel"></dd>
                        </div>
                        <div class="flex items-start justify-between gap-4 py-3.5" x-show="form.is_rush" x-cloak>
                            <dt class="text-sm text-muted">Rush service</dt>
                            <dd class="text-right text-sm font-medium text-amber-600">+&#8369;{{ number_format($rushSurcharge) }}</dd>
                        </div>
                    </dl>

                    <div class="border-t border-border px-6 py-5 dark:border-white/10">
                        <div class="flex items-end justify-between gap-4">
                            <div>
                                <p class="text-sm text-muted">Estimated total</p>
                                <p class="mt-0.5 text-[11px] text-muted">Final price set after weighing</p>
                            </div>
                            <p class="font-serif text-2xl font-semibold text-primary" x-text="'₱' + estimate.toLocaleString()"></p>
                        </div>
                    </div>

                    <div class="border-t border-border bg-white/60 px-6 py-4 dark:border-white/10 dark:bg-white/3">
                        <ul class="space-y-2 text-[12px] text-muted">
                            @foreach ([
                                'Pickup is free, always',
                                'No payment needed to book',
                                'Cancel any time before collection',
                            ] as $assurance)
                                <li class="flex items-center gap-2">
                                    <span data-lucide="check" class="h-3.5 w-3.5 shrink-0 text-accent-deep"></span>
                                    {{ $assurance }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </aside>
        </form>
    </div>
</section>

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('bookingForm', (config) => ({
            step: config.step,
            services: config.services,
            branches: config.branches,
            slots: config.slots,
            rushSurcharge: config.rushSurcharge,
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
                return this.services.find((s) => s.key === this.form.service_type) || this.services[0];
            },

            get serviceLabel() {
                return this.service ? this.service.label : '--';
            },

            get branchLabel() {
                const branch = this.branches.find((b) => String(b.id) === String(this.form.branch_id));
                return branch ? branch.name : '--';
            },

            get pickupLabel() {
                if (! this.form.pickup_date) return '--';
                return this.formatDate(this.form.pickup_date) + ', ' + (this.slots[this.form.pickup_slot] || '');
            },

            get returnLabel() {
                if (this.form.delivery_preference !== 'deliver') return 'Claim at branch';
                if (! this.form.delivery_date) return 'Delivered, date to confirm';
                return this.formatDate(this.form.delivery_date);
            },

            /** Mirrors App\Support\Booking::estimate so the two never disagree. */
            get estimate() {
                const service = this.service;
                if (! service) return 0;

                let total = service.from;

                if (service.perLoad) {
                    const kilos = parseFloat(this.form.estimated_kilos) || 7;
                    total = service.from * Math.max(1, Math.ceil(kilos / 7));
                }

                return this.form.is_rush ? total + this.rushSurcharge : total;
            },

            formatDate(value) {
                const date = new Date(value + 'T00:00:00');
                if (isNaN(date)) return value;
                return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
            },

            pick(serviceType) {
                if (this.services.some((s) => s.key === serviceType)) {
                    this.form.service_type = serviceType;
                }
                this.step = 1;
            },

            goTo(target) {
                // Only allow jumping back to a step already completed.
                if (target < this.step) this.step = target;
            },

            back() {
                if (this.step > 1) this.step--;
                this.focusSection();
            },

            next() {
                if (! this.validateStep()) return;
                if (this.step < 4) this.step++;
                this.focusSection();
            },

            /**
             * Let the browser surface its own messages for the fields on this
             * step, so an incomplete step cannot be skipped past.
             */
            validateStep() {
                const panel = this.$el.querySelector('[data-step="' + this.step + '"]');
                if (! panel) return true;

                for (const field of panel.querySelectorAll('input, select, textarea')) {
                    if (field.offsetParent === null && field.type !== 'radio') continue;
                    if (! field.checkValidity()) {
                        field.reportValidity();
                        return false;
                    }
                }

                return true;
            },

            focusSection() {
                this.$nextTick(() => {
                    window.renderLucideIcons?.();
                    const anchor = document.getElementById('book');
                    if (anchor && anchor.getBoundingClientRect().top < 0) {
                        anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            },
        }));
    });
</script>
@endpush
