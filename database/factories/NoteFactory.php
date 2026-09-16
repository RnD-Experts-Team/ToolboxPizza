<?php

namespace Database\Factories;

use App\Models\Note;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Note>
 *
 * Note declares @use HasFactory<NoteFactory> but this file was removed during
 * the breaks work, so Note::factory() fataled until now.
 */
class NoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => null,
            'body' => fake()->sentence(),
            'created_by' => null,
        ];
    }
}
