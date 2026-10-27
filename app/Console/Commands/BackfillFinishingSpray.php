<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Support\FinishingSprayConsumption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BackfillFinishingSpray extends Command
{
    protected $signature = 'inventory:backfill-finishing-spray {--from=2026-09-26 : First order date, inclusive (YYYY-MM-DD)} {--apply : Write stock and movement adjustments}';

    protected $description = 'Preview or apply finishing spray consumption at 3.5 mL/kg for existing orders';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        if (! $date || $date->format('Y-m-d') !== $from) {
            $this->error('Use a valid --from date in YYYY-MM-DD format.');

            return self::FAILURE;
        }

        try {
            $rows = DB::transaction(function () use ($from) {
                $rows = [];
                // Include archived services so later renames/deletions do not hide order lines.
                $services = LaundryService::withTrashed()->with('serviceCategory')->get()->keyBy('id');
                $orders = JobOrder::query()->where('created_at', '>=', $from.' 00:00:00')
                    ->whereNotNull('inventory_deducted_at')->where('status', '!=', 'cancelled')
                    ->with('items')->orderBy('id')->lockForUpdate()->get();

                foreach ($orders as $order) {
                    $items = $order->items->map->only(['laundry_service_id', 'quantity'])->all();
                    $sprayItems = $order->items->filter(fn ($item) => $item->service_category === 'finishing_spray'
                        || FinishingSprayConsumption::isSpray($services->get($item->laundry_service_id)));
                    if ($sprayItems->isEmpty()) {
                        continue;
                    }
                    $kilos = FinishingSprayConsumption::kilos($items, $services);
                    $stock = Inventory::query()->where('branch_id', $order->processing_branch_id ?: $order->branch_id)
                        ->where(fn ($query) => $query->where('sku', 'SUP-FINISHING-SPRAY')->orWhere('name', 'Finishing Spray'))
                        ->lockForUpdate()->first();
                    if (! $stock) {
                        throw ValidationException::withMessages(['stock' => "Missing finishing spray stock for {$order->job_order_number}."]);
                    }
                    $expected = FinishingSprayConsumption::quantity($stock, $kilos);
                    $outRemark = "Auto deducted for {$order->job_order_number}";
                    $inRemark = "Auto restored for {$order->job_order_number}";
                    $movements = InventoryMovement::query()->where('inventory_id', $stock->id)
                        ->whereIn('remarks', [$outRemark, $inRemark])->get();
                    $net = (float) $movements->where('movement_type', 'out')->sum('quantity')
                        - (float) $movements->where('movement_type', 'in')->sum('quantity');
                    $delta = round($expected - $net, 4);
                    if ($delta === 0.0) {
                        continue;
                    }
                    $rows[] = [$order->job_order_number, $stock->branch_id, $kilos, $net, $expected, $delta, $stock->unit];
                    if ($this->option('apply')) {
                        if ($delta > 0) {
                            $adjustment = new InventoryMovement([
                                'movement_type' => 'out', 'quantity' => $delta, 'remarks' => $outRemark,
                            ]);
                            // Keep consumption in its original reporting period.
                            $adjustment->forceFill(['created_at' => $order->inventory_deducted_at]);
                            $stock->movements()->save($adjustment);
                        } else {
                            // Usage reports sum outgoing movements. Correct an excessive
                            // deduction itself so those reports also show the lower usage.
                            $remaining = abs($delta);
                            foreach ($movements->where('movement_type', 'out')->sortByDesc('id') as $movement) {
                                $reduction = min($remaining, (float) $movement->quantity);
                                $movement->update(['quantity' => round((float) $movement->quantity - $reduction, 4)]);
                                $remaining = round($remaining - $reduction, 4);
                                if ($remaining <= 0) {
                                    break;
                                }
                            }
                        }
                        // Historical corrections may expose a stock deficit; keep the true balance.
                        $stock->update(['quantity' => round((float) $stock->quantity - $delta, 4)]);
                    }
                }

                return $rows;
            });
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));
            $this->error('No adjustments were saved.');

            return self::FAILURE;
        }

        $this->table(['Order', 'Stock branch', 'kg', 'Previous', 'Corrected', 'Extra consumed', 'Unit'], $rows);
        $this->info($this->option('apply') ? count($rows).' order(s) adjusted.' : 'Preview only. Add --apply to save these adjustments.');

        return self::SUCCESS;
    }
}
