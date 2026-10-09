<?php

namespace Tests\Feature\Tickets;

use App\Models\TicketSection;
use Database\Seeders\TicketSectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TicketSectionSeeder holds every area the dashboard's "Report a problem" can
 * send a ticket to. Re-running it refreshes wording and order only.
 */
class TicketSectionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_every_area_active_with_general_first(): void
    {
        $this->seed(TicketSectionSeeder::class);

        $this->assertSame(39, TicketSection::query()->count());
        $this->assertSame(39, TicketSection::query()->where('active', true)->count());
        $this->assertSame('app.general', TicketSection::query()->orderBy('display_order')->value('key'));

        foreach (['app.navigation', 'maintenance.tickets', 'maintenance.troubleshooting', 'qa.cleaning-chart', 'toolbox.tickets', 'admin.access'] as $key) {
            $this->assertTrue(TicketSection::query()->where('key', $key)->exists(), "{$key} is missing");
        }
    }

    public function test_running_it_again_creates_nothing_new(): void
    {
        $this->seed(TicketSectionSeeder::class);
        $this->seed(TicketSectionSeeder::class);

        $this->assertSame(39, TicketSection::query()->count());
    }

    public function test_it_never_reactivates_a_retired_area_but_refreshes_its_wording(): void
    {
        $this->seed(TicketSectionSeeder::class);
        TicketSection::query()->where('key', 'reports.wbr')->update(['active' => false, 'name' => 'Old name']);

        $this->seed(TicketSectionSeeder::class);

        $section = TicketSection::query()->where('key', 'reports.wbr')->sole();
        $this->assertFalse($section->active);
        $this->assertSame('WBR reports', $section->name);
    }
}
