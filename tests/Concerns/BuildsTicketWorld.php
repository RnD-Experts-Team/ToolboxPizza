<?php

namespace Tests\Concerns;

use App\Models\Store;
use App\Models\TicketAssignment;
use App\Models\TicketLevel;
use App\Models\TicketSection;
use App\Models\User;
use App\Models\UserStoreRole;

/**
 * Stores, users, store grants, sections, levels and assignments in one place.
 *
 * Store and UserStoreRole get no factories on purpose: they are replicated
 * models, and the house rule is that replicated rows are created explicitly
 * because production never mints them either.
 */
trait BuildsTicketWorld
{
    protected Store $store;

    protected Store $otherStore;

    protected TicketSection $section;

    protected function buildTicketWorld(): void
    {
        $this->store = Store::query()->create([
            'id' => 1, 'store_number' => '03795-00001', 'name' => 'Downtown',
        ]);

        $this->otherStore = Store::query()->create([
            'id' => 2, 'store_number' => '03795-00002', 'name' => 'Uptown',
        ]);

        $this->section = TicketSection::query()->create([
            'key' => 'hiring.page', 'name' => 'Hiring page',
        ]);
    }

    protected function makeUser(int $id, string $name = 'User'): User
    {
        return User::query()->create([
            'id' => $id, 'name' => "{$name} {$id}", 'email' => "u{$id}@example.com",
        ]);
    }

    protected function makeLevel(string $key, ?int $parentId = null): TicketLevel
    {
        return TicketLevel::query()->create([
            'key' => $key, 'name' => ucfirst($key), 'parent_id' => $parentId,
        ]);
    }

    protected function assignSection(User $user, ?TicketSection $section = null, bool $storeScoped = true): TicketAssignment
    {
        return TicketAssignment::query()->create([
            'user_id' => $user->id,
            'ticket_section_id' => ($section ?? $this->section)->id,
            'store_scoped' => $storeScoped,
        ]);
    }

    protected function assignLevel(User $user, TicketLevel $level, bool $storeScoped = true): TicketAssignment
    {
        return TicketAssignment::query()->create([
            'user_id' => $user->id,
            'ticket_level_id' => $level->id,
            'store_scoped' => $storeScoped,
        ]);
    }

    /**
     * The store CODE, never the id - that distinction is the point of the table.
     */
    protected function grantStore(User $user, ?string $storeCode = null, bool $active = true): UserStoreRole
    {
        return UserStoreRole::query()->create([
            'id' => random_int(100000, 9999999),
            'user_id' => $user->id,
            'store_id' => $storeCode ?? $this->store->store_number,
            'role_name' => 'hiring_manager',
            'active' => $active,
        ]);
    }

    /**
     * Someone the routing reaches for the default store: assigned to the
     * section, and holding that store.
     */
    protected function makeAssignee(int $id): User
    {
        $user = $this->makeUser($id, 'Assignee');
        $this->assignSection($user);
        $this->grantStore($user);

        return $user;
    }
}
