<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // decimal(10,7) holds a WGS84 coordinate to ~1cm, which is far past what
        // a phone GPS resolves, and avoids the drift float columns introduce
        // when a position is written and read back repeatedly.
        Schema::table('pickup_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('pickup_requests', 'pickup_latitude')) {
                $table->decimal('pickup_latitude', 10, 7)->nullable()->after('pickup_address');
                $table->decimal('pickup_longitude', 10, 7)->nullable()->after('pickup_latitude');
            }

            if (! Schema::hasColumn('pickup_requests', 'delivery_latitude')) {
                $table->decimal('delivery_latitude', 10, 7)->nullable()->after('delivery_address');
                $table->decimal('delivery_longitude', 10, 7)->nullable()->after('delivery_latitude');
            }

            if (! Schema::hasColumn('pickup_requests', 'rider_id')) {
                $table->foreignId('rider_id')->nullable()->after('handled_by')
                    ->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->nullable()->after('rider_id');
                $table->timestamp('picked_up_at')->nullable()->after('assigned_at');
                $table->timestamp('delivered_at')->nullable()->after('picked_up_at');
            }
        });

        Schema::table('pickup_requests', function (Blueprint $table) {
            // The rider console's main query: "my open jobs, soonest first".
            $table->index(['rider_id', 'status'], 'pickup_requests_rider_status_index');
        });

        // A customer's saved pin, so a repeat booking opens on their own house
        // instead of the city centre.
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('address');
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
        });

        // Last known position denormalised onto the rider row. Every watching
        // map reads this, so it must be a single indexed row read rather than
        // a "latest ping per rider" group-by against the history table.
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'last_latitude')) {
                $table->decimal('last_latitude', 10, 7)->nullable()->after('face_enrolled_at');
                $table->decimal('last_longitude', 10, 7)->nullable()->after('last_latitude');
                $table->unsignedSmallInteger('last_location_accuracy')->nullable()->after('last_longitude');
                $table->unsignedSmallInteger('last_location_heading')->nullable()->after('last_location_accuracy');
                $table->timestamp('last_location_at')->nullable()->after('last_location_heading');
                $table->boolean('is_sharing_location')->default(false)->after('last_location_at');
            }
        });

        // Breadcrumb history: proof of service, and the trail drawn behind a
        // moving rider. Pruned on a schedule, never read in bulk.
        if (! Schema::hasTable('rider_location_pings')) {
            Schema::create('rider_location_pings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rider_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('pickup_request_id')->nullable()
                    ->constrained('pickup_requests')->nullOnDelete();
                $table->decimal('latitude', 10, 7);
                $table->decimal('longitude', 10, 7);
                $table->unsignedSmallInteger('accuracy')->nullable();
                $table->unsignedSmallInteger('heading')->nullable();
                $table->decimal('speed', 6, 2)->nullable();
                $table->timestamp('recorded_at');
                $table->timestamps();

                $table->index(['rider_id', 'recorded_at']);
                $table->index(['pickup_request_id', 'recorded_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_location_pings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'last_latitude', 'last_longitude', 'last_location_accuracy',
                'last_location_heading', 'last_location_at', 'is_sharing_location',
            ]);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });

        Schema::table('pickup_requests', function (Blueprint $table) {
            $table->dropIndex('pickup_requests_rider_status_index');
            $table->dropConstrainedForeignId('rider_id');
            $table->dropColumn([
                'pickup_latitude', 'pickup_longitude',
                'delivery_latitude', 'delivery_longitude',
                'assigned_at', 'picked_up_at', 'delivered_at',
            ]);
        });
    }
};
