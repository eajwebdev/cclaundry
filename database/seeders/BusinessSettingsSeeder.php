<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\SystemSetting;
use App\Support\BusinessDefaults;
use Illuminate\Database\Seeder;

class BusinessSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = SystemSetting::current();
        $settings->fill([
            'business_name' => 'Cane & Cotton Laundry',
            'business_address' => BusinessDefaults::ADDRESS,
            'contact_number' => BusinessDefaults::CONTACT_NUMBER,
            'business_email' => BusinessDefaults::EMAIL,
            'facebook_url' => BusinessDefaults::FACEBOOK_URL,
        ]);

        // Existing installations may have an unfinished settings record. Fill
        // only missing operational defaults, then use the same completion check
        // as the settings form instead of forcing the flag to true.
        foreach (['currency' => 'PHP', 'job_order_prefix' => 'JO', 'invoice_prefix' => 'INV'] as $field => $default) {
            if (blank($settings->{$field})) {
                $settings->{$field} = $default;
            }
        }

        $settings->is_completed = $settings->isComplete();
        $settings->save();

        $mainBranch = Branch::query()->firstOrCreate(
            ['code' => 'MAIN'],
            ['name' => 'Main Branch', 'is_active' => true]
        );

        $mainBranch->forceFill([
            'address' => BusinessDefaults::ADDRESS,
            'contact_number' => BusinessDefaults::CONTACT_NUMBER,
            'machine_count' => BusinessDefaults::MACHINE_COUNT,
        ])->save();
    }
}
