<?php

namespace App\Services\EventConsume\Handlers;

use App\Services\EventConsume\EventHandlerInterface;
use App\Services\EventConsume\Handlers\Concerns\ReplicatesUserStoreRoles;

/**
 * auth.v1.assignment.user_role_store.bulk_assigned
 *
 * pizzasys emits ONE user_id at data.user_id and a list of rows that do NOT
 * repeat it (see UserRoleStoreService::bulkAssignUserRoleStore). NotificationsPizza's
 * copy of this handler forwards each row to the single-assignment handler
 * untouched, which then looks for a user_id that is not there and throws - so
 * every bulk assignment in the estate currently fails. This version reads the
 * user id from where pizzasys actually puts it.
 */
class UserStoreRoleBulkAssignedHandler implements EventHandlerInterface
{
    use ReplicatesUserStoreRoles;

    public function handle(array $event): void
    {
        $userId = $this->asInt(data_get($event, 'data.user_id') ?? data_get($event, 'user_id'));

        if ($userId <= 0) {
            throw new \Exception('UserStoreRoleBulkAssignedHandler: missing/invalid data.user_id');
        }

        $assignments = data_get($event, 'data.assignments')
            ?? data_get($event, 'assignments')
            ?? data_get($event, 'payload.assignments');

        if (! is_array($assignments)) {
            throw new \Exception('UserStoreRoleBulkAssignedHandler: assignments payload not found');
        }

        foreach ($assignments as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            // A row may still carry its own user_id on some producers; prefer it
            // and fall back to the envelope's.
            $this->upsertAssignment(
                $assignment,
                $this->asInt(data_get($assignment, 'user_id')) ?: $userId,
            );
        }
    }
}
