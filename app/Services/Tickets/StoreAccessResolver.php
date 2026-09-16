<?php

namespace App\Services\Tickets;

use App\Models\Store;
use App\Models\UserStoreRole;

/**
 * Which stores a given user can reach, answered from the replicated
 * user_store_roles.
 *
 * WHY THIS EXISTS WHEN PIZZASYS ALREADY DECIDES ACCESS: pizzasys answers "may
 * THE CALLER touch this store", at request time, for the caller. Ticket routing
 * has to answer a different question - "may user 812, who is making no request
 * at all, receive a ticket for 03795-00001" - and there is nobody to ask.
 *
 * THE CALLER'S OWN ACCESS IS NOT RE-CHECKED HERE. pizzasys stays authoritative
 * for that; a stale local replica denying a live grant would lock out a
 * legitimately-granted user with no recovery path from inside this service. The
 * two checks answer different questions and neither replaces the other.
 */
class StoreAccessResolver
{
    public function canAccessStore(int $userId, string $storeCode): bool
    {
        return UserStoreRole::query()
            ->where('active', true)
            ->where('user_id', $userId)
            ->coveringStore($storeCode)
            ->exists();
    }

    /**
     * Narrow a candidate list to those who hold the store. ONE query, never one
     * per user - a section with fifty assignees is an ordinary case.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    public function filterUsersWithStoreAccess(array $userIds, string $storeCode): array
    {
        if ($userIds === []) {
            return [];
        }

        return UserStoreRole::query()
            ->where('active', true)
            ->whereIn('user_id', $userIds)
            ->coveringStore($storeCode)
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * True when the user holds an unscoped grant, i.e. every store.
     */
    public function hasAllStores(int $userId): bool
    {
        return UserStoreRole::query()
            ->where('active', true)
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('store_id', 'all')->orWhereNull('store_id');
            })
            ->exists();
    }

    /**
     * The stores.id values this user may see.
     *
     * NULL means "every store", and the caller must DROP the clause rather than
     * building a whereIn over the whole estate.
     *
     * Note the join is by CODE: user_store_roles.store_id holds
     * "03795-00001", never stores.id. Matching them by id would silently return
     * nothing, or - worse - the wrong store.
     *
     * @return array<int, int>|null
     */
    public function accessibleStoreIdsFor(int $userId): ?array
    {
        if ($this->hasAllStores($userId)) {
            return null;
        }

        $codes = UserStoreRole::query()
            ->where('active', true)
            ->where('user_id', $userId)
            ->whereNotNull('store_id')
            ->where('store_id', '!=', 'all')
            ->distinct()
            ->pluck('store_id')
            ->all();

        if ($codes === []) {
            return [];
        }

        return Store::query()
            ->whereIn('store_number', $codes)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
