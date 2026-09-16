<?php

namespace Tests\Feature\EventConsume;

use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\EventConsume\Handlers\UserStoreRoleAssignedHandler;
use App\Services\EventConsume\Handlers\UserStoreRoleBulkAssignedHandler;
use App\Services\EventConsume\Handlers\UserStoreRoleRemovedHandler;
use App\Services\EventConsume\Handlers\UserStoreRoleToggledHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which stores a user can reach. This is what ticket routing consults to decide
 * whether a store-scoped assignee actually receives a ticket, so a bug here is
 * silent: the person is simply never on the list.
 */
class UserStoreRoleReplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::query()->create(['id' => 9, 'name' => 'Dana', 'email' => 'dana@example.com']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function assignment(array $overrides = []): array
    {
        return array_merge([
            'id' => 5001,
            'user_id' => 9,
            'role_id' => 3,
            'role_name' => 'hiring_manager',
            'store_id' => 42,              // the integer pk - deliberately NOT what we store
            'store_lc_id' => '03795-00001', // the CODE - this is what we store
            'is_active' => true,
        ], $overrides);
    }

    /**
     * The whole point: we keep the CODE, because that is what a ticket route and
     * a notification target speak in. Storing the pk would silently match
     * nothing later.
     */
    public function test_an_assignment_stores_the_store_code_not_the_pk(): void
    {
        app(UserStoreRoleAssignedHandler::class)->handle(['data' => ['assignment' => $this->assignment()]]);

        $this->assertDatabaseHas('user_store_roles', [
            'id' => 5001,
            'user_id' => 9,
            'store_id' => '03795-00001',
            'role_name' => 'hiring_manager',
            'active' => true,
        ]);
    }

    public function test_an_assignment_without_a_store_code_is_stored_as_all_stores(): void
    {
        app(UserStoreRoleAssignedHandler::class)->handle([
            'data' => ['assignment' => $this->assignment(['store_lc_id' => null])],
        ]);

        $this->assertDatabaseHas('user_store_roles', ['id' => 5001, 'store_id' => 'all']);
    }

    public function test_an_assignment_without_a_role_name_falls_back_to_the_role_id(): void
    {
        app(UserStoreRoleAssignedHandler::class)->handle([
            'data' => ['assignment' => $this->assignment(['role_name' => null])],
        ]);

        $this->assertDatabaseHas('user_store_roles', ['id' => 5001, 'role_name' => 'role_id_3']);
    }

    public function test_replaying_an_assignment_is_idempotent(): void
    {
        $event = ['data' => ['assignment' => $this->assignment()]];

        app(UserStoreRoleAssignedHandler::class)->handle($event);
        app(UserStoreRoleAssignedHandler::class)->handle($event);

        $this->assertSame(1, UserStoreRole::query()->count());
    }

    public function test_an_assignment_for_an_unsynced_user_is_rejected(): void
    {
        $this->expectExceptionMessage('not synced yet');

        app(UserStoreRoleAssignedHandler::class)->handle([
            'data' => ['assignment' => $this->assignment(['user_id' => 999])],
        ]);
    }

    /**
     * THE BUG THIS FIX EXISTS FOR.
     *
     * pizzasys emits one user_id at data.user_id and rows that do not repeat it.
     * NotificationsPizza forwards each row untouched to the single handler,
     * which then cannot find a user_id and throws - so every bulk assignment in
     * the estate currently fails outright.
     */
    public function test_a_bulk_assignment_takes_the_user_id_from_the_envelope(): void
    {
        app(UserStoreRoleBulkAssignedHandler::class)->handle([
            'data' => [
                'user_id' => 9,
                'count' => 2,
                'assignments' => [
                    // Note: no user_id on the rows. This is pizzasys' real shape.
                    ['id' => 6001, 'role_id' => 3, 'role_name' => 'hiring_manager', 'store_lc_id' => '03795-00001', 'is_active' => true],
                    ['id' => 6002, 'role_id' => 3, 'role_name' => 'hiring_manager', 'store_lc_id' => '03795-00002', 'is_active' => true],
                ],
            ],
        ]);

        $this->assertSame(2, UserStoreRole::query()->where('user_id', 9)->count());
        $this->assertDatabaseHas('user_store_roles', ['id' => 6001, 'store_id' => '03795-00001']);
        $this->assertDatabaseHas('user_store_roles', ['id' => 6002, 'store_id' => '03795-00002']);
    }

    public function test_a_bulk_assignment_without_a_user_id_is_rejected(): void
    {
        $this->expectExceptionMessage('missing/invalid data.user_id');

        app(UserStoreRoleBulkAssignedHandler::class)->handle([
            'data' => ['assignments' => [['id' => 6001, 'role_id' => 3]]],
        ]);
    }

    public function test_toggling_flips_the_active_flag(): void
    {
        app(UserStoreRoleAssignedHandler::class)->handle(['data' => ['assignment' => $this->assignment()]]);

        app(UserStoreRoleToggledHandler::class)->handle([
            'data' => ['assignment_id' => 5001, 'after_is_active' => false],
        ]);

        $this->assertDatabaseHas('user_store_roles', ['id' => 5001, 'active' => false]);
    }

    public function test_removal_deletes_by_assignment_id(): void
    {
        app(UserStoreRoleAssignedHandler::class)->handle(['data' => ['assignment' => $this->assignment()]]);

        app(UserStoreRoleRemovedHandler::class)->handle(['data' => ['assignment_id' => 5001]]);

        $this->assertDatabaseCount('user_store_roles', 0);
    }

    public function test_removing_an_already_removed_assignment_succeeds(): void
    {
        app(UserStoreRoleRemovedHandler::class)->handle(['data' => ['assignment_id' => 5001]]);

        $this->assertDatabaseCount('user_store_roles', 0);
    }

    /**
     * The estate's copy carries a fallback targeting (user_id, role_id,
     * store_id) that cannot ever match - it compares an integer to the code
     * column and checks NULL where 'all' is written. A branch that looks like
     * cleanup and silently does nothing is worse than no branch.
     */
    public function test_removal_without_an_assignment_id_is_rejected_rather_than_silently_matching_nothing(): void
    {
        $this->expectExceptionMessage('missing/invalid assignment_id');

        app(UserStoreRoleRemovedHandler::class)->handle([
            'data' => ['user_id' => 9, 'role_id' => 3, 'store_id' => 42],
        ]);
    }

    public function test_the_covering_store_scope_accepts_the_code_all_and_null(): void
    {
        UserStoreRole::query()->create(['id' => 1, 'user_id' => 9, 'store_id' => '03795-00001', 'role_name' => 'r', 'active' => true]);
        UserStoreRole::query()->create(['id' => 2, 'user_id' => 9, 'store_id' => 'all', 'role_name' => 'r2', 'active' => true]);
        UserStoreRole::query()->create(['id' => 3, 'user_id' => 9, 'store_id' => null, 'role_name' => 'r3', 'active' => true]);
        UserStoreRole::query()->create(['id' => 4, 'user_id' => 9, 'store_id' => '03795-00009', 'role_name' => 'r4', 'active' => true]);

        $ids = UserStoreRole::query()->coveringStore('03795-00001')->pluck('id')->all();

        $this->assertEqualsCanonicalizing([1, 2, 3], $ids);
    }
}
