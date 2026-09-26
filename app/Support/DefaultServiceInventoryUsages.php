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
     * Regular Laundry has its own production recipe. Its quantities below are
     * the five-kilo baseline; InventoryConsumption applies the client's
     * weight-based detergent and fabric-conditioner schedule at deduction time.
     */
    public static function rules(): array
    {
        return [
            'Regular Laundry' => [
                'Detergent Powder' => 0.04,
                'Fabric Conditioner' => 0.02,
                'Plastic Packaging' => 1,
            ],
            'Uniform Steaming (Kids)' => ['Hanger' => 1],
            'Uniform Steaming (Adult)' => ['Hanger' => 1],
            'Ariel Detergent' => ['Detergent Powder' => 0.10],
            'Tide Detergent' => ['Detergent Powder' => 0.10],
            'Downy Mystique' => ['Downy Mystique Fabric Conditioner' => 1],
            'Downy Sunrise' => ['Downy Sunrise Fabric Conditioner' => 1],
            'Finishing Spray' => ['Finishing Spray' => 1],
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
            ->get();

        foreach (self::rules() as $serviceName => $items) {
            $service = $services->get($serviceName);

            if (! $service) {
                continue;
            }

            $syncedInventoryIds = [];

            foreach ($items as $inventoryName => $quantity) {
                $stock = self::findStockForBranch($inventory, $inventoryName);

                if (! $stock) {
                    continue;
                }

                $syncedInventoryIds[] = $stock->id;

                ServiceInventoryUsage::updateOrCreate(
                    [
                        'laundry_service_id' => $service->id,
                        'inventory_id' => $stock->id,
                    ],
                    ['quantity' => $quantity]
                );
            }
        }
    }

    private static function findStockForBranch($inventoryCollection, string $preferredName): ?Inventory
    {
        $direct = $inventoryCollection->firstWhere('name', $preferredName);
        if ($direct) {
            return $direct;
        }

        return match ($preferredName) {
            'Detergent Powder' => $inventoryCollection->first(fn ($item) => InventoryConsumption::isDetergent($item->sku, $item->name)),
            'Fabric Conditioner' => $inventoryCollection->first(fn ($item) => InventoryConsumption::isFabricConditioner($item->sku, $item->name)),
            'Plastic Packaging' => $inventoryCollection->first(fn ($item) => InventoryConsumption::isPlasticBag($item->sku, $item->name)),
            default => null,
        };
    }
}
