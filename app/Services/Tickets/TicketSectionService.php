<?php

namespace App\Services\Tickets;

use App\Models\TicketSection;
use App\Services\Tickets\Exceptions\TicketException;
use Illuminate\Database\Eloquent\Collection;

/**
 * The section catalog - what the dashboard's `section_key` resolves against.
 */
class TicketSectionService
{
    /**
     * @return Collection<int, TicketSection>
     */
    public function index(bool $includeInactive = false): Collection
    {
        return TicketSection::query()
            ->with('levels')
            ->when(! $includeInactive, fn ($q) => $q->where('active', true))
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): TicketSection
    {
        return TicketSection::query()->create([
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'display_order' => $data['display_order'] ?? 0,
            'active' => $data['active'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TicketSection $section, array $data): TicketSection
    {
        // `key` is deliberately not updatable: the dashboard hardcodes it, and
        // renaming it silently orphans every box that still sends the old one.
        // Retire the section and add a new one instead.
        $section->update(array_intersect_key($data, array_flip([
            'name', 'description', 'display_order', 'active',
        ])));

        return $section->refresh();
    }

    /**
     * Retirement, not deletion: tickets point at this row and must keep
     * rendering their section's name forever.
     */
    public function deactivate(TicketSection $section): TicketSection
    {
        $section->update(['active' => false]);

        return $section->refresh();
    }

    /**
     * Resolve the key the dashboard sent.
     *
     * A retired section still resolves for tickets that already point at it;
     * only a NEW ticket is refused. Same split as BreakWriteService::resolveType().
     *
     * @throws TicketException
     */
    public function resolveByKey(string $key, bool $forNewTicket = true): TicketSection
    {
        $section = TicketSection::query()->where('key', $key)->first();

        if ($section === null) {
            throw TicketException::sectionInactive($key);
        }

        if ($forNewTicket && ! $section->active) {
            throw TicketException::sectionInactive($key);
        }

        return $section;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(TicketSection $section): array
    {
        return [
            'id' => $section->id,
            'key' => $section->key,
            'name' => $section->name,
            'description' => $section->description,
            'display_order' => $section->display_order,
            'active' => $section->active,
            'levels' => $section->relationLoaded('levels')
                ? $section->levels->map(fn ($l) => ['id' => $l->id, 'key' => $l->key, 'name' => $l->name])->all()
                : null,
        ];
    }
}
