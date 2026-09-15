<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The whole API sits behind this middleware, so it is tested against the
 * trivial health route: an auth regression fails here rather than somewhere
 * inside a domain test.
 */
class AuthTokenStoreScopeMiddlewareTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
    }

    public function test_a_verified_token_resolves_to_the_replicated_user(): void
    {
        $this->getJson('/api/v1/health', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.service', 'Toolbox')
            ->assertJsonPath('data.user_id', 9);
    }

    public function test_a_request_without_a_bearer_token_is_rejected(): void
    {
        $this->getJson('/api/v1/health', ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    public function test_an_inactive_token_is_rejected(): void
    {
        $this->tokenActive = false;

        $this->getJson('/api/v1/health', $this->headers())->assertStatus(401);
    }

    public function test_a_token_the_auth_server_did_not_authorize_is_forbidden(): void
    {
        $this->tokenAuthorized = false;

        $this->getJson('/api/v1/health', $this->headers())->assertStatus(403);
    }

    public function test_a_token_for_a_user_that_has_not_replicated_yet_is_rejected(): void
    {
        User::query()->whereKey(9)->delete();

        $this->getJson('/api/v1/health', $this->headers())->assertStatus(401);
    }

    /**
     * Employee ids come from the hiring system and are a DIFFERENT id space, so
     * an employee token whose subject id collides with a users.id must never be
     * allowed to resolve against the users table.
     */
    public function test_an_employee_subject_token_is_refused_outright(): void
    {
        $this->tokenSubjectType = 'employee';

        $this->getJson('/api/v1/health', $this->headers())->assertStatus(403);
    }
}
