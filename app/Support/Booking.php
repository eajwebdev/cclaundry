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
    /** Session key holding a booking captured before the customer signed up. */
    public const PENDING_SESSION_KEY = 'booking.pending';

    /** Rush handling is priced as a flat surcharge on top of the service. */
    public const RUSH_SURCHARGE = 100;

    /**
     * Assumed size of one machine load when estimating a load-priced service
     * from a customer's rough weight guess. Only ever used for the non-binding
     * estimate; the branch weighs the bag and sets the real total.
     */
    public const KILOS_PER_LOAD = 7;

    /**
     * Services pinned to the landing page. Scoped to a branch when given, so a
     * customer only sees what the branch they picked actually offers.
     */
    public static function services(?int $branchId = null): Collection
    {
        return LaundryService::query()
            ->onLanding()
            ->when($branchId, fn ($query) => $query->where(fn ($inner) => $inner
                ->where('branch_id', $branchId)
                ->orWhereNull('branch_id')))
            ->get();
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
            'unit' => $service->priceUnitLabel(),
            'icon' => $service->landingIcon(),
            'blurb' => $service->landing_blurb,
            'includes' => [],
            'sort' => $service->landing_sort_order,
        ]);

        // Bundles lead, then single services; each group keeps its own order.
        return $presets->values()->concat($services->values());
    }

    /** Composite keys a booking may reference, for validation. */
    public static function offeringKeys(?int $branchId = null): array
    {
        return self::offerings($branchId)->pluck('key')->all();
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

    public static function slots(): array
    {
        return PickupRequest::PICKUP_SLOTS;
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
            ->orderBy('service_category_id')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (LaundryService $service) => $service->serviceCategory?->name ?: 'Other Services');
    }

    /**
     * A non-binding estimate shown while booking. The real total is set at the
     * counter once the load is weighed, which is how walk-ins are priced too.
     */
    public static function estimate(LaundryService|ServicePreset|null $offering, ?float $kilos, bool $isRush): ?float
    {
        if (! $offering) {
            return null;
        }

        // A preset is already a whole-bundle price, so the weight guess does
        // not multiply it; the branch confirms once the bag is weighed.
        if ($offering instanceof ServicePreset) {
            $total = $offering->totalPrice();

            return round($isRush ? $total + self::RUSH_SURCHARGE : $total, 2);
        }

        $service = $offering;
        $price = (float) $service->price;

        $total = match ($service->pricing_type) {
            'kilo' => $price * max(1.0, (float) ($kilos ?: 1)),
            'load' => $price * max(1, (int) ceil(((float) ($kilos ?: self::KILOS_PER_LOAD)) / self::KILOS_PER_LOAD)),
            // Piece and custom pricing cannot be inferred from a weight guess,
            // so we quote a single unit and let the branch confirm.
            default => $price,
        };

        return round($isRush ? $total + self::RUSH_SURCHARGE : $total, 2);
    }

    /**
     * The earliest date a pickup can be booked. Anything requested today after
     * the last van leaves would only disappoint, so bookings start tomorrow.
     */
    public static function earliestPickupDate(): string
    {
        return now()->addDay()->toDateString();
    }

    public static function latestPickupDate(): string
    {
        return now()->addDays(30)->toDateString();
    }
}
