<?php

namespace App\Support\OwnerDashboard;

use App\Models\ZReading;

/** How one end-of-day closing (Z-Reading) is shown to the owner. Reads only; never changes the stored status. */
final class ClosingView
{
    private const FAILED_MACHINE = ['off', 'end_low', 'counter_low'];

    /** Load `branch`, `closer` and `preparer` beforehand to avoid a query per closing. */
    public static function from(ZReading $reading): array
    {
        $cash = self::money($reading->cash_diff);
        $gcash = self::money($reading->gcash_diff);
        $deposit = self::money($reading->deposit_diff);
        $failed = [];
        if (self::off($cash)) {
            $failed[] = 'Cash '.self::peso($cash).($cash < 0 ? ' short — recount, check expenses' : ' over — recount, check payments');
        }
        if (self::off($gcash)) {
            $failed[] = 'GCash '.self::peso($gcash).($gcash < 0 ? ' short' : ' over').' — check the app balance';
        }
        if (self::off($deposit)) {
            $failed[] = 'Bank deposit '.self::peso($deposit).($deposit < 0 ? ' short' : ' over').' — check the deposit slip';
        }

        $titan = array_map('intval', $reading->branch?->titan_machine_numbers ?: [5]);
        $counters = $reading->machine_counters ?? [];
        ksort($counters, SORT_NUMERIC);
        $machines = [];
        foreach ($counters as $no => $types) {
            foreach (['wash', 'dry'] as $type) {
                $row = $types[$type] ?? null;
                // Rows from before machine reconciliation carry no status; nothing to compare there.
                if (! is_array($row) || ($row['reconciliation_status'] ?? 'empty') === 'empty') {
                    continue;
                }
                $word = $type === 'wash' ? 'Washer' : 'Dryer';
                $difference = (int) ($row['unexplained_cycles'] ?? 0);
                $machines[] = [
                    'machine_no' => (int) $no,
                    'type' => $type,
                    'label' => in_array((int) $no, $titan, true) ? 'Titan '.strtolower($word)." #{$no}" : "{$word} #{$no}",
                    'booked' => (int) ($row['system_cycles'] ?? 0),
                    'counted' => (int) ($row['total'] ?? 0),
                    'difference' => $difference,
                ];
                if (in_array($row['reconciliation_status'], self::FAILED_MACHINE, true)) {
                    // 'off' can have 0 unexplained when the reasons add up but a JO # or note is missing.
                    $k = $difference ?: (int) ($row['total'] ?? 0) - (int) ($row['system_cycles'] ?? 0);
                    $failed[] = $row['reconciliation_status'] === 'end_low'
                        ? "{$word} #{$no}: counter reads lower than at opening"
                        : "{$word} #{$no}: ".abs($k).(abs($k) === 1 ? ' cycle ' : ' cycles ').($k > 0 ? 'more' : 'fewer').' than loads booked';
                }
            }
        }

        return [
            'date' => $reading->business_date->format('Y-m-d'),
            'branch' => $reading->branch?->name,
            'closed_by' => $reading->closer?->name ?? $reading->preparer?->name,
            'status' => $failed ? 'difference' : 'balanced',
            'shop_cash' => self::pair($reading->expected_cash_drawer_amount, $reading->actual_cash_amount, $cash),
            'gcash' => self::pair($reading->expected_gcash_amount, $reading->actual_gcash_amount, $gcash),
            'envelope_amount' => self::money($reading->deposit_bag_amount),
            'shop_cash_left' => self::money($reading->float_for_tomorrow),
            'gcash_sent_to_bank' => self::money($reading->gcash_transfer_amount),
            'machines' => $machines,
            'failed_checks' => $failed,
            'what_to_check' => implode(' · ', $failed),
        ];
    }

    /** The compact row for the "all closings" list. */
    public static function row(array $view): array
    {
        return array_intersect_key($view, array_flip(['date', 'branch', 'closed_by', 'status', 'what_to_check']));
    }

    private static function pair(mixed $expected, mixed $counted, float $difference): array
    {
        return ['expected' => self::money($expected), 'counted' => self::money($counted), 'difference' => $difference];
    }

    private static function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    /** Same tolerance as ZReadingController. */
    private static function off(float $diff): bool
    {
        return abs($diff) >= 0.005;
    }

    private static function peso(float $diff): string
    {
        return '₱'.number_format(abs($diff));
    }
}
