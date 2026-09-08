<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\LaundryService;
use App\Models\PickupRequest;
use Illuminate\Support\Collection;

/**
 * Shared shape of the public pickup-and-delivery booking form. The landing
 * page, the validator and the confirmation screen all read from here so the
 * options can never drift apart.
 */
class Booking
{
    /** Session key holding a booking captured before the customer signed up. */
    public const PENDING_SESSION_KEY = 'booking.pending';

    /**
     * Rush handling is priced as a flat surcharge on top of the service.
     */
    public const RUSH_SURCHARGE = 100;

    public static function serviceTypes(): array
    {
        return [
            'wash_dry_fold' => [
                'label' => 'Wash, Dry & Fold',
                'blurb' => 'Our everyday load. Sorted, washed, tumble dried and folded.',
                'icon' => 'shirt',
                'from' => 165,
                'unit' => 'per 7kg load',
            ],
            'wash_only' => [
                'label' => 'Wash Only',
                'blurb' => 'For when you would rather line dry at home.',
                'icon' => 'droplets',
                'from' => 60,
                'unit' => 'per 7kg load',
            ],
            'dry_only' => [
                'label' => 'Dry Only',
                'blurb' => 'Rain-soaked laundry dried and ready the same day.',
                'icon' => 'wind',
                'from' => 80,
                'unit' => 'per 7kg load',
            ],
            'press_iron' => [
                'label' => 'Steam Press & Ironing',
                'blurb' => 'Crisp uniforms, barongs and office wear, pressed by hand.',
                'icon' => 'flame',
                'from' => 50,
                'unit' => 'per piece',
            ],
            'handwash' => [
                'label' => 'Handwash & Delicates',
                'blurb' => 'Silk, lace and anything the machine should never touch.',
                'icon' => 'sparkles',
                'from' => 50,
                'unit' => 'per piece',
            ],
            'comforter' => [
                'label' => 'Comforters & Beddings',
                'blurb' => 'Bulky bedding handled in our large-capacity machines.',
                'icon' => 'bed-double',
                'from' => 100,
                'unit' => 'per piece',
            ],
        ];
    }

    public static function serviceTypeKeys(): array
    {
        return array_keys(self::serviceTypes());
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
     */
    public static function priceList(): Collection
    {
        return LaundryService::query()
            ->with('serviceCategory')
            ->where('is_active', true)
            ->whereHas('serviceCategory', fn ($query) => $query->where('is_active', true))
            ->orderBy('service_category_id')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (LaundryService $service) => $service->serviceCategory?->name ?: 'Other Services');
    }

    /**
     * A non-binding estimate shown on the confirmation screen. The real total
     * is set at the counter once the load is weighed, which is exactly how the
     * branch already prices walk-ins.
     */
    public static function estimate(string $serviceType, ?float $kilos, bool $isRush): ?float
    {
        $types = self::serviceTypes();

        if (! isset($types[$serviceType])) {
            return null;
        }

        $from = (float) $types[$serviceType]['from'];

        // Piece-priced services cannot be estimated from weight, so we only
        // quote the per-load services and leave the rest for the branch.
        $perLoadServices = ['wash_dry_fold', 'wash_only', 'dry_only'];

        if (! in_array($serviceType, $perLoadServices, true)) {
            return $isRush ? $from + self::RUSH_SURCHARGE : $from;
        }

        $loads = max(1, (int) ceil(((float) ($kilos ?: 7)) / 7));
        $total = $from * $loads;

        return $isRush ? $total + self::RUSH_SURCHARGE : $total;
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
