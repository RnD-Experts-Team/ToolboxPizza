<?php

namespace App\Services\EventConsume\Handlers\Concerns;

use App\Models\User;
use App\Models\UserStoreRole;
use Illuminate\Support\Facades\DB;

/**
 * Shared parsing for pizzasys' auth.v1.assignment.user_role_store.* events.
 *
 * The payload carries BOTH a numeric store pk (`store_id`) and the store code
 * (`store_lc_id`). We store the CODE, because that is what a ticket route and a
 * notification target speak in - and because it is the only one that survives
 * without a join.
 */
trait ReplicatesUserStoreRoles
{
    /**
     * Write one assignment row.
     *
     * `$userId` is passed in rather than read from `$assignment`, because the
     * bulk event does not repeat it per row - see UserStoreRoleBulkAssignedHandler.
     *
     * @param  array<string, mixed>  $assignment
     */
    protected function upsertAssignment(array $assignment, int $userId): void
    {
        $id = $this->asInt(data_get($assignment, 'id'));

        if ($id <= 0) {
            throw new \Exception('user_role_store: missing/invalid assignment.id');
        }

        if ($userId <= 0) {
            throw new \Exception('user_role_store: missing/invalid user_id');
        }

        $roleId = $this->asInt(data_get($assignment, 'role_id'));

        if ($roleId <= 0) {
            // Fail closed rather than store an ambiguous grant.
            throw new \Exception('user_role_store: missing/invalid role_id');
        }

        // Fail closed if the user has not replicated yet: the consumer NAKs and
        // the redelivery succeeds once auth.v1.user.created has landed.
        if (! User::query()->whereKey($userId)->exists()) {
            throw new \Exception("user_role_store: user {$userId} not synced yet");
        }

        // role_name is NOT NULL. Prefer the supplied name, else a stable
        // placeholder - the same scheme the rest of the estate uses, so a
        // removal that targets a role name still lines up.
        $roleName = (string) (
            data_get($assignment, 'role_name')
            ?? data_get($assignment, 'role.name')
            ?? ('role_id_'.$roleId)
        );

        // THE CODE, not the pk. `store_lc_id` is pizzasys' stores.store_id.
        // Absent means the grant is not scoped to one store.
        $storeCode = (string) (data_get($assignment, 'store_lc_id') ?? 'all');

        $meta = data_get($assignment, 'metadata') ?? data_get($assignment, 'meta');

        DB::transaction(function () use ($id, $userId, $storeCode, $roleName, $assignment, $meta) {
            UserStoreRole::query()->updateOrCreate(
                ['id' => $id],
                [
                    'user_id' => $userId,
                    'store_id' => $storeCode,
                    'role_name' => $roleName,
                    'active' => (bool) data_get($assignment, 'is_active', true),
                    'meta' => is_array($meta) ? $meta : ($meta === null ? null : ['value' => $meta]),
                ],
            );
        });
    }

    protected function asInt(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }

        if (is_string($v) && ctype_digit($v)) {
            return (int) $v;
        }

        if (is_numeric($v)) {
            return (int) $v;
        }

        return 0;
    }
}
