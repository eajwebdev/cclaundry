<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\LaundryService;
use App\Models\ServicePreset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared shape of the public pickup & delivery booking form. The landing page,
 * the validator and the confirmation screen all read from here so the options
 * can never drift apart.
 *
 * The services a customer can book are the real rows from `laundry_services`
 * that staff have pinned to the landing page (Admin > Laundry Services >
 * "Show on landing page"), so the prices advertised are always the live ones.
 */
class Booking
{
    /**
     * Session key holding the booking this browser just placed: enough to
     * reopen its confirmation without an account, and to prefill the optional
     * sign-up afterwards.
     */
    public const RECENT_SESSION_KEY = 'booking.recent';

    /** Rush handling is priced as a flat surcharge on top of the service. */
    public const RUSH_SURCHARGE = 100;

    /**
     * How many kilos one load holds when a load-priced service does not set its
     * own (Laundry Services > Max kg per load). 1 to 10 kg is one load, 11 to
     * 20 kg is two.
     */
    public const DEFAULT_KILOS_PER_LOAD = 10;

    /**
     * Name of the category the default catalog puts the extras in. What makes a
     * category hold add-ons is its `is_addon` flag, not this name, so staff can
     * rename it freely.
     */
    public const ADDON_CATEGORY = 'Add-ons';

    /** No pickup or delivery window may run past this: the vans are back by 6 PM. */
    public const LATEST_WINDOW_END = '18:00';

    /**
     * The collection windows a branch uses until it sets its own under
     * Settings > Branch.
     */
    public const DEFAULT_PICKUP_WINDOWS = [
        ['start' => '08:00', 'end' => '09:00'],
        ['start' => '09:00', 'end' => '10:00'],
        ['start' => '10:00', 'end' => '11:00'],
        ['start' => '11:00', 'end' => '12:00'],
        ['start' => '13:00', 'end' => '14:00'],
    ];

    /**
     * Services pinned to the landing page. Scoped to a branch when given, so a
     * customer only sees what the branch they picked actually offers.
     */
    public static function services(?int $branchId = null): Collection
    {
        return self::landingServices($branchId)
            // Detergent and fabric conditioner belong on the published price
            // list, but nobody books "Ariel" as their laundry service, so they
            // stay out of the booking form's service choices.
            ->whereDoesntHave('serviceCategory', fn ($query) => $query->where('is_addon', true))
            ->get();
    }

    /**
     * The extras: detergent and fabric conditioner, chosen alongside a wash and
     * counted by the load rather than weighed.
     */
    public static function addons(?int $branchId = null): Collection
    {
        return self::landingServices($branchId)
            ->whereHas('serviceCategory', fn ($query) => $query->where('is_addon', true))
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<LaundryService> */
    private static function landingServices(?int $branchId)
    {
        return LaundryService::query()
            ->onLanding()
            ->when($branchId, fn ($query) => $query->where(fn ($inner) => $inner
                ->where('branch_id', $branchId)
                ->orWhereNull('branch_id')));
    }

    /**
     * Presets pinned to the landing page. A preset bundles several services
     * ("Full Service 7kg" = wash + dry + fold + detergent + fabcon) and is what
     * most customers actually want to book.
     */
    public static function presets(?int $branchId = null): Collection
    {
        return ServicePreset::query()
            ->onLanding()
            ->with('items.service')
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->get()
            // A bundle with no priceable components would advertise nothing, and
            // one missing a component would advertise the wrong price.
            ->filter(fn (ServicePreset $preset) => $preset->items->isNotEmpty() && $preset->unavailableServiceNames() === [])
            ->values();
    }

    /**
     * Everything bookable on the public site, presets first, in one uniform
     * shape so the form, the validator and the summary all speak one language.
     * Each carries a composite key such as "preset:2" or "service:1".
     */
    public static function offerings(?int $branchId = null): Collection
    {
        $presets = self::presets($branchId)->map(fn (ServicePreset $preset) => [
            'key' => 'preset:'.$preset->id,
            'type' => 'preset',
            'id' => $preset->id,
            'name' => $preset->name,
            'price' => $preset->totalPrice(),
            'pricing_type' => 'preset',
            'minimum_kilos' => null,
            'unit' => 'per bundle',
            'unit_short' => 'bundle',
            'icon' => $preset->landingIcon(),
            'blurb' => $preset->landing_blurb,
            'includes' => $preset->includedServiceNames(),
            'sort' => $preset->landing_sort_order,
        ]);

        $services = self::services($branchId)->map(fn (LaundryService $service) => [
            'key' => 'service:'.$service->id,
            'type' => 'service',
            'id' => $service->id,
            'name' => $service->name,
            'price' => (float) $service->price,
            'pricing_type' => $service->pricing_type,
            'minimum_kilos' => $service->minimum_kilos !== null ? (float) $service->minimum_kilos : null,
            'kilos_per_load' => $service->pricing_type === 'load' ? $service->kilosPerLoad() : null,
            'unit' => $service->priceUnitLabel(),
            'unit_short' => $service->priceUnitShort(),
            'icon' => $service->landingIcon(),
            'blurb' => $service->landing_blurb,
            'includes' => [],
            'sort' => $service->landing_sort_order,
        ]);

        // Bundles lead, then single services; each group keeps its own order.
        return $presets->values()->concat($services->values());
    }

    /**
     * The add-ons in the same uniform shape as the services above, so the form
     * and the validator handle both with one set of rules.
     */
    public static function addonOfferings(?int $branchId = null): Collection
    {
        return self::addons($branchId)->map(fn (LaundryService $service) => [
            'key' => 'service:'.$service->id,
            'type' => 'service',
            'id' => $service->id,
            'name' => $service->name,
            'price' => (float) $service->price,
            'pricing_type' => $service->pricing_type,
            'report_category' => $service->report_category,
            // An add-on is counted by the load, so a weight minimum on it
            // would never apply.
            'minimum_kilos' => null,
            'unit' => $service->price_unit_label ?: 'per load',
            'unit_short' => $service->price_unit_label ? $service->priceUnitShort() : 'load',
            'icon' => $service->landingIcon(),
            'blurb' => $service->landing_blurb,
            'includes' => [],
            'sort' => $service->landing_sort_order,
        ])->values();
    }

    /** Composite keys a booking may reference, for validation. */
    public static function offeringKeys(?int $branchId = null): array
    {
        return self::offerings($branchId)->pluck('key')->all();
    }

    /** Every key a booking line may name: the services and their add-ons. */
    public static function bookableKeys(?int $branchId = null): array
    {
        return array_merge(
            self::offeringKeys($branchId),
            self::addonOfferings($branchId)->pluck('key')->all()
        );
    }

    /**
     * What the customer is asked to enter for a service, and what their number
     * means once it is written down.
     *
     * Anything the branch weighs is asked for in kilos — including the
     * load-priced linens, so that every washing line on a booking can be held
     * against the scale at the counter in the same units. Steaming is counted
     * in pairs and the add-ons by the load, where a weight would be nonsense.
     */
    public static function unitFor(?string $pricingType): string
    {
        return match ($pricingType) {
            'kilo', 'load' => 'kg',
            'piece' => 'pc',
            default => 'qty',
        };
    }

    /**
     * The amount in the units the service is actually priced in: kilos stay
     * kilos, but 12 kg of load-priced linens at 10 kg a load is two loads on
     * the bill.
     *
     * A service sold by weight can also carry a minimum. Three kilos against
     * a five-kilo minimum is charged as five, which is what the shop has
     * always done at the counter and what the card promises on the way in.
     */
    public static function billableQuantity(?string $pricingType, float $quantity, ?float $minimumKilos = null, ?float $kilosPerLoad = null): float
    {
        $quantity = max(0.01, $quantity);

        if ($pricingType === 'load') {
            $perLoad = $kilosPerLoad !== null && $kilosPerLoad > 0 ? $kilosPerLoad : self::DEFAULT_KILOS_PER_LOAD;

            // Rounded to the centikilo first, so 20 kg over 10 kg a load is
            // exactly two loads and never two point something.
            return (float) max(1, (int) ceil(round($quantity / $perLoad, 6)));
        }

        return $pricingType === 'kilo' && $minimumKilos !== null
            ? max($quantity, $minimumKilos)
            : $quantity;
    }

    /**
     * Resolves a composite key back to the model it names.
     *
     * @return array{type: string, service: ?LaundryService, preset: ?ServicePreset}|null
     */
    public static function resolveOffering(?string $key): ?array
    {
        if (! $key || ! str_contains($key, ':')) {
            return null;
        }

        [$type, $id] = explode(':', $key, 2);

        if (! ctype_digit($id)) {
            return null;
        }

        return match ($type) {
            'preset' => ($preset = ServicePreset::with('items.service')->find((int) $id))
                ? ['type' => 'preset', 'service' => null, 'preset' => $preset]
                : null,
            'service' => ($service = LaundryService::find((int) $id))
                ? ['type' => 'service', 'service' => $service, 'preset' => null]
                : null,
            default => null,
        };
    }

    /**
     * A branch's pickup windows as key => label, earliest first.
     *
     * The key is the window's own times ("08_09", or "0830_0930" off the hour),
     * so a booking keeps its label and its cutoff even after the branch
     * changes or deletes that window.
     */
    public static function slots(?int $branchId = null): array
    {
        $windows = ($branchId ? BranchSetting::query()->where('branch_id', $branchId)->first()?->pickup_windows : null)
            ?: self::DEFAULT_PICKUP_WINDOWS;

        return collect($windows)
            ->sortBy('start')
            ->mapWithKeys(fn (array $window) => [self::slotKey($window['start'], $window['end']) => self::windowLabel($window['start'], $window['end'])])
            ->all();
    }

    /**
     * The span the pickup windows cover across every branch, for the line on
     * the landing page: "8:00 AM - 2:00 PM".
     */
    public static function pickupHoursLabel(): string
    {
        $windows = collect(self::slotsByBranch() ?: [self::slots()])
            ->flatMap(fn (array $slots) => array_keys($slots))
            ->map(fn (string $slot) => self::slotTimes($slot))
            ->filter();

        if ($windows->isEmpty()) {
            return '';
        }

        return self::windowLabel($windows->min(0), $windows->max(1));
    }

    /**
     * A branch's opening hours folded into lines customers can read at a glance,
     * with runs of days on the same hours joined: ["Mon - Sat: 8:00 AM - 6:00 PM",
     * "Sun: 9:00 AM - 1:00 PM"].
     *
     * @return array<int, string>
     */
    public static function openingHoursLines(?array $hours): array
    {
        $days = ['monday' => 'Mon', 'tuesday' => 'Tue', 'wednesday' => 'Wed', 'thursday' => 'Thu', 'friday' => 'Fri', 'saturday' => 'Sat', 'sunday' => 'Sun'];
        $runs = [];

        foreach ($days as $day => $short) {
            $open = data_get($hours, "$day.open");
            $close = data_get($hours, "$day.close");
            $label = $open && $close && $open !== $close ? self::windowLabel($open, $close) : 'Closed';
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last]['label'] === $label) {
                $runs[$last]['to'] = $short;
            } else {
                $runs[] = ['from' => $short, 'to' => $short, 'label' => $label];
            }
        }

        if ($hours === null || $hours === []) {
            return [];
        }

        return array_map(
            fn (array $run) => ($run['from'] === $run['to'] ? $run['from'] : $run['from'].' - '.$run['to']).': '.$run['label'],
            $runs
        );
    }

    /** Every active branch's windows, keyed by branch id, for the booking form. */
    public static function slotsByBranch(): array
    {
        return self::branches()
            ->mapWithKeys(fn (Branch $branch) => [$branch->id => self::slots($branch->id)])
            ->all();
    }

    public static function slotKey(string $start, string $end): string
    {
        $part = fn (string $time) => str_ends_with($time, ':00') ? substr($time, 0, 2) : str_replace(':', '', $time);

        return $part($start).'_'.$part($end);
    }

    /**
     * The start and end a key stands for, as "H:i", or null for a key that is
     * not a time window (old bookings from before the hourly windows).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function slotTimes(string $slot): ?array
    {
        if (! preg_match('/^(\d{2})(\d{2})?_(\d{2})(\d{2})?$/', $slot, $m)) {
            return null;
        }

        return [$m[1].':'.(($m[2] ?? '') ?: '00'), $m[3].':'.(($m[4] ?? '') ?: '00')];
    }

    /** "8:00 AM - 9:00 AM" for a key; anything unrecognised is shown as it is. */
    public static function slotLabel(?string $slot): string
    {
        $times = $slot ? self::slotTimes($slot) : null;

        return $times ? self::windowLabel(...$times) : (string) $slot;
    }

    private static function windowLabel(string $start, string $end): string
    {
        $format = fn (string $time) => Carbon::createFromFormat('H:i', $time)->format('g:i A');

        return $format($start).' - '.$format($end);
    }

    /**
     * When each window closes -- the time it ends -- so a booking made early can
     * still be collected later the same day.
     *
     * @param  array<string, string>  $slots
     * @return array<string, string>
     */
    public static function slotCutoffs(array $slots): array
    {
        return collect(array_keys($slots))
            ->mapWithKeys(fn (string $slot) => [$slot => self::slotTimes($slot)[1] ?? null])
            ->filter()
            ->all();
    }

    /** Has today's van for this window already gone? */
    public static function slotHasPassed(string $slot): bool
    {
        $cutoff = self::slotTimes($slot)[1] ?? null;

        return $cutoff !== null && now()->format('H:i') >= $cutoff;
    }

    /**
     * The windows still open on a given day. Every window on a future date;
     * on today, only the ones that have not finished yet.
     */
    public static function slotsFor(?string $date = null, ?int $branchId = null): array
    {
        if ($date !== now()->toDateString()) {
            return self::slots($branchId);
        }

        return array_filter(
            self::slots($branchId),
            fn (string $slot) => ! self::slotHasPassed($slot),
            ARRAY_FILTER_USE_KEY
        );
    }

    /** How the customer means to pay, chosen when they book. */
    public static function paymentMethods(): array
    {
        return [
            'cash' => 'Cash',
            'gcash' => 'GCash',
        ];
    }

    public static function deliveryPreferences(): array
    {
        return [
            'deliver' => 'Deliver my laundry to me',
            'branch_pickup' => "I'll pick it up at the branch",
        ];
    }

    /**
     * Branches that can accept a booking. Every active branch qualifies; the
     * pickup/dropoff ones simply route the load to a processing branch later.
     */
    public static function branches(): Collection
    {
        return Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'address', 'contact_number', 'branch_type']);
    }

    /**
     * Public price list, grouped by category, for the landing page rate card.
     * Only services staff have allowed on the landing page (Admin > Laundry
     * Services > "Show on landing page") are published here.
     */
    public static function priceList(): Collection
    {
        return LaundryService::query()
            ->with('serviceCategory')
            ->where('is_active', true)
            ->where('show_on_landing', true)
            ->whereHas('serviceCategory', fn ($query) => $query->where('is_active', true))
            ->get()
            // Printed-poster order: categories in the order staff arranged them,
            // and inside each one the cheapest-first sequence the price list is
            // written in — not category id and alphabetical, which read as random.
            ->sortBy(fn (LaundryService $service) => sprintf(
                '%03d-%03d-%s',
                $service->serviceCategory?->sort_order ?? 999,
                $service->landing_sort_order,
                $service->name
            ))
            ->groupBy(fn (LaundryService $service) => $service->serviceCategory?->name ?: 'Other Services');
    }

    /**
     * What one line of a booking comes to: the service price times the amount
     * in the units it is sold in.
     */
    public static function lineTotal(?string $pricingType, float $price, float $quantity, ?float $minimumKilos = null, ?float $kilosPerLoad = null): float
    {
        // A preset is a whole-bundle price, so the amount does not multiply it.
        if ($pricingType === 'preset') {
            return round($price, 2);
        }

        return round($price * self::billableQuantity($pricingType, $quantity, $minimumKilos, $kilosPerLoad), 2);
    }

    /**
     * A non-binding estimate shown while booking: every line added up, plus the
     * rush surcharge once for the whole booking. The real total is set at the
     * counter once the bag is weighed, which is how walk-ins are priced too.
     *
     * @param  iterable<array{pricing_type?: ?string, price?: float, quantity?: float, line_total?: float}>  $lines
     */
    public static function estimate(iterable $lines, bool $isRush): ?float
    {
        $total = 0.0;
        $counted = 0;

        foreach ($lines as $line) {
            // A line that has already been costed brings its own figure, so a
            // minimum charge applied when it was built is not lost here.
            $total += isset($line['line_total'])
                ? (float) $line['line_total']
                : self::lineTotal(
                    $line['pricing_type'] ?? null,
                    (float) ($line['price'] ?? 0),
                    (float) ($line['quantity'] ?? 1),
                    isset($line['minimum_kilos']) ? (float) $line['minimum_kilos'] : null,
                    isset($line['kilos_per_load']) ? (float) $line['kilos_per_load'] : null
                );
            $counted++;
        }

        if ($counted === 0) {
            return null;
        }

        return round($isRush ? $total + self::RUSH_SURCHARGE : $total, 2);
    }

    /**
     * The earliest date a pickup can be booked: today, while a window is still
     * open, and tomorrow once the last van has gone — promising a collection
     * that cannot happen only disappoints.
     */
    public static function earliestPickupDate(): string
    {
        $branchIds = self::branches()->pluck('id')->all() ?: [null];

        // Today while any branch still has a window open; the slot rule stops a
        // customer picking a closed one at their own branch.
        $openToday = collect($branchIds)->contains(fn (?int $branchId) => self::slotsFor(now()->toDateString(), $branchId) !== []);

        return $openToday
            ? now()->toDateString()
            : now()->addDay()->toDateString();
    }

    public static function latestPickupDate(): string
    {
        return now()->addDays(30)->toDateString();
    }
}
