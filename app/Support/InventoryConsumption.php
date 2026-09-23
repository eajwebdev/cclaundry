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
     * Laundry follows the client's weight-based detergent/fabcon schedule.
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

        if ($sku === 'SUP-DETERGENT' || str_contains($name, 'detergent')) {
            // 40 ml at 5 kg, plus 5 ml per additional kilo. Fractional
            // weights receive the same proportional dosage (6.5 kg = 47.5 ml).
            $milliliters = 40 + (5 * max(0, $kilos - 5));

            return self::millilitersInInventoryUnit($milliliters, (string) $inventory?->unit);
        }

        if ($sku === 'SUP-CONDITIONER' || str_contains($name, 'fabric conditioner') || str_contains($name, 'fabcon')) {
            $milliliters = match (true) {
                $kilos <= 5 => 20,
                $kilos <= 7 => 25,
                default => 30,
            };

            return self::millilitersInInventoryUnit($milliliters, (string) $inventory?->unit);
        }

        return round((float) $usage->quantity * $serviceQuantity, 4);
    }

    private static function isRegularLaundry(LaundryService $service): bool
    {
        return mb_strtolower(trim((string) $service->name)) === 'regular laundry'
            && $service->pricing_type === 'kilo';
    }

    private static function millilitersInInventoryUnit(float $milliliters, string $unit): float
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
