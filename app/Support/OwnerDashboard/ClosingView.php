<?php

namespace App\Support\OwnerDashboard;

use App\Models\ZReading;
use App\Support\MachineClass;

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
                $status = $row['reconciliation_status'];
                $machines[] = $machine = [
                    'machine_no' => (int) $no,
                    'type' => $type,
                    'label' => MachineClass::forMachine($reading->branch, (int) $no) === MachineClass::TITAN ? 'Titan '.strtolower($word)." #{$no}" : "{$word} #{$no}",
                    'booked' => (int) ($row['system_cycles'] ?? 0),
                    'counted' => (int) ($row['total'] ?? 0),
                    'difference' => $difference,
                    // A failed check can have no unexplained cycles (off, end_low), so the page reads this, not the difference.
                    'ok' => ! in_array($status, self::FAILED_MACHINE, true),
                ];
                if (in_array($status, self::FAILED_MACHINE, true)) {
                    // For 'off' the counter ran past the bookings; reasons can over- or fully cover that.
                    $more = $status === 'off' ? $machine['counted'] - $machine['booked'] > 0 : $difference > 0;
                    $failed[] = match (true) {
                        $status === 'end_low' => "{$word} #{$no}: counter reads lower than at opening",
                        $status === 'off' && $difference === 0 => "{$word} #{$no}: extra cycles need a job order # or note",
                        default => "{$word} #{$no}: ".abs($difference).(abs($difference) === 1 ? ' cycle ' : ' cycles ').($more ? 'more' : 'fewer').' than loads booked',
                    };
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
