<?php

namespace App\Support;

use App\Models\Inventory;
use App\Models\LaundryService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FinishingSprayConsumption
{
    public const MILLILITERS_PER_KILO = 3.5;

    public static function isSpray(?LaundryService $service): bool
    {
        return $service && ($service->report_category === 'finishing_spray'
            || mb_strtolower(trim($service->name)) === 'finishing spray');
    }

    /** Add-on quantities are selections, not laundry weights. */
    public static function kilos(array $items, Collection $services): float
    {
        $kilos = 0.0;
        foreach ($items as $item) {
            $service = $services->get((int) $item['laundry_service_id']);
            if (! $service || self::isSpray($service) || $service->serviceCategory?->is_addon) {
                continue;
            }
            if ($service->pricing_type === 'kilo') {
                $kilos += (float) $item['quantity'];
            } elseif ($service->pricing_type === 'load' && $service->kilos_per_load > 0) {
                $kilos += (float) $item['quantity'] * (float) $service->kilos_per_load;
            }
        }

        if ($kilos <= 0) {
            throw ValidationException::withMessages(['items' => 'Finishing spray requires laundry measured in kilos or a load with configured kilos per load.']);
        }

        return $kilos;
    }

    public static function quantity(Inventory $inventory, float $kilos): float
    {
        $unit = mb_strtolower(trim($inventory->unit));
        if (! in_array($unit, ['ml', 'milliliter', 'milliliters', 'liter', 'liters', 'litre', 'litres', 'l'], true)) {
            throw ValidationException::withMessages(['items' => "Record {$inventory->name} stock in mL or liters before using the 3.5 mL/kg rule; '{$inventory->unit}' has no known volume conversion."]);
        }

        return InventoryConsumption::millilitersInInventoryUnit(self::MILLILITERS_PER_KILO * $kilos, $unit);
    }
}
