<?php

namespace Tests\Unit;

use App\Models\Inventory;
use App\Models\LaundryService;
use App\Models\ServiceInventoryUsage;
use App\Support\InventoryConsumption;
use App\Support\LaundryDosingGuide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InventoryConsumptionTest extends TestCase
{
    #[DataProvider('regularLaundrySchedule')]
    public function test_regular_laundry_uses_the_client_weight_schedule(
        float $kilos,
        float $expectedStandardDetergent,
        float $expectedMrsBloom,
        float $expectedFabcon,
        float $expectedPlasticBag
    ): void {
        $service = new LaundryService([
            'name' => 'Regular Laundry',
            'pricing_type' => 'kilo',
            'minimum_kilos' => 5,
        ]);

        $detergent = $this->usage('SUP-DETERGENT', 'Standard liquid detergent', 'liter', 0.04);
        $mrsBloom = $this->usage('SUP-DETERGENT-BLOOM', 'Mrs. Bloom detergent', 'liter', 0.02);
        $fabcon = $this->usage('SUP-CONDITIONER', 'Fab con yen yen', 'liter', 0.02);
        $plasticBag = $this->usage('PKG-PLASTIC', 'Plastic bag', 'pcs', 1);

        $this->assertSame($expectedStandardDetergent, InventoryConsumption::forOrderLine($service, $detergent, $kilos));
        $this->assertSame($expectedMrsBloom, InventoryConsumption::forOrderLine($service, $mrsBloom, $kilos));
        $this->assertSame($expectedFabcon, InventoryConsumption::forOrderLine($service, $fabcon, $kilos));
        $this->assertSame($expectedPlasticBag, InventoryConsumption::forOrderLine($service, $plasticBag, $kilos));
    }

    public static function regularLaundrySchedule(): array
    {
        return [
            'below minimum uses five kilos' => [3, 0.04, 0.02, 0.02, 1.0],
            'five kilos' => [5, 0.04, 0.02, 0.02, 1.0],
            'six kilos' => [6, 0.045, 0.022, 0.025, 1.0],
            'six and a half kilos' => [6.5, 0.0475, 0.0235, 0.025, 1.0],
            'seven kilos' => [7, 0.05, 0.025, 0.025, 1.0],
            'eight kilos' => [8, 0.06, 0.03, 0.03, 1.0],
            'nine kilos' => [9, 0.065, 0.032, 0.03, 2.0],
            'ten kilos' => [10, 0.07, 0.035, 0.035, 2.0],
            'eleven kilos' => [11, 0.075, 0.037, 0.035, 2.0],
            'twelve kilos' => [12, 0.085, 0.042, 0.04, 2.0],
            'thirteen kilos' => [13, 0.09, 0.045, 0.045, 2.0],
            'fourteen kilos' => [14, 0.095, 0.047, 0.045, 2.0],
            'fifteen kilos' => [15, 0.105, 0.05, 0.05, 2.0],
            'sixteen kilos (two machine doses)' => [16, 0.145, 0.07, 0.07, 2.0],
        ];
    }

    public function test_regular_laundry_supports_explicit_milliliter_units(): void
    {
        $service = new LaundryService([
            'name' => 'Regular Laundry',
            'pricing_type' => 'kilo',
            'minimum_kilos' => 5,
        ]);

        $detergent = $this->usage('SUP-DETERGENT', 'Standard liquid detergent', 'ml', 40);
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

        // 8kg: 60ml standard detergent, 30ml fab con
        $this->assertSame(60.0, InventoryConsumption::forOrderLine($service, $detergent, 8));
        $this->assertSame(30.0, InventoryConsumption::forOrderLine($service, $fabcon, 8));
    }

    #[DataProvider('beddingProfiles')]
    public function test_bedding_presets_use_the_attached_guide_ranges(
        string $profile,
        float $minimumKilos,
        float $maximumKilos,
        float $minimumStandard,
        float $maximumStandard,
        float $minimumBloom,
        float $maximumBloom,
        float $minimumSoftener,
        float $maximumSoftener
    ): void {
        $service = new LaundryService([
            'name' => 'Configured bedding service',
            'pricing_type' => 'kilo',
            'dosing_profile' => $profile,
        ]);

        $standard = $this->usage('SUP-DETERGENT', 'Standard detergent', 'ml', 1);
        $bloom = $this->usage('SUP-BLOOM', 'Mrs Bloom detergent', 'ml', 1);
        $softener = $this->usage('SUP-CONDITIONER', 'Fabric softener', 'ml', 1);

        $this->assertSame($minimumStandard, InventoryConsumption::forOrderLine($service, $standard, $minimumKilos));
        $this->assertSame($maximumStandard, InventoryConsumption::forOrderLine($service, $standard, $maximumKilos));
        $this->assertSame($minimumBloom, InventoryConsumption::forOrderLine($service, $bloom, $minimumKilos));
        $this->assertSame($maximumBloom, InventoryConsumption::forOrderLine($service, $bloom, $maximumKilos));
        $this->assertSame($minimumSoftener, InventoryConsumption::forOrderLine($service, $softener, $minimumKilos));
        $this->assertSame($maximumSoftener, InventoryConsumption::forOrderLine($service, $softener, $maximumKilos));
    }

    public static function beddingProfiles(): array
    {
        return [
            'bed sheets' => [LaundryDosingGuide::BED_SHEETS, 5, 7, 40, 55, 20, 25, 20, 25],
            'single/twin comforter' => [LaundryDosingGuide::SINGLE_TWIN_COMFORTER, 5, 7, 50, 60, 25, 30, 20, 25],
            'double/queen comforter' => [LaundryDosingGuide::DOUBLE_QUEEN_COMFORTER, 7, 10, 65, 75, 30, 35, 30, 35],
            'king comforter' => [LaundryDosingGuide::KING_COMFORTER, 10, 15, 80, 105, 40, 50, 40, 50],
            'duvet insert' => [LaundryDosingGuide::DUVET_INSERT, 7, 15, 65, 105, 30, 50, 30, 50],
            'duvet cover' => [LaundryDosingGuide::DUVET_COVER, 5, 8, 40, 60, 20, 30, 20, 30],
        ];
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
