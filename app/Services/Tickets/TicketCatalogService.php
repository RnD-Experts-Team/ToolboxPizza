<?php

namespace App\Services\Tickets;

use App\Exceptions\TicketException;
use App\Models\TicketAssignment;
use App\Models\TicketLevel;
use App\Models\TicketSection;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The routing catalogue: sections the dashboard tags itself with, the levels
 * that group them, and who is assigned to either.
 *
 * Owns two invariants the database cannot express: no level may sit inside
 * itself, and an assignment targets exactly one of a section or a level. The
 * Form Requests check the second advisorily; this is the authoritative check.
 */
class TicketCatalogService
{
    public function __construct(private readonly TicketAccessService $access) {}

    // -------------------------------------------------------------------------
    // Sections
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sections(bool $includeInactive = false): array
    {
        return TicketSection::query()
            ->with('levels')
            ->when(! $includeInactive, fn ($q) => $q->where('active', true))
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn (TicketSection $s) => $this->presentSection($s))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSection(array $data): TicketSection
    {
        return TicketSection::query()->create([
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'display_order' => $data['display_order'] ?? 0,
            'active' => $data['active'] ?? true,
        ])->load('levels');
    }

    /**
     * `key` is deliberately not updatable: the dashboard hardcodes it, and
     * renaming it silently orphans every page that still sends the old one.
     * Retire the section and add a new one instead.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSection(TicketSection $section, array $data): TicketSection
    {
        $section->update(array_intersect_key($data, array_flip(['name', 'description', 'display_order', 'active'])));

        return $section->refresh()->load('levels');
    }

    /**
     * Resolve the key the dashboard sent when raising or re-sectioning a ticket.
     *
     * A retired section is refused here, and an unknown key reads the same way.
     * Tickets that already point at a retired section keep rendering it - they
     * never come through this path.
     *
     * @throws TicketException
     */
    public function resolveSection(string $key): TicketSection
    {
        $section = TicketSection::query()->where('key', $key)->first();

        if ($section === null || ! $section->active) {
            throw TicketException::sectionInactive($key);
        }

        return $section;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentSection(TicketSection $section): array
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

    // -------------------------------------------------------------------------
    // Levels
    // -------------------------------------------------------------------------

    /**
     * The whole catalogue as a tree, roots first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function levelTree(bool $includeInactive = false): array
    {
        $byParent = TicketLevel::query()
            ->with('sections')
            ->when(! $includeInactive, fn ($q) => $q->where('active', true))
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (TicketLevel $level) => $level->parent_id ?? 0);

        $build = function (int $parentId) use (&$build, $byParent): array {
            return ($byParent[$parentId] ?? collect())
                ->map(fn (TicketLevel $level) => $this->presentLevel($level) + ['children' => $build($level->id)])
                ->values()
                ->all();
        };

        return $build(0);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createLevel(array $data): TicketLevel
    {
        $level = TicketLevel::query()->create([
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'display_order' => $data['display_order'] ?? 0,
            'active' => $data['active'] ?? true,
        ]);

        // The graph is memoised for the request; the next resolution must see
        // this level.
        $this->access->refresh();

        return $level->load('sections');
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws TicketException
     */
    public function updateLevel(TicketLevel $level, array $data): TicketLevel
    {
        // THE PREVENTION GUARD. Everything downstream - resolution, the inbox,
        // notification targeting - walks this graph, so a cycle here would be
        // felt everywhere at once.
        if (array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'] === null ? null : (int) $data['parent_id'];

            if ($this->access->wouldCycle($level->id, $parentId)) {
                throw TicketException::levelCycle([$level->id, ...$this->access->descendantsOf($level->id)]);
            }
        }

        $level->update(array_intersect_key($data, array_flip(['name', 'description', 'parent_id', 'display_order', 'active'])));

        $this->access->refresh();

        return $level->refresh()->load('sections');
    }

    /**
     * Retire a level, and say how many assignments that silences.
     *
     * Deactivating a mid-tree level severs the chain, so everyone assigned ABOVE
     * it silently stops getting the sections below. The count is counted BEFORE
     * retiring, for the admin UI to put in its confirmation.
     */
    public function deactivateLevel(TicketLevel $level): int
    {
        $affected = TicketAssignment::query()
            ->where('active', true)
            ->whereIn('ticket_level_id', $this->access->descendantsOf($level->id))
            ->count();

        $level->update(['active' => false]);
        $this->access->refresh();

        return $affected;
    }

    /**
     * Whole-list replace of the sections under a level: the caller states the
     * set, and an empty array detaches everything.
     *
     * @param  array<int, int>  $sectionIds
     */
    public function syncLevelSections(TicketLevel $level, array $sectionIds): TicketLevel
    {
        DB::transaction(fn () => $level->sections()->sync($sectionIds));

        return $level->load('sections');
    }

    /**
     * @return array<string, mixed>
     */
    public function presentLevel(TicketLevel $level): array
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

    // -------------------------------------------------------------------------
    // Assignments
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function assignments(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 50), 1), 200);

        return TicketAssignment::query()
            ->with(['user', 'section', 'level'])
            ->when(filled($filters['user_ids'] ?? null), fn ($q) => $q->whereIn('user_id', (array) $filters['user_ids']))
            ->when(filled($filters['section_ids'] ?? null), fn ($q) => $q->whereIn('ticket_section_id', (array) $filters['section_ids']))
            ->when(filled($filters['level_ids'] ?? null), fn ($q) => $q->whereIn('ticket_level_id', (array) $filters['level_ids']))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (TicketAssignment $a) => $this->presentAssignment($a));
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws TicketException
     */
    public function createAssignment(array $data, User $actor): TicketAssignment
    {
        $sectionId = $data['ticket_section_id'] ?? null;
        $levelId = $data['ticket_level_id'] ?? null;

        if ($sectionId === null && $levelId === null) {
            throw TicketException::assignmentTargetRequired();
        }

        if ($sectionId !== null && $levelId !== null) {
            throw TicketException::assignmentTargetAmbiguous();
        }

        $duplicate = TicketAssignment::query()
            ->where('user_id', $data['user_id'])
            ->when($sectionId !== null, fn ($q) => $q->where('ticket_section_id', $sectionId))
            ->when($levelId !== null, fn ($q) => $q->where('ticket_level_id', $levelId))
            ->exists();

        if ($duplicate) {
            throw TicketException::assignmentDuplicate();
        }

        return TicketAssignment::query()->create([
            'user_id' => $data['user_id'],
            'ticket_section_id' => $sectionId,
            'ticket_level_id' => $levelId,
            // Defaults true: fail closed. A misconfigured assignment should
            // reach too few people, not every store in the estate.
            'store_scoped' => $data['store_scoped'] ?? true,
            'active' => $data['active'] ?? true,
            'created_by' => $actor->id,
        ])->load(['user', 'section', 'level']);
    }

    /**
     * The target is not movable - delete and recreate instead. Changing it in
     * place would silently re-route everything the holder is responsible for.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateAssignment(TicketAssignment $assignment, array $data): TicketAssignment
    {
        $assignment->update(array_intersect_key($data, array_flip(['store_scoped', 'active'])));

        return $assignment->refresh()->load(['user', 'section', 'level']);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentAssignment(TicketAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'user' => $assignment->relationLoaded('user') && $assignment->user
                ? ['id' => $assignment->user->id, 'name' => $assignment->user->name, 'email' => $assignment->user->email]
                : ['id' => $assignment->user_id],
            'section' => $assignment->relationLoaded('section') && $assignment->section
                ? ['id' => $assignment->section->id, 'key' => $assignment->section->key, 'name' => $assignment->section->name]
                : null,
            'level' => $assignment->relationLoaded('level') && $assignment->level
                ? ['id' => $assignment->level->id, 'key' => $assignment->level->key, 'name' => $assignment->level->name]
                : null,
            // The single most important field to surface: it is the difference
            // between "hiring, at their stores" and "hiring, everywhere".
            'store_scoped' => $assignment->store_scoped,
            'active' => $assignment->active,
            'via' => $assignment->via(),
            'created_at' => $assignment->created_at?->toIso8601String(),
        ];
    }
}
