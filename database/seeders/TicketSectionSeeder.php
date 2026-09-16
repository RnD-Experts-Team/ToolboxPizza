<?php

namespace Database\Seeders;

use App\Models\TicketSection;
use Illuminate\Database\Seeder;

/**
 * The section catalog the frontend tags areas with.
 *
 * These KEYS are hardcoded in the dashboard, so the rows have to exist before a
 * ticket can be raised from those screens - unlike levels and assignments, which
 * are org structure and belong to whoever administers the queues.
 *
 * Idempotent on `key`, and it deliberately does NOT touch `active`: retiring a
 * section is an admin decision, and re-running a seeder must not quietly undo
 * it. Only the wording and the picker order are refreshed.
 */
class TicketSectionSeeder extends Seeder
{
    public function run(): void
    {
        $order = 0;

        foreach ($this->catalog() as $key => [$name, $description]) {
            $order += 10;

            $section = TicketSection::query()->firstOrNew(['key' => $key]);

            $section->fill([
                'name' => $name,
                'description' => $description,
                'display_order' => $order,
            ]);

            // Only on first insert. An existing row keeps whatever the admin set.
            if (! $section->exists) {
                $section->active = true;
            }

            $section->save();
        }
    }

    /**
     * @return array<string, array{0: string,1: string}>
     */
    private function catalog(): array
    {
        return [
            // Boxes 3, 4 and 7 of the main dashboard all report here: they are
            // one problem to whoever fixes them, however many tiles they occupy.
            'inventory.main-dashboard' => [
                'Main dashboard - inventory',
                'Inventory boxes on the main dashboard.',
            ],
            'screens.menu' => [
                'Menu screens',
                'In-store menu boards and the screens app.',
            ],
            'hiring.page' => [
                'Hiring page',
                'The hiring dashboard itself.',
            ],
            'hiring.tickets' => [
                'Hiring tickets',
                'Tickets raised from inside the hiring workflow.',
            ],
        ];
    }
}
