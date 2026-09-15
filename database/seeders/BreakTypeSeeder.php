<?php

namespace Database\Seeders;

use App\Enums\BreakTypeGroup;
use App\Models\BreakType;
use Illuminate\Database\Seeder;

/**
 * The break catalog. Idempotent on `slug`, so re-running only updates copy and
 * never duplicates a type that history already points at.
 *
 * Order and wording follow the picker the product specified: the counted types,
 * then a separator, then "Special breaks - excluded from limit".
 */
class BreakTypeSeeder extends Seeder
{
    public function run(): void
    {
        $sort = 0;

        foreach ($this->catalog() as $type) {
            $sort += 10;

            BreakType::query()->updateOrCreate(
                ['slug' => $type['slug']],
                $type + ['sort_order' => $sort, 'active' => true],
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalog(): array
    {
        $counted = [
            'coffee_break' => 'Coffee break',
            'smoking' => 'Smoking',
            'rest_room' => 'Rest room',
            'visit_a_friend' => 'Visit a friend',
            'meal_snack' => 'Meal / snack',
            'fresh_air' => 'Fresh air',
            'personal_call' => 'Personal call',
            'stretch_walk' => 'Stretch / walk',
            'other' => 'Custom / Other',
        ];

        // Excluded from the allowance: the employee was called away rather than
        // taking time for themselves, so it is recorded but never counted.
        $excluded = [
            'general_manager' => 'General Manager',
            'maintenance' => 'Maintenance',
            'specialists' => 'Specialists',
            'management' => 'Management',
            'hiring' => 'Hiring',
            'finance' => 'Finance',
        ];

        $rows = [];

        foreach ($counted as $slug => $name) {
            $rows[] = [
                'slug' => $slug,
                'name' => $name,
                'group' => BreakTypeGroup::Regular->value,
                'counts_toward_limit' => true,
                // Only "Custom / Other" asks the user what it actually was.
                'requires_custom_label' => $slug === 'other',
            ];
        }

        foreach ($excluded as $slug => $name) {
            $rows[] = [
                'slug' => $slug,
                'name' => $name,
                'group' => BreakTypeGroup::Special->value,
                'counts_toward_limit' => false,
                'requires_custom_label' => false,
            ];
        }

        return $rows;
    }
}
