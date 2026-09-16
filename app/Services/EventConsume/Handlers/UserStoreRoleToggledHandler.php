<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\UserStoreRole;
use App\Services\EventConsume\EventHandlerInterface;
use App\Services\EventConsume\Handlers\Concerns\ReplicatesUserStoreRoles;
use Illuminate\Support\Facades\DB;

class UserStoreRoleToggledHandler implements EventHandlerInterface
{
    use ReplicatesUserStoreRoles;

    public function handle(array $event): void
    {
        $assignmentId = $this->asInt(
            data_get($event, 'data.assignment_id') ?? data_get($event, 'assignment_id')
        );

        if ($assignmentId <= 0) {
            throw new \Exception('UserStoreRoleToggledHandler: missing/invalid assignment_id');
        }

        $after = data_get($event, 'data.after_is_active') ?? data_get($event, 'after_is_active');

        if (! is_bool($after)) {
            if (! is_numeric($after)) {
                throw new \Exception('UserStoreRoleToggledHandler: missing after_is_active');
            }

            $after = ((int) $after) === 1;
        }

        DB::transaction(function () use ($assignmentId, $after) {
            UserStoreRole::query()->whereKey($assignmentId)->update(['active' => $after]);
        });
    }
}
