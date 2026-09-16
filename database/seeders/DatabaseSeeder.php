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
        $this->call(BreakTypeSeeder::class);

        // Sections only. Levels and assignments are org structure, not catalog:
        // seeding them would invent a reporting line nobody agreed to.
        $this->call(TicketSectionSeeder::class);
    }
}
