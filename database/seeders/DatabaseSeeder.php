<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            BusinessSettingsSeeder::class,
            LaundryServiceCategorySeeder::class,
            LaundryServiceSeeder::class,
            InventorySeeder::class,
            ServiceInventoryUsageSeeder::class,
            BranchSettingSeeder::class,
            SmsTemplateSeeder::class,
            // Last: it bills the branches the seeders above created.
            SubscriptionBillingSeeder::class,
        ]);
    }
}
