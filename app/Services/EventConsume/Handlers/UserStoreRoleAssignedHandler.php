<?php

namespace App\Services\EventConsume\Handlers;

use App\Services\EventConsume\EventHandlerInterface;
use App\Services\EventConsume\Handlers\Concerns\ReplicatesUserStoreRoles;

class UserStoreRoleAssignedHandler implements EventHandlerInterface
{
    use ReplicatesUserStoreRoles;

    public function handle(array $event): void
    {
        $assignment = $this->extractAssignment($event);

        $this->upsertAssignment(
            $assignment,
            $this->asInt(data_get($assignment, 'user_id') ?? data_get($event, 'data.user_id')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function extractAssignment(array $event): array
    {
        foreach (['data.assignment', 'assignment', 'payload.assignment'] as $path) {
            $assignment = data_get($event, $path);

            if (is_array($assignment)) {
                return $assignment;
            }
        }

        throw new \Exception('UserStoreRoleAssignedHandler: assignment payload not found');
    }
}
