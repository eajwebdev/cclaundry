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
    public function test_guide_table_matches_the_liquid_dosing_pdf(): void
    {
        $table = [
            5 => [25, 15], 6 => [30, 20], 7 => [30, 20], 8 => [35, 25], 9 => [35, 25], 10 => [40, 30],
            11 => [40, 30], 12 => [45, 35], 13 => [45, 35], 14 => [50, 40], 15 => [50, 40],
        ];

        foreach ($table as $kilos => [$detergent, $softener]) {
            $this->assertSame((float) $detergent, LaundryDosingGuide::detergentForWeight($kilos), "{$kilos} kg detergent");
            $this->assertSame((float) $softener, LaundryDosingGuide::softenerForWeight($kilos), "{$kilos} kg Yen Yen");
        }

        // Under 5 kg still uses the 5 kg dose; part kilos round up to the next row.
        $this->assertSame(25.0, LaundryDosingGuide::detergentForWeight(1.5));
        $this->assertSame(35.0, LaundryDosingGuide::detergentForWeight(7.5));
    }

    #[DataProvider('regularLaundrySchedule')]
    public function test_regular_laundry_uses_the_liquid_dosing_guide(
        float $kilos,
        float $expectedDetergent,
        float $expectedYenYen,
        float $expectedPlasticBag
    ): void {
        $service = new LaundryService([
            'name' => 'Regular Laundry',
            'pricing_type' => 'kilo',
            'minimum_kilos' => 5,
        ]);

        // The guide has one detergent column, so any detergent item follows it.
        $standard = $this->usage('SUP-DETERGENT', 'Standard liquid detergent', 'liter', 0.04);
        $mrsBloom = $this->usage('SUP-DETERGENT-BLOOM', 'Mrs. Bloom detergent', 'liter', 0.02);
        $yenYen = $this->usage('SUP-CONDITIONER', 'Fab con yen yen', 'liter', 0.02);
        $plasticBag = $this->usage('PKG-PLASTIC', 'Plastic bag', 'pcs', 1);

        $this->assertSame($expectedDetergent, InventoryConsumption::forOrderLine($service, $standard, $kilos));
        $this->assertSame($expectedDetergent, InventoryConsumption::forOrderLine($service, $mrsBloom, $kilos));
        $this->assertSame($expectedYenYen, InventoryConsumption::forOrderLine($service, $yenYen, $kilos));
        $this->assertSame($expectedPlasticBag, InventoryConsumption::forOrderLine($service, $plasticBag, $kilos));
    }

    public static function regularLaundrySchedule(): array
    {
        return [
            'below minimum uses five kilos' => [3, 0.025, 0.015, 1.0],
            'five kilos' => [5, 0.025, 0.015, 1.0],
            'six kilos' => [6, 0.03, 0.02, 1.0],
            'six and a half kilos rounds up to seven' => [6.5, 0.03, 0.02, 1.0],
            'seven kilos' => [7, 0.03, 0.02, 1.0],
            'eight kilos' => [8, 0.035, 0.025, 1.0],
            'nine kilos' => [9, 0.035, 0.025, 2.0],
            'ten kilos' => [10, 0.04, 0.03, 2.0],
            'eleven kilos' => [11, 0.04, 0.03, 2.0],
            'twelve kilos' => [12, 0.045, 0.035, 2.0],
            'thirteen kilos' => [13, 0.045, 0.035, 2.0],
            'fourteen kilos' => [14, 0.05, 0.04, 2.0],
            'fifteen kilos' => [15, 0.05, 0.04, 2.0],
            'sixteen kilos splits into two 8 kg loads' => [16, 0.07, 0.05, 2.0],
            'twenty kilos splits into two 10 kg loads' => [20, 0.08, 0.06, 3.0],
        ];
    }

    public function test_regular_laundry_supports_explicit_milliliter_units(): void
    {
        $service = new LaundryService([
            'name' => 'Regular Laundry',
            'pricing_type' => 'kilo',
            'minimum_kilos' => 5,
        ]);

        $detergent = $this->usage('SUP-DETERGENT', 'Mrs. Bloom detergent', 'ml', 40);
        $yenYen = $this->usage('SUP-FABCON-YENYEN', 'Fab con yen yen', 'ml', 20);

        $this->assertSame(25.0, InventoryConsumption::forOrderLine($service, $detergent, 5));
        $this->assertSame(15.0, InventoryConsumption::forOrderLine($service, $yenYen, 5));
        $this->assertSame(30.0, InventoryConsumption::forOrderLine($service, $detergent, 6));
        $this->assertSame(20.0, InventoryConsumption::forOrderLine($service, $yenYen, 6));
        $this->assertSame(35.0, InventoryConsumption::forOrderLine($service, $detergent, 8));
        $this->assertSame(25.0, InventoryConsumption::forOrderLine($service, $yenYen, 8));
    }

    #[DataProvider('beddingProfiles')]
    public function test_bedding_presets_use_the_bedding_dosing_guide(
        string $profile,
        float $minimumKilos,
        float $maximumKilos,
        float $minimumDetergent,
        float $maximumDetergent,
        float $minimumYenYen,
        float $maximumYenYen
    ): void {
        $service = new LaundryService([
            'name' => 'Configured bedding service',
            'pricing_type' => 'kilo',
            'dosing_profile' => $profile,
        ]);

        $detergent = $this->usage('SUP-BLOOM', 'Mrs Bloom detergent', 'ml', 1);
        $yenYen = $this->usage('SUP-CONDITIONER', 'Yen Yen softener', 'ml', 1);

        $this->assertSame($minimumDetergent, InventoryConsumption::forOrderLine($service, $detergent, $minimumKilos));
        $this->assertSame($maximumDetergent, InventoryConsumption::forOrderLine($service, $detergent, $maximumKilos));
        $this->assertSame($minimumYenYen, InventoryConsumption::forOrderLine($service, $yenYen, $minimumKilos));
        $this->assertSame($maximumYenYen, InventoryConsumption::forOrderLine($service, $yenYen, $maximumKilos));
    }

    public static function beddingProfiles(): array
    {
        // Max total weight range and dose range columns from the Bedding Dosing Guide.
        return [
            'bed sheet set' => [LaundryDosingGuide::BED_SHEETS, 7.5, 10, 35, 40, 25, 30],
            'single/twin comforter' => [LaundryDosingGuide::SINGLE_TWIN_COMFORTER, 4.5, 7.5, 25, 35, 15, 25],
            'double/queen comforter' => [LaundryDosingGuide::DOUBLE_QUEEN_COMFORTER, 5, 7, 25, 30, 15, 20],
            'king comforter' => [LaundryDosingGuide::KING_COMFORTER, 6, 9, 30, 35, 20, 25],
            'duvet insert' => [LaundryDosingGuide::DUVET_INSERT, 4.5, 12, 25, 45, 15, 35],
            'duvet cover' => [LaundryDosingGuide::DUVET_COVER, 4.2, 9, 25, 35, 15, 25],
        ];
    }

    public function test_towels_and_down_duvets_get_no_softener(): void
    {
        $towels = new LaundryService(['name' => 'Towels', 'pricing_type' => 'kilo', 'dosing_profile' => LaundryDosingGuide::TOWELS]);
        $downDuvet = new LaundryService(['name' => 'Down duvet', 'pricing_type' => 'kilo', 'dosing_profile' => LaundryDosingGuide::DUVET_INSERT_DOWN]);
        $detergent = $this->usage('SUP-BLOOM', 'Mrs Bloom detergent', 'ml', 1);
        $yenYen = $this->usage('SUP-CONDITIONER', 'Yen Yen softener', 'ml', 1);

        $this->assertSame(35.0, InventoryConsumption::forOrderLine($towels, $detergent, 8));
        $this->assertSame(0.0, InventoryConsumption::forOrderLine($towels, $yenYen, 8));
        $this->assertSame(0.0, InventoryConsumption::forOrderLine($downDuvet, $yenYen, 6));
    }

    public function test_very_dirty_loads_use_the_dose_for_three_more_kilos(): void
    {
        $service = new LaundryService(['name' => 'Heavy Duty', 'pricing_type' => 'kilo', 'dosing_profile' => LaundryDosingGuide::VERY_DIRTY]);

        // Guide example: 5 kg load = 8 kg dose.
        $this->assertSame(35.0, InventoryConsumption::forOrderLine($service, $this->usage('SUP-BLOOM', 'Mrs Bloom detergent', 'ml', 1), 5));
        $this->assertSame(25.0, InventoryConsumption::forOrderLine($service, $this->usage('SUP-CONDITIONER', 'Yen Yen softener', 'ml', 1), 5));
    }

    public function test_bedding_sold_per_piece_respects_weight_and_piece_limits(): void
    {
        $service = new LaundryService(['name' => 'King Comforter', 'pricing_type' => 'piece', 'dosing_profile' => LaundryDosingGuide::KING_COMFORTER]);

        // One king comforter (~4.5 kg) still takes the 5 kg dose, in the Titan.
        $this->assertSame([['kilos' => 4.5, 'machine' => 'titan', 'detergent_ml' => 25.0, 'softener_ml' => 15.0]], LaundryDosingGuide::loadsForService($service, 1));

        // Three king comforters: max 2 per load, so two loads of 6.75 kg (7 kg dose).
        $loads = LaundryDosingGuide::loadsForService($service, 3);
        $this->assertCount(2, $loads);
        $this->assertSame(30.0, $loads[0]['detergent_ml']);
        $this->assertSame(20.0, $loads[0]['softener_ml']);
    }

    public function test_machine_follows_the_machine_load_guide(): void
    {
        $this->assertSame('giant', LaundryDosingGuide::machineFor(LaundryDosingGuide::REGULAR, 10));
        $this->assertSame('titan', LaundryDosingGuide::machineFor(LaundryDosingGuide::REGULAR, 11));
        $this->assertSame('giant', LaundryDosingGuide::machineFor(LaundryDosingGuide::TOWELS, 8));
        $this->assertSame('titan', LaundryDosingGuide::machineFor(LaundryDosingGuide::TOWELS, 9));
        $this->assertSame('giant', LaundryDosingGuide::machineFor(LaundryDosingGuide::SINGLE_TWIN_COMFORTER, 7.5));
        $this->assertSame('titan', LaundryDosingGuide::machineFor(LaundryDosingGuide::KING_COMFORTER, 4.5));
        $this->assertSame('titan', LaundryDosingGuide::machineFor(LaundryDosingGuide::DUVET_INSERT, 3));
    }

    public function test_load_priced_services_dose_each_load(): void
    {
        $service = new LaundryService([
            'name' => 'Wash Dry Fold per load',
            'pricing_type' => 'load',
            'kilos_per_load' => 8,
            'dosing_profile' => LaundryDosingGuide::REGULAR,
        ]);

        $this->assertSame(70.0, InventoryConsumption::forOrderLine($service, $this->usage('SUP-BLOOM', 'Mrs Bloom detergent', 'ml', 1), 2));
        $this->assertSame(50.0, InventoryConsumption::forOrderLine($service, $this->usage('SUP-CONDITIONER', 'Yen Yen softener', 'ml', 1), 2));
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
