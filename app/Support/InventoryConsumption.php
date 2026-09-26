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
        if (! self::isRegularLaundry($service)) {
            return round((float) $usage->quantity * $serviceQuantity, 4);
        }

        $inventory = $usage->inventory;
        $sku = strtoupper(trim((string) $inventory?->sku));
        $name = mb_strtolower(trim((string) $inventory?->name));
        $kilos = max($serviceQuantity, (float) ($service->minimum_kilos ?? 5), 5);

        // Detergent (Mrs Bloom / Detergent Powder / any detergent):
        // 40 ml at 5 kg baseline, plus 5 ml per additional kilo.
        // Fractional weights receive proportional dosage (6.5 kg = 47.5 ml).
        if (self::isDetergent($sku, $name)) {
            $milliliters = 40 + (5 * max(0, $kilos - 5));

            return self::millilitersInInventoryUnit($milliliters, (string) $inventory?->unit);
        }

        // Fabric Conditioner (Fab con yen yen / conditioner / fabcon):
        // 20 ml for 5 kg, 25 ml for 6-7 kg, 30 ml for 8 kg and above.
        if (self::isFabricConditioner($sku, $name)) {
            $milliliters = match (true) {
                $kilos <= 5 => 20,
                $kilos <= 7 => 25,
                default => 30,
            };

            return self::millilitersInInventoryUnit($milliliters, (string) $inventory?->unit);
        }

        // Plastic Bag (Plastic packaging / laundry bag / plastic bag):
        // 1 plastic bag for loads up to 8 kg (or service load capacity).
        // Does not multiply by kilos so 5 kg - 8 kg consumes 1 bag.
        if (self::isPlasticBag($sku, $name)) {
            $kilosPerBag = (float) ($service->kilos_per_load ?: 8);
            if ($kilosPerBag <= 0) {
                $kilosPerBag = 8;
            }

            $loads = (int) ceil(round($kilos / $kilosPerBag, 6));
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
