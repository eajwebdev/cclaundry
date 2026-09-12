<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\LaundryService;
use App\Models\ServiceInventoryUsage;

class DefaultServiceInventoryUsages
{
    /**
     * What a service takes out of stock when it is sold.
     *
     * Only the services the price list actually sells appear here; the old
     * per-item catalogue (comforter sizes, dry cleaning, rugs and so on) is
     * gone, and rules for services that no longer exist only ever matched
     * nothing.
     *
     * The washing services deliberately deduct nothing: detergent and fabric
     * conditioner are add-ons the customer picks and pays for, so charging
     * stock against the wash as well would count the same sachet twice.
     */
    public static function rules(): array
    {
        return [
            'Uniform Steaming (Kids)' => ['Hanger' => 1],
            'Uniform Steaming (Adult)' => ['Hanger' => 1],
            'Ariel Detergent' => ['Detergent Powder' => 0.10],
            'Tide Detergent' => ['Detergent Powder' => 0.10],
            'Downy' => ['Fabric Conditioner' => 0.08],
        ];
    }

    public static function seedForBranch(Branch $branch): void
    {
        $services = LaundryService::query()
            ->where('branch_id', $branch->id)
            ->get()
            ->keyBy('name');

        $inventory = Inventory::query()
            ->where('branch_id', $branch->id)
            ->get()
            ->keyBy('name');

        foreach (self::rules() as $serviceName => $items) {
            $service = $services->get($serviceName);

            if (! $service) {
                continue;
            }

            foreach ($items as $inventoryName => $quantity) {
                $stock = $inventory->get($inventoryName);

                if (! $stock) {
                    continue;
                }

                ServiceInventoryUsage::updateOrCreate(
                    [
                        'laundry_service_id' => $service->id,
                        'inventory_id' => $stock->id,
                    ],
                    ['quantity' => $quantity]
                );
            }

            $validInventoryIds = collect($items)
                ->keys()
                ->map(fn (string $inventoryName) => $inventory->get($inventoryName)?->id)
                ->filter()
                ->values();

            $service->inventoryUsages()
                ->whereNotIn('inventory_id', $validInventoryIds)
                ->delete();
        }
    }
}
