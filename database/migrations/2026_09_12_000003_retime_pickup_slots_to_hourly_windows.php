<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The three broad half-day windows became five one-hour ones. Bookings already
 * on the books keep a real window rather than rendering their old raw key:
 * each legacy slot lands on the new window closest to when the van used to go.
 */
return new class extends Migration
{
    private const FORWARD = [
        'morning' => '08_09',
        'afternoon' => '13_14',
        'evening' => '13_14',
    ];

    private const BACK = [
        '08_09' => 'morning',
        '09_10' => 'morning',
        '10_11' => 'morning',
        '11_12' => 'morning',
        '13_14' => 'afternoon',
    ];

    public function up(): void
    {
        $this->remap(self::FORWARD);

        Schema::table('pickup_requests', function ($table) {
            $table->string('pickup_slot')->default('08_09')->change();
        });
    }

    public function down(): void
    {
        Schema::table('pickup_requests', function ($table) {
            $table->string('pickup_slot')->default('morning')->change();
        });

        $this->remap(self::BACK);
    }

    private function remap(array $map): void
    {
        foreach ($map as $from => $to) {
            DB::table('pickup_requests')->where('pickup_slot', $from)->update(['pickup_slot' => $to]);
            DB::table('pickup_requests')->where('delivery_slot', $from)->update(['delivery_slot' => $to]);
        }
    }
};
