<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A booking is a bag, and a bag holds more than one kind of washing. Someone
 * with a regular load and a comforter had to book twice, because a pickup
 * request could name exactly one service.
 *
 * Each line snapshots the service as it was priced on the day, the amount the
 * customer entered and what that number means (kilos for the weighed services,
 * pairs for steaming, plain counts for the detergent and fabcon add-ons), so
 * the counter can hold the booking against the scale when the bag arrives.
 *
 * The single-service columns on pickup_requests stay, filled from the first
 * washing line: the rider screen, tracking and the sales reports all read them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pickup_request_items')) {
            Schema::create('pickup_request_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pickup_request_id')->constrained()->cascadeOnDelete();
                $table->foreignId('laundry_service_id')->nullable()->constrained('laundry_services')->nullOnDelete();
                $table->foreignId('service_preset_id')->nullable()->constrained('service_presets')->nullOnDelete();

                $table->string('service_name');
                $table->decimal('service_price', 12, 2)->default(0);
                $table->string('pricing_type', 20)->nullable();

                // What the customer typed, and what that number means to them.
                $table->decimal('quantity', 8, 2)->default(1);
                $table->string('unit', 12)->default('qty');

                // The same amount in the units the service is priced in: kilos
                // stay kilos, but 12 kg of load-priced linens is 2 loads.
                $table->decimal('billable_quantity', 8, 2)->default(1);
                $table->decimal('line_total', 12, 2)->default(0);

                // Detergent and fabric conditioner ride along with a wash; they
                // are never the reason for the booking.
                $table->boolean('is_addon')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);

                $table->timestamps();

                $table->index(['pickup_request_id', 'is_addon']);
            });
        }

        $this->backfillExistingBookings();
    }

    /**
     * Bookings placed before this table existed each named one service in their
     * own columns. Copied across so every booking has lines to show, rather
     * than older ones rendering an empty service list.
     */
    private function backfillExistingBookings(): void
    {
        $bookings = DB::table('pickup_requests')
            ->leftJoin('pickup_request_items', 'pickup_request_items.pickup_request_id', '=', 'pickup_requests.id')
            ->whereNull('pickup_request_items.id')
            ->whereNotNull('pickup_requests.service_name')
            ->select([
                'pickup_requests.id',
                'pickup_requests.laundry_service_id',
                'pickup_requests.service_preset_id',
                'pickup_requests.service_name',
                'pickup_requests.service_price',
                'pickup_requests.service_pricing_type',
                'pickup_requests.estimated_kilos',
                'pickup_requests.estimated_total',
            ])
            ->get();

        $now = now();

        foreach ($bookings as $booking) {
            $pricingType = $booking->service_pricing_type;
            $unit = in_array($pricingType, ['kilo', 'load'], true) ? 'kg'
                : ($pricingType === 'piece' ? 'pc' : 'qty');

            // Only the weighed services ever recorded a weight; everything else
            // was quoted as a single unit.
            $quantity = $unit === 'kg' && $booking->estimated_kilos
                ? (float) $booking->estimated_kilos
                : 1.0;

            $billable = $pricingType === 'load'
                ? max(1.0, ceil($quantity / 7))
                : $quantity;

            DB::table('pickup_request_items')->insert([
                'pickup_request_id' => $booking->id,
                'laundry_service_id' => $booking->laundry_service_id,
                'service_preset_id' => $booking->service_preset_id,
                'service_name' => $booking->service_name,
                'service_price' => (float) ($booking->service_price ?? 0),
                'pricing_type' => $pricingType,
                'quantity' => $quantity,
                'unit' => $unit,
                'billable_quantity' => $billable,
                // The stored estimate includes any rush surcharge, so the line
                // is priced from the service itself rather than from the total.
                'line_total' => round((float) ($booking->service_price ?? 0) * $billable, 2),
                'is_addon' => false,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_request_items');
    }
};
