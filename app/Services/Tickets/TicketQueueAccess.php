<?php

namespace App\Services\Tickets;

use App\Models\Store;
use App\Models\User;
use App\Services\AuthServerClient;
use App\Services\StoreAccessResolver;

/**
 * Does the CALLER hold the `view tickets` permission, and for which stores?
 *
 * `view tickets` is what lets someone list a store's whole ticket queue
 * (GET /stores/{storeId}/tickets). This applies the same permission to opening
 * one ticket of that queue and to the cross-store inbox, so the list and the
 * rows in it agree.
 *
 * Two ways to hold it, both decided by pizzasys and never re-derived here from
 * role names:
 *
 *  - GLOBALLY: it is in the permissions pizzasys returned for this request
 *    (the super-admin holds every permission; a head holding Store Manager
 *    globally holds it too). Free - no extra call.
 *  - PER STORE: a worker holds it only through a store role. That is not in the
 *    token's permissions, so pizzasys is asked the very question its own rule
 *    for GET /stores/{storeId}/tickets answers, with every store in question
 *    in ONE call, and its per-store verdicts are read back.
 *
 * Only ever answers for the authenticated caller, from their own token: asking
 * about anyone else, or in a queue worker with no request, is "no". A failed
 * call is "no" too - this only ever ADDS visibility, so failing closed costs a
 * 404, never a leak.
 */
class TicketQueueAccess
{
    /** The route pizzasys' `view tickets` rule is attached to. */
    private const ROUTE_NAME = 'api.v1.stores.tickets.index';

    /** @var array<int, bool> stores.id => may the caller list its queue; memoised per request */
    private array $byStore = [];

    public function __construct(
        private readonly AuthServerClient $authServer,
        private readonly StoreAccessResolver $storeAccess,
    ) {}

    /**
     * Does the caller run the ticket system (`administer tickets`, global)?
     * pizzasys gates the catalog on it; here it also means acting on any
     * ticket.
     */
    public function administers(User $user): bool
    {
        return $this->callerHolds($user, 'administer tickets');
    }

    /**
     * Held globally: every store's queue. An administrator sees every ticket
     * too, whether or not `view tickets` was also granted to them.
     */
    public function holdsGlobally(User $user): bool
    {
        return $this->callerHolds($user, 'view tickets') || $this->administers($user);
    }

    private function callerHolds(User $user, string $permission): bool
    {
        if (! $this->isCaller($user)) {
            return false;
        }

        return in_array($permission, (array) request()->attributes->get('authz_permissions', []), true);
    }

    /**
     * May the caller see every ticket of this store?
     */
    public function canViewStore(User $user, ?Store $store): bool
    {
        if (! $this->isCaller($user)) {
            return false;
        }

        if ($this->holdsGlobally($user)) {
            return true;
        }

        if ($store === null) {
            return false;
        }

        $this->ask([(int) $store->id]);

        return $this->byStore[(int) $store->id] ?? false;
    }

    /**
     * The stores whose whole queue the caller may see. NULL means every store,
     * and the caller must drop its clause rather than build a whereIn over the
     * estate - the same convention as StoreAccessResolver.
     *
     * @return array<int, int>|null
     */
    public function viewableStoreIds(User $user): ?array
    {
        if (! $this->isCaller($user)) {
            return [];
        }

        if ($this->holdsGlobally($user)) {
            return null;
        }

        // Only a store the caller holds a role at can grant it. An unscoped
        // grant ('all') is a role at every store, so every store is a candidate.
        $candidates = $this->storeAccess->accessibleStoreIdsFor((int) $user->id)
            ?? Store::query()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($candidates === []) {
            return [];
        }

        $this->ask($candidates);

        return array_values(array_filter($candidates, fn (int $id) => $this->byStore[$id] ?? false));
    }

    /**
     * Fill the memo for every store not already answered, in one call.
     *
     * @param  array<int, int>  $storeIds
     */
    private function ask(array $storeIds): void
    {
        $missing = array_values(array_filter(
            array_unique($storeIds),
            fn (int $id) => ! array_key_exists($id, $this->byStore),
        ));

        if ($missing === []) {
            return;
        }

        $token = (string) request()->bearerToken();
        $first = Store::query()->find($missing[0]);

        if ($token === '' || $first === null) {
            foreach ($missing as $id) {
                $this->byStore[$id] = false;
            }

            return;
        }

        $code = (string) $first->store_number;

        // The path carries one store and `stores` the rest: pizzasys reads the
        // store from every usual place and checks each one.
        $verify = $this->authServer->verify($token, 'GET', "/api/v1/stores/{$code}/tickets", self::ROUTE_NAME, [
            'header' => [],
            'body' => [],
            'path' => ['storeId' => $code],
            'query' => ['stores' => $missing],
        ]);

        $active = (bool) ($verify['active'] ?? false);
        $authorized = $active && (bool) data_get($verify, 'ext.authorized', false);
        $perStore = data_get($verify, 'ext.store.per_store');

        foreach ($missing as $id) {
            $this->byStore[$id] = match (true) {
                ! $active => false,
                // Present whenever pizzasys weighed the stores one by one.
                is_array($perStore) => (bool) ($perStore[$id] ?? false),
                // Authorized with no verdicts: a bypass that covers every store.
                default => $authorized,
            };
        }
    }

    private function isCaller(User $user): bool
    {
        $caller = request()->user();

        return $caller !== null && (int) $caller->id === (int) $user->id;
    }
}
