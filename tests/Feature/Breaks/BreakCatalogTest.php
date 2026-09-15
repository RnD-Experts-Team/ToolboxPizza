<?php

namespace Tests\Feature\Breaks;

use App\Models\BreakType;
use Database\Seeders\BreakTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

class BreakCatalogTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
        $this->seed(BreakTypeSeeder::class);
    }

    public function test_the_catalog_lists_every_seeded_type_in_picker_order(): void
    {
        $response = $this->getJson('/api/v1/break-types', $this->headers())->assertOk();

        $slugs = array_column($response->json('data'), 'slug');

        $this->assertSame([
            'coffee_break', 'smoking', 'rest_room', 'visit_a_friend', 'meal_snack',
            'fresh_air', 'personal_call', 'stretch_walk', 'other',
            'general_manager', 'maintenance', 'specialists', 'management', 'hiring', 'finance',
        ], $slugs);
    }

    public function test_the_special_types_are_flagged_as_excluded_from_the_limit(): void
    {
        $excluded = BreakType::query()
            ->where('counts_toward_limit', false)
            ->orderBy('sort_order')
            ->pluck('slug')
            ->all();

        $this->assertSame(
            ['general_manager', 'maintenance', 'specialists', 'management', 'hiring', 'finance'],
            $excluded,
        );
    }

    public function test_only_the_custom_other_type_asks_for_a_label(): void
    {
        $this->assertSame(
            ['other'],
            BreakType::query()->where('requires_custom_label', true)->pluck('slug')->all(),
        );
    }

    public function test_a_retired_type_disappears_from_the_picker_but_keeps_its_row(): void
    {
        BreakType::query()->where('slug', 'smoking')->update(['active' => false]);

        $slugs = array_column($this->getJson('/api/v1/break-types', $this->headers())->json('data'), 'slug');

        $this->assertNotContains('smoking', $slugs);
        // The row survives, because 30 days of history point at it.
        $this->assertDatabaseHas('break_types', ['slug' => 'smoking']);
    }

    public function test_reseeding_updates_copy_without_duplicating_a_type(): void
    {
        BreakType::query()->where('slug', 'rest_room')->update(['name' => 'Bathroom']);

        $this->seed(BreakTypeSeeder::class);

        $this->assertSame(15, BreakType::query()->count());
        $this->assertDatabaseHas('break_types', ['slug' => 'rest_room', 'name' => 'Rest room']);
    }
}
