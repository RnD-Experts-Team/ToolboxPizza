<?php

namespace Database\Factories;

use App\Models\TicketResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketResponse>
 */
class TicketResponseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['body' => fake()->sentence()];
    }
}
