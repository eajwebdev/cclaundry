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
        float $expectedFabcon,
        float $expectedPlasticBag
    ): void {
        $service = new LaundryService([
            'name' => 'Regular Laundry',
            'pricing_type' => 'kilo',
            'minimum_kilos' => 5,
        ]);

        $detergent = $this->usage('SUP-DETERGENT', 'Detergent Mrs bloom', 'liter', 0.04);
        $fabcon = $this->usage('SUP-CONDITIONER', 'Fab con yen yen', 'liter', 0.02);
        $plasticBag = $this->usage('PKG-PLASTIC', 'Plastic bag', 'pcs', 1);

        $this->assertSame($expectedDetergent, InventoryConsumption::forOrderLine($service, $detergent, $kilos));
        $this->assertSame($expectedFabcon, InventoryConsumption::forOrderLine($service, $fabcon, $kilos));
        $this->assertSame($expectedPlasticBag, InventoryConsumption::forOrderLine($service, $plasticBag, $kilos));
    }

    public static function regularLaundrySchedule(): array
    {
        return [
            'below minimum uses five kilos' => [3, 0.04, 0.02, 1.0],
            'five kilos' => [5, 0.04, 0.02, 1.0],
            'six kilos' => [6, 0.045, 0.025, 1.0],
            'six and a half kilos' => [6.5, 0.0475, 0.025, 1.0],
            'seven kilos' => [7, 0.05, 0.025, 1.0],
            'eight kilos' => [8, 0.055, 0.03, 1.0],
            'nine kilos' => [9, 0.06, 0.03, 2.0],
            'sixteen kilos (two loads)' => [16, 0.095, 0.03, 2.0],
        ];
    }

    public function test_regular_laundry_supports_explicit_milliliter_units(): void
    {
        $service = new LaundryService([
            'name' => 'Regular Laundry',
            'pricing_type' => 'kilo',
            'minimum_kilos' => 5,
        ]);

        $detergent = $this->usage('SUP-DETERGENT-BLOOM', 'Mrs bloom', 'ml', 40);
        $fabcon = $this->usage('SUP-FABCON-YENYEN', 'Fab con yen yen', 'ml', 20);

        // 5kg: 40ml detergent, 20ml fab con
        $this->assertSame(40.0, InventoryConsumption::forOrderLine($service, $detergent, 5));
        $this->assertSame(20.0, InventoryConsumption::forOrderLine($service, $fabcon, 5));

        // 6kg: 45ml detergent (+5ml), 25ml fab con
        $this->assertSame(45.0, InventoryConsumption::forOrderLine($service, $detergent, 6));
        $this->assertSame(25.0, InventoryConsumption::forOrderLine($service, $fabcon, 6));

        // 7kg: 50ml detergent (+10ml), 25ml fab con
        $this->assertSame(50.0, InventoryConsumption::forOrderLine($service, $detergent, 7));
        $this->assertSame(25.0, InventoryConsumption::forOrderLine($service, $fabcon, 7));

        // 8kg: 55ml detergent (+15ml), 30ml fab con
        $this->assertSame(55.0, InventoryConsumption::forOrderLine($service, $detergent, 8));
        $this->assertSame(30.0, InventoryConsumption::forOrderLine($service, $fabcon, 8));
    }

    public function test_plastic_bag_dosage_does_not_multiply_by_kilos_for_regular_laundry(): void
    {
        $service = new LaundryService([
            'name' => 'Regular Laundry',
            'pricing_type' => 'kilo',
            'minimum_kilos' => 5,
        ]);

        $plasticBag = $this->usage('PKG-PLASTIC-BAG', 'Plastic bag', 'pcs', 1);

        // An order of 5kg, 6kg, 7kg, 8kg uses exactly 1 plastic bag (not 5, 6, 7, 8 bags)
        $this->assertSame(1.0, InventoryConsumption::forOrderLine($service, $plasticBag, 5));
        $this->assertSame(1.0, InventoryConsumption::forOrderLine($service, $plasticBag, 6));
        $this->assertSame(1.0, InventoryConsumption::forOrderLine($service, $plasticBag, 7));
        $this->assertSame(1.0, InventoryConsumption::forOrderLine($service, $plasticBag, 8));
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
