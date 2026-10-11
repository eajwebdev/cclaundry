<?php

namespace App\Support;

use App\Models\Branch;

/**
 * Washer sizes from the Machine Load Guide. Every machine is a Giant unless
 * the branch marks its number as a Titan, so branches saved before this
 * setting existed keep working without any data change.
 */
class MachineClass
{
    public const GIANT = 'giant';

    public const TITAN = 'titan';

    /** @return array<string, array{label: string, rated_kg: int}> */
    public static function definitions(): array
    {
        return [
            self::GIANT => ['label' => 'Giant', 'rated_kg' => 10],
            self::TITAN => ['label' => 'Titan', 'rated_kg' => 15],
        ];
    }

    public static function label(string $class): string
    {
        return self::definitions()[$class]['label'] ?? ucfirst($class);
    }

    /** @return list<int> */
    public static function titanNumbers(?Branch $branch): array
    {
        $machineCount = (int) ($branch?->machine_count ?? 0);

        return self::normalizeNumbers($branch?->titan_machine_numbers ?? [], $machineCount);
    }

    public static function forMachine(?Branch $branch, int $machine): string
    {
        return in_array($machine, self::titanNumbers($branch), true) ? self::TITAN : self::GIANT;
    }

    /**
     * Keep only whole machine numbers that exist at the branch, sorted, once each.
     *
     * @return list<int>
     */
    public static function normalizeNumbers(mixed $numbers, int $machineCount): array
    {
        return collect(is_array($numbers) ? $numbers : [])
            ->filter(fn ($number) => is_numeric($number))
            ->map(fn ($number) => (int) $number)
            ->filter(fn (int $number) => $number >= 1 && $number <= $machineCount)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
