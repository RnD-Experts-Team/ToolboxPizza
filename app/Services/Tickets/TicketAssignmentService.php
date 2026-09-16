<?php

namespace App\Services\Tickets;

use App\Models\TicketAssignment;
use App\Models\User;
use App\Services\Tickets\Exceptions\TicketException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Who is responsible for what.
 *
 * Owns the section-XOR-level invariant. The Form Request checks it too, but
 * advisorily - this is the authoritative one, the same split BreakWriteService
 * documents.
 */
class TicketAssignmentService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function index(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 50), 1), 200);

        return TicketAssignment::query()
            ->with(['user', 'section', 'level'])
            ->when(filled($filters['user_ids'] ?? null), fn ($q) => $q->whereIn('user_id', (array) $filters['user_ids']))
            ->when(filled($filters['section_ids'] ?? null), fn ($q) => $q->whereIn('ticket_section_id', (array) $filters['section_ids']))
            ->when(filled($filters['level_ids'] ?? null), fn ($q) => $q->whereIn('ticket_level_id', (array) $filters['level_ids']))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (TicketAssignment $a) => $this->present($a));
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws TicketException
     */
    public function create(array $data, ?User $actor = null): TicketAssignment
    {
        $sectionId = $data['ticket_section_id'] ?? null;
        $levelId = $data['ticket_level_id'] ?? null;

        $this->assertExactlyOneTarget($sectionId, $levelId);

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
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TicketAssignment $assignment, array $data): TicketAssignment
    {
        // The target is not movable - delete and recreate instead. Changing it
        // in place would silently re-route everything the holder is responsible
        // for, and the unique indexes are keyed on it.
        $assignment->update(array_intersect_key($data, array_flip(['store_scoped', 'active'])));

        return $assignment->refresh();
    }

    public function delete(TicketAssignment $assignment): void
    {
        // A real delete: an assignment is configuration, not history. The
        // tickets it once routed keep their participants and their audit trail.
        $assignment->delete();
    }

    /**
     * @throws TicketException
     */
    private function assertExactlyOneTarget(mixed $sectionId, mixed $levelId): void
    {
        if ($sectionId === null && $levelId === null) {
            throw TicketException::assignmentTargetRequired();
        }

        if ($sectionId !== null && $levelId !== null) {
            throw TicketException::assignmentTargetAmbiguous();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function present(TicketAssignment $assignment): array
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
