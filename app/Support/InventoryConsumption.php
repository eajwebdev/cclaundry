<?php

namespace App\Support;

use App\Models\LaundryService;
use App\Models\ServiceInventoryUsage;

class InventoryConsumption
{
    /**
     * Calculate the stock quantity consumed by one job-order line.
     *
     * Most service recipes remain a flat quantity per unit sold. Regular
     * Laundry follows the client's weight-based detergent/fabcon schedule and
     * packaging rules.
     */
    public static function forOrderLine(
        LaundryService $service,
        ServiceInventoryUsage $usage,
        float $serviceQuantity
    ): float {
        $profile = LaundryDosingGuide::profileFor($service);

        if (! $profile) {
            return round((float) $usage->quantity * $serviceQuantity, 4);
        }

        $inventory = $usage->inventory;
        $sku = strtoupper(trim((string) $inventory?->sku));
        $name = mb_strtolower(trim((string) $inventory?->name));
        $quantity = $service->pricing_type === 'kilo'
            ? max($serviceQuantity, (float) ($service->minimum_kilos ?? 0))
            : $serviceQuantity;

        if (self::isDetergent($sku, $name)) {
            $chemical = self::isMrsBloomDetergent($sku, $name)
                ? LaundryDosingGuide::MRS_BLOOM_DETERGENT
                : LaundryDosingGuide::STANDARD_DETERGENT;
            $milliliters = LaundryDosingGuide::milliliters($profile, $chemical, $quantity, (string) $service->pricing_type);

            return self::millilitersInInventoryUnit($milliliters, (string) $inventory?->unit);
        }

        if (self::isFabricConditioner($sku, $name)) {
            $milliliters = LaundryDosingGuide::milliliters(
                $profile,
                LaundryDosingGuide::FABRIC_SOFTENER,
                $quantity,
                (string) $service->pricing_type
            );

            return self::millilitersInInventoryUnit($milliliters, (string) $inventory?->unit);
        }

        // Plastic Bag (Plastic packaging / laundry bag / plastic bag):
        // Keep Regular Laundry's established 8 kg packaging rule. Other guide
        // profiles use their own maximum load from the dosing table.
        if (self::isPlasticBag($sku, $name)) {
            if (self::isRegularLaundry($service)) {
                $kilosPerBag = (float) ($service->kilos_per_load ?: 8);
                $loads = (int) ceil(round($quantity / max($kilosPerBag, 1), 6));
            } else {
                $loads = LaundryDosingGuide::loadCount($profile, $quantity, (string) $service->pricing_type);
            }

            $bagsPerLoad = (float) $usage->quantity > 0 ? (float) $usage->quantity : 1.0;

            return round(max(1, $loads) * $bagsPerLoad, 4);
        }

        return round((float) $usage->quantity * $serviceQuantity, 4);
    }

    public static function isRegularLaundry(LaundryService $service): bool
    {
        $name = mb_strtolower(trim((string) $service->name));

        return ($name === 'regular laundry' || str_contains($name, 'regular laundry'))
            && $service->pricing_type === 'kilo';
    }

    public static function isDetergent(?string $sku, ?string $name): bool
    {
        $sku = strtoupper(trim((string) $sku));
        $name = mb_strtolower(trim((string) $name));

        return $sku === 'SUP-DETERGENT'
            || str_contains($sku, 'DETERGENT')
            || str_contains($sku, 'BLOOM')
            || str_contains($name, 'detergent')
            || str_contains($name, 'mrs bloom')
            || str_contains($name, 'mrs. bloom')
            || str_contains($name, 'mrs-bloom')
            || str_contains($name, 'bloom');
    }

    public static function isMrsBloomDetergent(?string $sku, ?string $name): bool
    {
        $sku = strtoupper(trim((string) $sku));
        $name = mb_strtolower(trim((string) $name));

        return str_contains($sku, 'BLOOM')
            || str_contains($name, 'mrs bloom')
            || str_contains($name, 'mrs. bloom')
            || str_contains($name, 'mrs-bloom');
    }

    public static function isFabricConditioner(?string $sku, ?string $name): bool
    {
        $sku = strtoupper(trim((string) $sku));
        $name = mb_strtolower(trim((string) $name));

        return $sku === 'SUP-CONDITIONER'
            || str_contains($sku, 'CONDITIONER')
            || str_contains($sku, 'FABCON')
            || str_contains($sku, 'YEN')
            || str_contains($name, 'fabric conditioner')
            || str_contains($name, 'fabcon')
            || str_contains($name, 'fab con')
            || str_contains($name, 'conditioner')
            || str_contains($name, 'yen yen')
            || str_contains($name, 'yenyen');
    }

    public static function isPlasticBag(?string $sku, ?string $name): bool
    {
        $sku = strtoupper(trim((string) $sku));
        $name = mb_strtolower(trim((string) $name));

        return str_starts_with($sku, 'PKG-PLASTIC')
            || $sku === 'PKG-LAUNDRY-BAG'
            || str_contains($sku, 'PLASTIC')
            || str_contains($sku, 'BAG')
            || str_contains($name, 'plastic bag')
            || str_contains($name, 'plastic packaging')
            || str_contains($name, 'laundry bag')
            || str_contains($name, 'plastic')
            || str_contains($name, 'bag');
    }

    public static function millilitersInInventoryUnit(float $milliliters, string $unit): float
    {
        $normalizedUnit = mb_strtolower(trim($unit));

        // Default stock is recorded in liters (or kilograms for the legacy
        // detergent item), so 40 ml is stored as 0.040. Respect inventories
        // that are explicitly tracked in milliliters as well.
        if (in_array($normalizedUnit, ['ml', 'milliliter', 'milliliters'], true)) {
            return round($milliliters, 4);
        }

        return round($milliliters / 1000, 4);
    }
}
