<?php

namespace Tests\Feature\EventConsume;

use App\Models\User;
use App\Services\EventConsume\EventRouter;
use App\Services\EventConsume\Handlers\UserCreatedHandler;
use App\Services\EventConsume\Handlers\UserDeletedHandler;
use App\Services\EventConsume\Handlers\UserUpdatedHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Users are not created by this service — they arrive over auth.v1.user.*.
 * Until one has replicated, the middleware answers 401 'user not synced yet',
 * so this path is a hard prerequisite for every endpoint.
 */
class UserReplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_created_event_replicates_the_user_by_its_pizzasys_id(): void
    {
        app(UserCreatedHandler::class)->handle([
            'type' => 'auth.v1.user.created',
            'data' => ['user' => ['id' => 4321, 'name' => 'Dana Whitfield', 'email' => 'dana@example.com']],
        ]);

        $this->assertDatabaseHas('users', [
            'id' => 4321,
            'name' => 'Dana Whitfield',
            'email' => 'dana@example.com',
        ]);
    }

    public function test_replaying_a_created_event_is_idempotent(): void
    {
        $event = [
            'type' => 'auth.v1.user.created',
            'data' => ['user' => ['id' => 4321, 'name' => 'Dana Whitfield', 'email' => 'dana@example.com']],
        ];

        app(UserCreatedHandler::class)->handle($event);
        app(UserCreatedHandler::class)->handle($event);

        $this->assertSame(1, User::query()->count());
    }

    public function test_a_created_event_without_a_usable_id_is_rejected(): void
    {
        $this->expectExceptionMessage('missing/invalid user.id');

        app(UserCreatedHandler::class)->handle([
            'data' => ['user' => ['name' => 'Nobody', 'email' => 'nobody@example.com']],
        ]);
    }

    public function test_an_updated_event_applies_the_changed_fields(): void
    {
        User::query()->create(['id' => 4321, 'name' => 'Dana Whitfield', 'email' => 'dana@example.com']);

        app(UserUpdatedHandler::class)->handle([
            'data' => [
                'user_id' => 4321,
                'changed_fields' => ['name' => ['from' => 'Dana Whitfield', 'to' => 'Dana W.']],
            ],
        ]);

        $this->assertDatabaseHas('users', ['id' => 4321, 'name' => 'Dana W.', 'email' => 'dana@example.com']);
    }

    public function test_an_updated_event_never_creates_a_user(): void
    {
        $this->expectExceptionMessage('not synced yet');

        app(UserUpdatedHandler::class)->handle([
            'data' => ['user_id' => 999, 'changed_fields' => ['name' => ['from' => 'a', 'to' => 'b']]],
        ]);
    }

    /**
     * Deliberate: break_entries cascades on user delete, so removing the row
     * would destroy a user's break history mid-retention-window. Access is
     * unaffected — pizzasys stops issuing tokens for a deleted user.
     */
    public function test_a_deleted_event_keeps_the_local_row_for_authorship(): void
    {
        User::query()->create(['id' => 4321, 'name' => 'Dana Whitfield', 'email' => 'dana@example.com']);

        app(UserDeletedHandler::class)->handle(['data' => ['user_id' => 4321]]);

        $this->assertDatabaseHas('users', ['id' => 4321]);
    }

    public function test_the_router_maps_the_user_subjects(): void
    {
        $map = app(EventRouter::class)->getResolvedMap();

        foreach (['created', 'updated', 'deleted'] as $verb) {
            $this->assertArrayHasKey("auth.v1.user.{$verb}", $map);
        }
    }

    public function test_the_router_refuses_a_subject_it_has_no_handler_for(): void
    {
        // Stores and role assignments ARE handled now, so this needs a subject
        // that genuinely has no handler.
        $this->expectExceptionMessage("No handler for subject 'hiring.v1.employee.created'");

        app(EventRouter::class)->resolve('hiring.v1.employee.created');
    }
}
