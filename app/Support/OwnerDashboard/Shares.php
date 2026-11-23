<?php

namespace App\Support\OwnerDashboard;

final class Shares
{
    /**
     * Whole-number percentages that add to 100 (all zeros when the total is 0).
     * The largest part takes the rounding remainder.
     *
     * @param  array<array-key, int|float>  $amounts
     * @return array<array-key, int>
     */
    public static function percentages(array $amounts): array
    {
        $total = array_sum($amounts);
        if ($total <= 0) {
            return array_map(fn () => 0, $amounts);
        }

        $pct = array_map(fn ($v) => (int) round($v / $total * 100), $amounts);
        $largest = array_search(max($amounts), $amounts);
        $pct[$largest] = 100 - (array_sum($pct) - $pct[$largest]);

        return $pct;
    }
}
