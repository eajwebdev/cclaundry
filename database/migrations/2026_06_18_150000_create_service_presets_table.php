<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('service_category_id')->nullable()->constrained('laundry_service_categories')->nullOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            // A preset bundles several services into one thing a customer can
            // book. Pinned presets appear on the public landing page alongside
            // pinned individual services.
            $table->boolean('show_on_landing')->default(false)->index();
            $table->string('landing_blurb')->nullable();
            $table->string('landing_icon', 40)->nullable();
            $table->unsignedInteger('landing_sort_order')->default(0);

            $table->timestamps();
        });

        Schema::create('service_preset_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_preset_id')->constrained('service_presets')->cascadeOnDelete();
            $table->foreignId('laundry_service_id')->constrained('laundry_services')->cascadeOnDelete();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->timestamps();

            $table->unique(['service_preset_id', 'laundry_service_id']);
        });

        // A public booking can reference a preset instead of a single service.
        if (Schema::hasTable('pickup_requests') && ! Schema::hasColumn('pickup_requests', 'service_preset_id')) {
            Schema::table('pickup_requests', function (Blueprint $table) {
                $table->foreignId('service_preset_id')
                    ->nullable()
                    ->after('laundry_service_id')
                    ->constrained('service_presets')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pickup_requests') && Schema::hasColumn('pickup_requests', 'service_preset_id')) {
            Schema::table('pickup_requests', function (Blueprint $table) {
                $table->dropConstrainedForeignId('service_preset_id');
            });
        }

        Schema::dropIfExists('service_preset_items');
        Schema::dropIfExists('service_presets');
    }
};
