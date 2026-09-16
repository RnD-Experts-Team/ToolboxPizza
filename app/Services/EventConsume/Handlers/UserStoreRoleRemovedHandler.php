<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\UserStoreRole;
use App\Services\EventConsume\EventHandlerInterface;
use App\Services\EventConsume\Handlers\Concerns\ReplicatesUserStoreRoles;
use Illuminate\Support\Facades\DB;

/**
 * auth.v1.assignment.user_role_store.removed
 *
 * Deletes by assignment id and nothing else. pizzasys always sends one.
 *
 * NotificationsPizza's copy carries a fallback that targets (user_id, role_id,
 * store_id) when the id is absent, and that fallback cannot work: it resolves
 * store_id to an INTEGER and compares it to a column holding the store CODE,
 * and it matches NULL for an unscoped grant where the assigned handler writes
 * the string 'all'. It silently deletes nothing. A branch that looks like it
 * cleans up and does not is worse than no branch, so this throws instead.
 */
class UserStoreRoleRemovedHandler implements EventHandlerInterface
{
    use ReplicatesUserStoreRoles;

    public function handle(array $event): void
    {
        $assignmentId = $this->asInt(
            data_get($event, 'data.assignment_id')
                ?? data_get($event, 'assignment_id')
                ?? data_get($event, 'data.assignment.id')
                ?? data_get($event, 'assignment.id')
        );

        if ($assignmentId <= 0) {
            throw new \Exception('UserStoreRoleRemovedHandler: missing/invalid assignment_id');
        }

        // Idempotent: a redelivery deletes nothing and succeeds.
        DB::transaction(function () use ($assignmentId) {
            UserStoreRole::query()->whereKey($assignmentId)->delete();
        });
    }
}
