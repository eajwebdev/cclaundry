<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\LaundryService;
use App\Models\PickupRequest;
use App\Models\ServicePreset;
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
     * Assumed size of one machine load when estimating a load-priced service
     * from a customer's rough weight guess. Only ever used for the non-binding
     * estimate; the branch weighs the bag and sets the real total.
     */
    public const KILOS_PER_LOAD = 7;

    /** Extras chosen with a load, never the load itself. */
    public const ADDON_CATEGORY = 'Add-ons';

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
            ->whereDoesntHave('serviceCategory', fn ($query) => $query->where('name', self::ADDON_CATEGORY))
            ->get();
    }

    /**
     * The extras: detergent and fabric conditioner, chosen alongside a wash and
     * counted by the sachet rather than weighed.
     */
    public static function addons(?int $branchId = null): Collection
    {
        return self::landingServices($branchId)
            ->whereHas('serviceCategory', fn ($query) => $query->where('name', self::ADDON_CATEGORY))
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
            // A bundle with no priceable components would advertise nothing.
            ->filter(fn (ServicePreset $preset) => $preset->items->isNotEmpty())
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
            'unit' => $service->priceUnitLabel(),
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
            // An add-on is counted by the sachet, so a weight minimum on it
            // would never apply.
            'minimum_kilos' => null,
            'unit' => $service->priceUnitLabel(),
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
     * in pairs and the add-ons by the sachet, where a weight would be nonsense.
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
     * kilos, but 12 kg of load-priced linens is two loads on the bill.
     *
     * A service sold by weight can also carry a minimum. Three kilos against
     * a five-kilo minimum is charged as five, which is what the shop has
     * always done at the counter and what the card promises on the way in.
     */
    public static function billableQuantity(?string $pricingType, float $quantity, ?float $minimumKilos = null): float
    {
        $quantity = max(0.01, $quantity);

        if ($pricingType === 'load') {
            return (float) max(1, (int) ceil($quantity / self::KILOS_PER_LOAD));
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
     * When each pickup window closes -- the hour it ends -- so a booking made
     * early can still be collected later the same day. Same-day is the common
     * case: someone rings at breakfast and wants the bag gone before lunch.
     */
    public const SLOT_CUTOFFS = [
        '08_09' => '09:00',
        '09_10' => '10:00',
        '10_11' => '11:00',
        '11_12' => '12:00',
        '13_14' => '14:00',
    ];

    public static function slots(): array
    {
        return PickupRequest::PICKUP_SLOTS;
    }

    /** Has today's van for this window already gone? */
    public static function slotHasPassed(string $slot): bool
    {
        $cutoff = self::SLOT_CUTOFFS[$slot] ?? null;

        return $cutoff !== null && now()->format('H:i') >= $cutoff;
    }

    /**
     * The windows still open on a given day. Every window on a future date;
     * on today, only the ones that have not finished yet.
     */
    public static function slotsFor(?string $date = null): array
    {
        if ($date !== now()->toDateString()) {
            return self::slots();
        }

        return array_filter(
            self::slots(),
            fn (string $slot) => ! self::slotHasPassed($slot),
            ARRAY_FILTER_USE_KEY
        );
    }

    public static function deliveryPreferences(): array
    {
        return [
            'deliver' => 'Deliver it back to me',
            'branch_pickup' => 'I will claim it at the branch',
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
    public static function lineTotal(?string $pricingType, float $price, float $quantity, ?float $minimumKilos = null): float
    {
        // A preset is a whole-bundle price, so the amount does not multiply it.
        if ($pricingType === 'preset') {
            return round($price, 2);
        }

        return round($price * self::billableQuantity($pricingType, $quantity, $minimumKilos), 2);
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
                    isset($line['minimum_kilos']) ? (float) $line['minimum_kilos'] : null
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
        return self::slotsFor(now()->toDateString()) !== []
            ? now()->toDateString()
            : now()->addDay()->toDateString();
    }

    public static function latestPickupDate(): string
    {
        return now()->addDays(30)->toDateString();
    }
}
