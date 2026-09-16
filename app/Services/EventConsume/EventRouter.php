<?php

namespace App\Services\EventConsume;

use App\Services\EventConsume\Handlers\StoreCreatedHandler;
use App\Services\EventConsume\Handlers\StoreDeletedHandler;
use App\Services\EventConsume\Handlers\StoreUpdatedHandler;
use App\Services\EventConsume\Handlers\UserCreatedHandler;
use App\Services\EventConsume\Handlers\UserDeletedHandler;
use App\Services\EventConsume\Handlers\UserStoreRoleAssignedHandler;
use App\Services\EventConsume\Handlers\UserStoreRoleBulkAssignedHandler;
use App\Services\EventConsume\Handlers\UserStoreRoleRemovedHandler;
use App\Services\EventConsume\Handlers\UserStoreRoleToggledHandler;
use App\Services\EventConsume\Handlers\UserUpdatedHandler;
use Exception;

class EventRouter
{
    /** @var array<string, class-string<EventHandlerInterface>> */
    private array $map;

    public function __construct()
    {
        $devMode = (bool) config('nats.dev_mode');

        $authPrefix = $devMode
            ? 'auth.testing.v1'
            : 'auth.v1';

        $this->map = [
            // USERS (source of truth: pizzasys)
            //
            // The only subjects this service consumes. A user must exist here
            // before they can use anything — AuthTokenStoreScopeMiddleware
            // returns 401 'user not synced yet' otherwise — so `nats:consume`
            // has to run supervised.
            "{$authPrefix}.user.created" => UserCreatedHandler::class,
            "{$authPrefix}.user.updated" => UserUpdatedHandler::class,
            "{$authPrefix}.user.deleted" => UserDeletedHandler::class,

            // STORES (source of truth: pizzasys). Tickets are store-scoped by
            // store number in the path, so this service now needs its own copy.
            "{$authPrefix}.store.created" => StoreCreatedHandler::class,
            "{$authPrefix}.store.updated" => StoreUpdatedHandler::class,
            "{$authPrefix}.store.deleted" => StoreDeletedHandler::class,

            // STORE ROLE ASSIGNMENTS (source of truth: pizzasys).
            //
            // This is what lets ticket routing answer "may user 812, who is
            // making no request at all, receive a ticket for 03795-00001".
            // pizzasys decides that for THE CALLER on every request, but it is
            // never asked about a recipient - and a recipient is exactly who we
            // are resolving. See the user_store_roles migration.
            "{$authPrefix}.assignment.user_role_store.assigned" => UserStoreRoleAssignedHandler::class,
            "{$authPrefix}.assignment.user_role_store.bulk_assigned" => UserStoreRoleBulkAssignedHandler::class,
            "{$authPrefix}.assignment.user_role_store.toggled" => UserStoreRoleToggledHandler::class,
            "{$authPrefix}.assignment.user_role_store.removed" => UserStoreRoleRemovedHandler::class,
        ];
    }

    /** @return array<string, class-string<EventHandlerInterface>> */
    public function getResolvedMap(): array
    {
        return $this->map;
    }

    /** @return class-string<EventHandlerInterface> */
    public function resolve(string $subject): string
    {
        if (! isset($this->map[$subject])) {
            throw new Exception("No handler for subject '{$subject}'");
        }

        return $this->map[$subject];
    }
}
