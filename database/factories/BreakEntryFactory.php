<?php

namespace Database\Factories;

use App\Enums\BreakSource;
use App\Models\BreakEntry;
use App\Models\BreakType;
use App\Services\Breaks\WorkDayResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BreakEntry>
 */
class BreakEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = CarbonImmutable::now()->subHours(2);
        $endedAt = $startedAt->addMinutes(10);

        return [
            'user_id' => null,
            'break_type_id' => fn () => BreakType::query()->where('slug', 'coffee_break')->value('id'),
            'other_label' => null,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            // Derived exactly as the service derives it, so a factory-built row
            // is indistinguishable from one the API wrote.
            'work_date' => fn (array $attrs) => app(WorkDayResolver::class)
                ->workDateFor(CarbonImmutable::parse($attrs['started_at'])),
            'duration_seconds' => fn (array $attrs) => $attrs['ended_at'] === null
                ? null
                : CarbonImmutable::parse($attrs['started_at'])
                    ->diffInSeconds(CarbonImmutable::parse($attrs['ended_at']), absolute: false),
            'counts_toward_limit' => true,
            'source' => BreakSource::Timer,
        ];
    }

    /**
     * A break of a given catalog slug, taking its counted/excluded flag from
     * the catalog the way a real write does.
     */
    public function ofType(string $slug): static
    {
        return $this->state(function () use ($slug) {
            $type = BreakType::query()->where('slug', $slug)->firstOrFail();

            return [
                'break_type_id' => $type->id,
                'counts_toward_limit' => $type->counts_toward_limit,
                'other_label' => $type->requires_custom_label ? 'Something else' : null,
            ];
        });
    }

    /**
     * Fix the window explicitly. Everything derived from it follows.
     */
    public function between(string $startedAt, ?string $endedAt): static
    {
        return $this->state(function () use ($startedAt, $endedAt) {
            $start = CarbonImmutable::parse($startedAt)->utc();
            $end = $endedAt === null ? null : CarbonImmutable::parse($endedAt)->utc();

            return [
                'started_at' => $start,
                'ended_at' => $end,
                'work_date' => app(WorkDayResolver::class)->workDateFor($start),
                'duration_seconds' => $end === null ? null : $start->diffInSeconds($end, absolute: false),
            ];
        });
    }

    public function running(): static
    {
        return $this->state(fn () => ['ended_at' => null, 'duration_seconds' => null]);
    }

    public function manual(): static
    {
        return $this->state(fn () => ['source' => BreakSource::Manual]);
    }
}
