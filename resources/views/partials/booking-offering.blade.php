@php
    /**
     * One bookable thing on the booking form: a laundry service, or one of the
     * add-ons that ride along with a wash.
     *
     * Ticking it reveals its own amount box, because a booking can hold several
     * services at once and each is measured on its own terms — kilos for
     * anything we weigh, pairs for steaming, a plain count for a sachet.
     *
     * @var array $offering  One entry from App\Support\Booking::offerings()
     * @var bool  $isAddon
     */
    $key = $offering['key'];

    // Safe for an id attribute: the key itself carries a colon.
    $fieldId = 'qty-'.preg_replace('/[^a-z0-9]+/i', '-', $key);

    $noun = $isAddon ? 'qty' : \App\Support\Booking::unitFor($offering['pricing_type']);

    [$prompt, $suffix, $step, $placeholder] = match ($noun) {
        'kg' => ['How many kilos?', 'kg', '0.5', 'e.g. 8'],
        'pc' => ['How many pairs?', 'pairs', '1', 'e.g. 2'],
        default => ['How many?', 'qty', '1', 'e.g. 1'],
    };

    $peso = fn ($amount) => '₱'.number_format((float) $amount, fmod((float) $amount, 1) ? 2 : 0);
@endphp

<div class="cc-option flex-col items-stretch gap-0 p-0" :class="{ 'cc-option-active': isPicked(@js($key)) }">
    <label class="flex cursor-pointer items-start gap-3 p-3.5 sm:px-4">
        <input type="checkbox" class="sr-only" :checked="isPicked(@js($key))" @change="toggle(@js($key))">
        <span class="cc-check" aria-hidden="true"><span data-lucide="check" class="h-3.5 w-3.5"></span></span>
        <span class="min-w-0 flex-1">
            <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                <span class="text-[15px] font-bold text-cc-deep">
                    {{ $offering['name'] }}
                    @if($isAddon)
                        <span class="ml-1 rounded-full bg-cc-soft px-1.5 py-0.5 align-middle text-[9px] font-bold tracking-wide text-cc-brown uppercase">Add-on</span>
                    @elseif($offering['type'] === 'preset')
                        <span class="ml-1 rounded-full bg-cc-soft px-1.5 py-0.5 align-middle text-[9px] font-bold tracking-wide text-cc-brown uppercase">Bundle</span>
                    @endif
                </span>
                <span class="text-sm font-bold whitespace-nowrap text-cc-brown">
                    {{ $peso($offering['price']) }} <span class="font-semibold text-cc-muted">{{ $offering['unit'] }}</span>
                </span>
            </span>
            @if(! empty($offering['includes']))
                <span class="mt-0.5 block text-xs leading-relaxed text-cc-muted">{{ implode(' + ', $offering['includes']) }}</span>
            @elseif($offering['blurb'])
                <span class="mt-0.5 block text-xs leading-relaxed text-cc-muted">{{ $offering['blurb'] }}</span>
            @endif
        </span>
    </label>

    {{-- Chosen means we need an amount for it: this is the figure the branch
         checks on the scale, so it is asked for here rather than guessed. --}}
    <div x-show="isPicked(@js($key))" x-cloak class="border-t border-cc-line px-3.5 pt-2.5 pb-3.5 sm:px-4">
        <label for="{{ $fieldId }}" class="cc-help font-semibold">{{ $prompt }} <span class="text-cc-brown">*</span></label>
        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <div class="relative w-32">
                <input id="{{ $fieldId }}" type="number" inputmode="decimal"
                       step="{{ $step }}" min="0.5" max="200" placeholder="{{ $placeholder }}"
                       x-model="qty[@js($key)]"
                       class="cc-input min-h-11 py-2 pr-14"
                       :class="errors.items_quantity && ! hasAmount(@js($key)) && 'cc-input-invalid'">
                <span class="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-xs font-bold text-cc-muted">{{ $suffix }}</span>
            </div>
            <p class="cc-help" x-show="lineTotalFor(@js($key)) > 0" x-cloak>
                about <span class="font-bold text-cc-deep" x-text="money(lineTotalFor(@js($key)))"></span>
                {{-- Anything under the minimum is still charged at it, so the
                     figure is explained here rather than queried at the door. --}}
                <span x-show="belowMinimum(@js($key))" x-cloak
                      x-text="'(charged at the ' + minimumLabel(@js($key)) + ' minimum)'"></span>
            </p>
        </div>
    </div>
</div>
