<?php

namespace App\Support;

/**
 * The stages a run passes through, as a rider meets them on the road.
 *
 * One list so the filter chips, the pin colours and the card all agree, and
 * so renaming a stage is a single edit rather than a hunt through templates.
 */
class RiderMapStages
{
    public const DELIVERY = 'delivery';

    public const PICKUP = 'pickup';

    public const AVAILABLE = 'available';

    public const IN_CYCLE = 'in_cycle';

    /**
     * Ordered by how much they want the rider's attention: the two that can be
     * acted on now lead, then what is free to take, then what is still washing.
     *
     * @return array<string, array{label: string, color: string}>
     */
    public static function all(): array
    {
        return [
            self::DELIVERY => ['label' => 'Ready for delivery', 'color' => '#ea580c'],
            self::PICKUP => ['label' => 'Ready for pickup', 'color' => '#A07148'],
            self::AVAILABLE => ['label' => 'Up for grabs', 'color' => '#0284c7'],
            self::IN_CYCLE => ['label' => 'In the wash', 'color' => '#94a3b8'],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
