<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')
            ->update(['business_email' => 'canencottonlaundry@gmail.com']);

        foreach (DB::table('laundry_services')->where('name', 'Downy')->get() as $legacy) {
            $alreadyNamed = DB::table('laundry_services')
                ->where('branch_id', $legacy->branch_id)
                ->where('name', 'Downy Mystique')
                ->whereNull('deleted_at')
                ->exists();

            DB::table('laundry_services')->where('id', $legacy->id)->update(
                $alreadyNamed
                    ? ['show_on_landing' => false, 'updated_at' => now()]
                    : [
                        'name' => 'Downy Mystique',
                        'price_unit_label' => 'per load',
                        'updated_at' => now(),
                    ]
            );
        }

        DB::table('laundry_services')
            ->whereIn('name', ['Ariel Detergent', 'Tide Detergent', 'Downy Mystique', 'Downy Sunrise'])
            ->update(['price_unit_label' => 'per load']);

        foreach (DB::table('laundry_services')->where('name', 'Downy Mystique')->whereNull('deleted_at')->get() as $mystique) {
            $sunrise = DB::table('laundry_services')
                ->where('branch_id', $mystique->branch_id)
                ->where('name', 'Downy Sunrise')
                ->whereNull('deleted_at')
                ->first();

            if ($sunrise) {
                continue;
            }

            $sunriseId = DB::table('laundry_services')->insertGetId([
                'branch_id' => $mystique->branch_id,
                'service_category_id' => $mystique->service_category_id,
                'name' => 'Downy Sunrise',
                'report_category' => 'fabcon',
                'pricing_type' => 'custom',
                'price' => 10,
                'price_unit_label' => 'per load',
                'is_active' => $mystique->is_active,
                'show_on_landing' => $mystique->show_on_landing,
                'landing_blurb' => 'Optional fabric conditioner for your load.',
                'landing_icon' => $mystique->landing_icon ?: 'sparkles',
                'landing_sort_order' => $mystique->landing_sort_order + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (DB::table('service_inventory_usages')->where('laundry_service_id', $mystique->id)->get() as $usage) {
                DB::table('service_inventory_usages')->insert([
                    'laundry_service_id' => $sunriseId,
                    'inventory_id' => $usage->inventory_id,
                    'quantity' => $usage->quantity,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Existing bookings and inventory usage may already refer to these services.
    }
};
