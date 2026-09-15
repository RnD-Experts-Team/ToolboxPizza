<?php

namespace App\Services\EventConsume;

use App\Services\EventConsume\Handlers\UserCreatedHandler;
use App\Services\EventConsume\Handlers\UserDeletedHandler;
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

            // STORES (source of truth: pizzasys) are NOT replicated here.
            // Breaks are per-user and nothing in this service is store-scoped;
            // the auth middleware's buildStoreContext() reads the request, not
            // the database, so it needs no stores table either. The tickets
            // slice will want them — the pattern is identical (updateOrCreate
            // on a replicated primary key), plus a stores migration and the
            // three "{$authPrefix}.store.*" entries here.
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
