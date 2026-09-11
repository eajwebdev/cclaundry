<?php

namespace Database\Seeders;

use App\Models\LaundryServiceCategory;
use Illuminate\Database\Seeder;

class LaundryServiceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Full Service',   'visibility' => 'all', 'sort_order' => 1],
            ['name' => 'Extra Services', 'visibility' => 'all', 'sort_order' => 2],
            ['name' => 'Add-ons',        'visibility' => 'all', 'sort_order' => 3],
        ];

        foreach ($categories as $category) {
            LaundryServiceCategory::updateOrCreate(
                ['name' => $category['name']],
                ['visibility' => $category['visibility'], 'sort_order' => $category['sort_order'], 'is_active' => true]
            );
        }

        // Retire the previous default categories rather than deleting them, so
        // old job orders keep their category. Active categories become report
        // columns, and these would otherwise sit there empty.
        LaundryServiceCategory::query()
            ->whereIn('name', ['Small Machine', 'Big Machine', 'Delivery', 'Special Items', 'Establishment', 'For Sale Items'])
            ->update(['is_active' => false]);
    }
}
