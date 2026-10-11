<?php

namespace App\Support;

use App\Models\LaundryService;

/**
 * Cane & Cotton's Liquid, Bedding and Machine Load guides, in milliliters.
 *
 * Rule of thumb from the guide: 25 mL Mrs. Bloom detergent + 15 mL Yen Yen
 * softener for 5 kg, then +5 mL of each every 2 kilos. Yen Yen is always
 * 10 mL less than detergent. Loads under 5 kg still get the 5 kg dose, and a
 * load over the machine limit is split into equal loads.
 *
 * The guide is configuration only: choosing a profile never changes stock by
 * itself. Inventory is consumed only when a job order is created/accepted by
 * production, through InventoryConsumption, and restoring stock always uses
 * the recorded movements, so past orders never change when this guide does.
 */
class LaundryDosingGuide
{
    public const REGULAR = 'regular_liquid';

    public const TOWELS = 'towels_microfibre';

    public const VERY_DIRTY = 'very_dirty';

    public const BED_SHEETS = 'bed_sheets';

    public const SINGLE_TWIN_COMFORTER = 'single_twin_comforter';

    public const DOUBLE_QUEEN_COMFORTER = 'double_queen_comforter';

    public const KING_COMFORTER = 'king_comforter';

    public const DUVET_INSERT = 'duvet_insert';

    public const DUVET_INSERT_DOWN = 'duvet_insert_down';

    public const DUVET_COVER = 'duvet_cover';

    public const DETERGENT = 'detergent';

    public const FABRIC_SOFTENER = 'fabric_softener';

    public const MINIMUM_DOSE_KILOS = 5;

    public const VERY_DIRTY_EXTRA_KILOS = 3;

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::REGULAR => 'Liquid guide — clothes, sheets, uniforms (Giant 5–10 kg · Titan 11–15 kg)',
            self::TOWELS => 'Towels & microfibre — no softener (Giant 5–8 kg · Titan 9–12 kg)',
            self::VERY_DIRTY => 'Very dirty / heavy-duty — dose for +3 kg (sportswear, grease, mud)',
            self::BED_SHEETS => 'Bedding — bed sheet set (1.5–2 kg each · 5 sets · Giant max 10 kg)',
            self::SINGLE_TWIN_COMFORTER => 'Bedding — Single/Twin comforter (1.5–2.5 kg each · 3 pcs · Giant max 8 kg)',
            self::DOUBLE_QUEEN_COMFORTER => 'Bedding — Double/Queen comforter (2.5–3.5 kg each · 2 pcs · Giant max 8 kg)',
            self::KING_COMFORTER => 'Bedding — King comforter (3–4.5 kg each · 2 pcs · Titan only, max 12 kg)',
            self::DUVET_INSERT => 'Bedding — duvet insert (1.5–4 kg each · 3 pcs · Titan, max 12 kg)',
            self::DUVET_INSERT_DOWN => 'Bedding — feather/down or microfibre duvet, no softener (Titan, max 12 kg)',
            self::DUVET_COVER => 'Bedding — duvet cover (0.7–1.5 kg each · 6 pcs · Giant max 10 kg)',
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

    /**
     * giant_max: heaviest load a Giant takes; heavier loads go to the Titan.
     * max: heaviest single load (Titan limit); heavier lines are split.
     * piece_kg / max_pieces: bedding item weight (upper, dry) and pieces per load.
     *
     * @return array{giant_max: float, max: float, titan_only: bool, softener: bool, extra_kg: float, piece_kg: ?float, max_pieces: ?int}|null
     */
    public static function profile(?string $profile): ?array
    {
        $definition = fn (float $giantMax, float $max, bool $titanOnly = false, bool $softener = true, float $extraKg = 0, ?float $pieceKg = null, ?int $maxPieces = null) => [
            'giant_max' => $giantMax,
            'max' => $max,
            'titan_only' => $titanOnly,
            'softener' => $softener,
            'extra_kg' => $extraKg,
            'piece_kg' => $pieceKg,
            'max_pieces' => $maxPieces,
        ];

        return match ($profile) {
            self::REGULAR => $definition(10, 15),
            self::TOWELS => $definition(8, 12, softener: false),
            self::VERY_DIRTY => $definition(10, 15, extraKg: self::VERY_DIRTY_EXTRA_KILOS),
            self::BED_SHEETS => $definition(10, 12, pieceKg: 2, maxPieces: 5),
            self::SINGLE_TWIN_COMFORTER => $definition(8, 12, pieceKg: 2.5, maxPieces: 3),
            self::DOUBLE_QUEEN_COMFORTER => $definition(8, 12, pieceKg: 3.5, maxPieces: 2),
            self::KING_COMFORTER => $definition(0, 12, titanOnly: true, pieceKg: 4.5, maxPieces: 2),
            self::DUVET_INSERT => $definition(0, 12, titanOnly: true, pieceKg: 4, maxPieces: 3),
            self::DUVET_INSERT_DOWN => $definition(0, 12, titanOnly: true, softener: false, pieceKg: 4, maxPieces: 3),
            self::DUVET_COVER => $definition(10, 12, pieceKg: 1.5, maxPieces: 6),
            default => null,
        };
    }

    /** Mrs. Bloom detergent for one load: 25 mL at 5 kg, +5 mL every 2 kilos. */
    public static function detergentForWeight(float $kilos): float
    {
        $wholeKilos = max(self::MINIMUM_DOSE_KILOS, (int) ceil(round($kilos, 4)));

        return (float) (25 + (5 * intdiv($wholeKilos - 4, 2)));
    }

    /** Yen Yen softener for one load: always 10 mL less than detergent. */
    public static function softenerForWeight(float $kilos): float
    {
        return self::detergentForWeight($kilos) - 10;
    }

    /**
     * Split one order line into machine loads with the dose for each.
     *
     * @return list<array{kilos: float, machine: string, detergent_ml: float, softener_ml: float}>
     */
    public static function loads(string $profile, float $quantity, string $pricingType, ?float $kilosPerLoad = null): array
    {
        $definition = self::profile($profile);
        if (! $definition || $quantity <= 0) {
            return [];
        }

        if ($pricingType === 'kilo') {
            $count = max(1, (int) ceil(round($quantity / $definition['max'], 6)));
            $loadKilos = $quantity / $count;
        } elseif ($pricingType === 'piece' && $definition['piece_kg']) {
            // Bedding sold per piece: estimate the weight from the guide and
            // respect both the weight limit and the pieces-per-load limit.
            $weight = $quantity * $definition['piece_kg'];
            $count = max(
                1,
                (int) ceil(round($weight / $definition['max'], 6)),
                (int) ceil(round($quantity / $definition['max_pieces'], 6))
            );
            $loadKilos = $weight / $count;
        } else {
            // Load/custom quantities already count machine loads at the POS.
            $count = max(1, (int) ceil(round($quantity, 6)));
            $loadKilos = min($definition['max'], $kilosPerLoad && $kilosPerLoad > 0 ? $kilosPerLoad : $definition['max']);
        }

        $doseKilos = $loadKilos + $definition['extra_kg'];
        $load = [
            'kilos' => round($loadKilos, 2),
            'machine' => self::machineFor($profile, $loadKilos),
            'detergent_ml' => self::detergentForWeight($doseKilos),
            'softener_ml' => $definition['softener'] ? self::softenerForWeight($doseKilos) : 0.0,
        ];

        return array_fill(0, $count, $load);
    }

    /** Kilo lines are dosed at no less than the service's minimum kilos. */
    public static function doseQuantity(LaundryService $service, float $quantity): float
    {
        return $service->pricing_type === 'kilo'
            ? max($quantity, (float) ($service->minimum_kilos ?? 0))
            : $quantity;
    }

    /**
     * The machine loads and doses for one job-order line, or [] when the
     * service has no dosing profile.
     *
     * @return list<array{kilos: float, machine: string, detergent_ml: float, softener_ml: float}>
     */
    public static function loadsForService(LaundryService $service, float $quantity): array
    {
        $profile = self::profileFor($service);

        if (! $profile) {
            return [];
        }

        return self::loads(
            $profile,
            self::doseQuantity($service, $quantity),
            (string) $service->pricing_type,
            (float) $service->kilos_per_load ?: null
        );
    }

    public static function machineFor(string $profile, float $loadKilos): string
    {
        $definition = self::profile($profile);

        if (! $definition || $definition['titan_only'] || round($loadKilos, 4) > $definition['giant_max']) {
            return MachineClass::TITAN;
        }

        return MachineClass::GIANT;
    }

    public static function milliliters(string $profile, string $chemical, float $quantity, string $pricingType, ?float $kilosPerLoad = null): float
    {
        $key = match ($chemical) {
            self::DETERGENT => 'detergent_ml',
            self::FABRIC_SOFTENER => 'softener_ml',
            default => null,
        };

        if (! $key) {
            return 0.0;
        }

        return round(array_sum(array_column(self::loads($profile, $quantity, $pricingType, $kilosPerLoad), $key)), 4);
    }

    public static function loadCount(string $profile, float $quantity, string $pricingType, ?float $kilosPerLoad = null): int
    {
        return count(self::loads($profile, $quantity, $pricingType, $kilosPerLoad));
    }
}
