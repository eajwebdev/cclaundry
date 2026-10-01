<?php

namespace App\Support;

use App\Models\LaundryService;

/**
 * Cane & Cotton's liquid dosing guide, expressed in milliliters.
 *
 * The guide is configuration only: choosing a profile never changes stock by
 * itself. Inventory is consumed only when a job order is created/accepted by
 * production, through InventoryConsumption.
 */
class LaundryDosingGuide
{
    public const REGULAR = 'regular_liquid';

    public const BED_SHEETS = 'bed_sheets';

    public const SINGLE_TWIN_COMFORTER = 'single_twin_comforter';

    public const DOUBLE_QUEEN_COMFORTER = 'double_queen_comforter';

    public const KING_COMFORTER = 'king_comforter';

    public const DUVET_INSERT = 'duvet_insert';

    public const DUVET_COVER = 'duvet_cover';

    public const STANDARD_DETERGENT = 'standard_detergent';

    public const MRS_BLOOM_DETERGENT = 'mrs_bloom_detergent';

    public const FABRIC_SOFTENER = 'fabric_softener';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::REGULAR => 'Liquid guide — regular laundry (5–15 kg)',
            self::BED_SHEETS => 'Bedding — bed sheets (5–7 kg)',
            self::SINGLE_TWIN_COMFORTER => 'Bedding — Single/Twin comforter (5–7 kg)',
            self::DOUBLE_QUEEN_COMFORTER => 'Bedding — Double/Queen comforter (7–10 kg)',
            self::KING_COMFORTER => 'Bedding — King comforter (10–15 kg)',
            self::DUVET_INSERT => 'Bedding — duvet insert (7–15 kg)',
            self::DUVET_COVER => 'Bedding — duvet cover (5–8 kg)',
        ];
    }

    /**
     * Resolve the saved profile, with a compatibility fallback for the
     * long-standing Regular Laundry service. No database row is rewritten.
     */
    public static function profileFor(LaundryService $service): ?string
    {
        $profile = trim((string) $service->dosing_profile);

        if (array_key_exists($profile, self::labels())) {
            return $profile;
        }

        return InventoryConsumption::isRegularLaundry($service) ? self::REGULAR : null;
    }

    /** @return array{min: float, max: float, standard: array<float, float>, bloom: array<float, float>, softener: array<float, float>}|null */
    public static function profile(?string $profile): ?array
    {
        return match ($profile) {
            self::REGULAR => [
                'min' => 5.0,
                'max' => 15.0,
                'standard' => [5.0 => 40.0, 6.0 => 45.0, 7.0 => 50.0, 8.0 => 60.0, 9.0 => 65.0, 10.0 => 70.0, 11.0 => 75.0, 12.0 => 85.0, 13.0 => 90.0, 14.0 => 95.0, 15.0 => 105.0],
                'bloom' => [5.0 => 20.0, 6.0 => 22.0, 7.0 => 25.0, 8.0 => 30.0, 9.0 => 32.0, 10.0 => 35.0, 11.0 => 37.0, 12.0 => 42.0, 13.0 => 45.0, 14.0 => 47.0, 15.0 => 50.0],
                'softener' => [5.0 => 20.0, 6.0 => 25.0, 7.0 => 25.0, 8.0 => 30.0, 9.0 => 30.0, 10.0 => 35.0, 11.0 => 35.0, 12.0 => 40.0, 13.0 => 45.0, 14.0 => 45.0, 15.0 => 50.0],
            ],
            self::BED_SHEETS => self::rangeProfile(5, 7, 40, 55, 20, 25, 20, 25),
            self::SINGLE_TWIN_COMFORTER => self::rangeProfile(5, 7, 50, 60, 25, 30, 20, 25),
            self::DOUBLE_QUEEN_COMFORTER => self::rangeProfile(7, 10, 65, 75, 30, 35, 30, 35),
            self::KING_COMFORTER => self::rangeProfile(10, 15, 80, 105, 40, 50, 40, 50),
            self::DUVET_INSERT => self::rangeProfile(7, 15, 65, 105, 30, 50, 30, 50),
            self::DUVET_COVER => self::rangeProfile(5, 8, 40, 60, 20, 30, 20, 30),
            default => null,
        };
    }

    public static function milliliters(string $profile, string $chemical, float $quantity, string $pricingType): float
    {
        $definition = self::profile($profile);
        if (! $definition || $quantity <= 0) {
            return 0.0;
        }

        $schedule = match ($chemical) {
            self::STANDARD_DETERGENT => $definition['standard'],
            self::MRS_BLOOM_DETERGENT => $definition['bloom'],
            self::FABRIC_SOFTENER => $definition['softener'],
            default => [],
        };

        if ($schedule === []) {
            return 0.0;
        }

        // Load/piece/custom quantities already represent a count at the POS.
        // Use the guide's upper dose for each complete item/load.
        if ($pricingType !== 'kilo') {
            return round(self::doseForWeight($schedule, $definition['max']) * $quantity, 4);
        }

        // A kilo-priced line can exceed one machine. Dose complete maximum-size
        // loads first, then the remainder at no less than the guide minimum.
        $remaining = $quantity;
        $total = 0.0;
        while ($remaining > $definition['max']) {
            $total += self::doseForWeight($schedule, $definition['max']);
            $remaining -= $definition['max'];
        }

        if ($remaining > 0) {
            $total += self::doseForWeight($schedule, max($remaining, $definition['min']));
        }

        return round($total, 4);
    }

    public static function loadCount(string $profile, float $quantity, string $pricingType): int
    {
        $definition = self::profile($profile);
        if (! $definition || $quantity <= 0) {
            return 0;
        }

        return $pricingType === 'kilo'
            ? max(1, (int) ceil(round($quantity / $definition['max'], 6)))
            : max(1, (int) ceil($quantity));
    }

    /** @return array{min: float, max: float, standard: array<float, float>, bloom: array<float, float>, softener: array<float, float>} */
    private static function rangeProfile(
        float $min,
        float $max,
        float $standardMin,
        float $standardMax,
        float $bloomMin,
        float $bloomMax,
        float $softenerMin,
        float $softenerMax
    ): array {
        return [
            'min' => $min,
            'max' => $max,
            'standard' => [$min => $standardMin, $max => $standardMax],
            'bloom' => [$min => $bloomMin, $max => $bloomMax],
            'softener' => [$min => $softenerMin, $max => $softenerMax],
        ];
    }

    /** @param array<float, float> $schedule */
    private static function doseForWeight(array $schedule, float $weight): float
    {
        ksort($schedule, SORT_NUMERIC);
        $weights = array_map('floatval', array_keys($schedule));
        $weight = max($weights[0], min($weight, $weights[array_key_last($weights)]));

        foreach ($weights as $index => $upperWeight) {
            if ($weight > $upperWeight) {
                continue;
            }

            if ($index === 0 || $weight === $upperWeight) {
                return (float) $schedule[$upperWeight];
            }

            $lowerWeight = $weights[$index - 1];
            $lowerDose = (float) $schedule[$lowerWeight];
            $upperDose = (float) $schedule[$upperWeight];
            $progress = ($weight - $lowerWeight) / ($upperWeight - $lowerWeight);

            return $lowerDose + (($upperDose - $lowerDose) * $progress);
        }

        return (float) end($schedule);
    }
}
