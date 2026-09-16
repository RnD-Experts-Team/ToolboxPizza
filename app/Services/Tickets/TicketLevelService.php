<?php

namespace App\Services\Tickets;

use App\Models\TicketAssignment;
use App\Models\TicketLevel;
use App\Services\Tickets\Exceptions\TicketException;
use Illuminate\Support\Facades\DB;

/**
 * The level catalog, and the only place a `parent_id` is ever written.
 *
 * This service owns CYCLE PREVENTION. TicketLevelGraph's visited sets contain a
 * cycle that arrives some other way; this is what stops one entering through the
 * API in the first place.
 */
class TicketLevelService
{
    public function __construct(private readonly TicketLevelGraph $graph) {}

    /**
     * The whole catalog as a tree, roots first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(bool $includeInactive = false): array
    {
        $levels = TicketLevel::query()
            ->with('sections')
            ->when(! $includeInactive, fn ($q) => $q->where('active', true))
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        $byParent = [];

        foreach ($levels as $level) {
            $byParent[$level->parent_id ?? 0][] = $level;
        }

        $build = function (?int $parentId) use (&$build, $byParent): array {
            return array_map(
                fn (TicketLevel $level) => $this->present($level) + ['children' => $build($level->id)],
                $byParent[$parentId ?? 0] ?? [],
            );
        };

        return $build(null);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws TicketException
     */
    public function create(array $data): TicketLevel
    {
        $level = TicketLevel::query()->create([
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'display_order' => $data['display_order'] ?? 0,
            'active' => $data['active'] ?? true,
        ]);

        // A brand-new row has no descendants, so it cannot close a loop - but
        // refresh anyway, because the graph is memoised for the request and the
        // next resolution must see this level.
        $this->graph->refresh();

        return $level;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws TicketException
     */
    public function update(TicketLevel $level, array $data): TicketLevel
    {
        if (array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'] === null ? null : (int) $data['parent_id'];

            // THE PREVENTION GUARD. Everything downstream - resolution, the
            // inbox, notification targeting - walks this graph, so a cycle here
            // would be felt everywhere at once.
            if ($this->graph->wouldCycle($level->id, $parentId)) {
                throw TicketException::levelCycle([
                    $level->id,
                    ...$this->graph->descendantsOf($level->id),
                ]);
            }
        }

        $level->update(array_intersect_key($data, array_flip([
            'name', 'description', 'parent_id', 'display_order', 'active',
        ])));

        $this->graph->refresh();

        return $level->refresh();
    }

    public function deactivate(TicketLevel $level): TicketLevel
    {
        $level->update(['active' => false]);
        $this->graph->refresh();

        return $level->refresh();
    }

    /**
     * How many assignments stop receiving if this level is retired.
     *
     * Deactivating a mid-tree level severs the chain, so everyone assigned
     * ABOVE it silently stops getting the sections below. The admin UI is meant
     * to warn with this number rather than let that happen quietly.
     */
    public function assignmentsAffectedByDeactivating(TicketLevel $level): int
    {
        return TicketAssignment::query()
            ->where('active', true)
            ->whereIn('ticket_level_id', $this->graph->descendantsOf($level->id))
            ->count();
    }

    /**
     * Whole-list replace of the sections under a level, mirroring the
     * break-milestones replace: the caller states the set, an empty array
     * detaches everything.
     *
     * @param  array<int, int>  $sectionIds
     */
    public function syncSections(TicketLevel $level, array $sectionIds): TicketLevel
    {
        DB::transaction(fn () => $level->sections()->sync($sectionIds));

        return $level->load('sections');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(TicketLevel $level): array
    {
        return [
            'id' => $level->id,
            'key' => $level->key,
            'name' => $level->name,
            'description' => $level->description,
            'parent_id' => $level->parent_id,
            'display_order' => $level->display_order,
            'active' => $level->active,
            'sections' => $level->relationLoaded('sections')
                ? $level->sections->map(fn ($s) => ['id' => $s->id, 'key' => $s->key, 'name' => $s->name])->all()
                : null,
        ];
    }
}
