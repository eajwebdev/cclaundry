<?php

namespace Tests\Unit;

use App\Models\Inventory;
use App\Models\LaundryService;
use App\Models\ServiceInventoryUsage;
use App\Support\InventoryConsumption;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InventoryConsumptionTest extends TestCase
{
    #[DataProvider('regularLaundrySchedule')]
    public function test_regular_laundry_uses_the_client_weight_schedule(
        float $kilos,
        float $expectedDetergent,
        float $expectedFabcon
    ): void {
        $service = new LaundryService([
            'name' => 'Regular Laundry',
            'pricing_type' => 'kilo',
            'minimum_kilos' => 5,
        ]);

        $detergent = $this->usage('SUP-DETERGENT', 'Detergent Powder', 'liter', 0.04);
        $fabcon = $this->usage('SUP-CONDITIONER', 'Fabric Conditioner', 'liter', 0.02);

        $this->assertSame($expectedDetergent, InventoryConsumption::forOrderLine($service, $detergent, $kilos));
        $this->assertSame($expectedFabcon, InventoryConsumption::forOrderLine($service, $fabcon, $kilos));
    }

    public static function regularLaundrySchedule(): array
    {
        return [
            'below minimum uses five kilos' => [3, 0.04, 0.02],
            'five kilos' => [5, 0.04, 0.02],
            'six kilos' => [6, 0.045, 0.025],
            'six and a half kilos' => [6.5, 0.0475, 0.025],
            'seven kilos' => [7, 0.05, 0.025],
            'eight kilos' => [8, 0.055, 0.03],
            'nine kilos' => [9, 0.06, 0.03],
        ];
    }

    public function test_other_services_keep_the_flat_recipe_calculation(): void
    {
        $service = new LaundryService(['name' => 'Wash Only', 'pricing_type' => 'load']);
        $usage = $this->usage('SUP-DETERGENT', 'Detergent Powder', 'kg', 0.1);

        $this->assertSame(0.3, InventoryConsumption::forOrderLine($service, $usage, 3));
    }

    private function usage(string $sku, string $name, string $unit, float $quantity): ServiceInventoryUsage
    {
        $usage = new ServiceInventoryUsage(['quantity' => $quantity]);
        $usage->setRelation('inventory', new Inventory([
            'sku' => $sku,
            'name' => $name,
            'unit' => $unit,
        ]));

        return $usage;
    }
}
