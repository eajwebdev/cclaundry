<?php

namespace Tests\Feature;

use Tests\TestCase;

class MaintenancePageTest extends TestCase
{
    /** `php artisan down --render="maintenance"` renders the view once with no data, session or user. */
    public function test_it_renders_with_no_data_and_needs_no_built_assets(): void
    {
        $html = view('maintenance')->render();

        $this->assertStringContainsString('System Under Maintenance', $html);
        $this->assertStringContainsString('Please check back shortly.', $html);
        $this->assertStringNotContainsString('Logout', $html);
        $this->assertStringNotContainsString('/build/', $html);
    }

    public function test_it_shows_the_message_and_start_time_when_given(): void
    {
        $html = view('maintenance', ['message' => 'Back at 3 PM.', 'since' => now()->setDate(2026, 11, 23)->setTime(13, 5)])->render();

        $this->assertStringContainsString('Back at 3 PM.', $html);
        $this->assertStringContainsString('In maintenance since Nov 23, 2026 1:05 PM.', $html);
    }
}
