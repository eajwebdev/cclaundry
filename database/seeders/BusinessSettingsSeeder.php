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
        SystemSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'business_name' => 'Cane & Cotton Laundry',
                'business_address' => BusinessDefaults::ADDRESS,
                'contact_number' => BusinessDefaults::CONTACT_NUMBER,
                'business_email' => BusinessDefaults::EMAIL,
                'facebook_url' => BusinessDefaults::FACEBOOK_URL,
            ]
        );

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
