<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTicketWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The whole API sits behind this middleware, so it is tested against the
 * trivial health route: an auth regression fails here rather than somewhere
 * inside a domain test.
 */
class AuthTokenStoreScopeMiddlewareTest extends TestCase
{
    use BuildsTicketWorld, FakesAuthServer, RefreshDatabase;

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

    /**
     * The store context is an authorization question, and no authorization
     * decision is ever made on a file's bytes.
     *
     * The estate's copy excludes `files` BY NAME at the top level, which misses
     * notes[0][files][] - and a file object that reaches the JSON encode makes
     * the call throw. verifyWithAuthServer catches that and reports
     * `active => false`, so the caller gets a 401 that has nothing to do with
     * their token. Asserting on the outbound payload rather than only on the
     * status code, because the status code alone would not say why.
     */
    public function test_uploaded_files_are_stripped_from_the_store_context_at_every_depth(): void
    {
        $this->buildTicketWorld();
        $this->grantStore($this->authUser);

        config([
            'toolbox.tickets.notifications.enabled' => false,
            'toolbox.tickets.realtime.enabled' => false,
        ]);

        Storage::fake('public');

        $this->post("/api/v1/stores/{$this->store->store_number}/tickets", [
            'section_key' => $this->section->key,
            'title' => 'With attachments',
            'description' => 'Files at two depths.',
            'files' => [UploadedFile::fake()->image('top.jpg')],
            'notes' => [['body' => 'A note', 'files' => [UploadedFile::fake()->image('nested.jpg')]]],
        ], $this->headers())->assertCreated();

        Http::assertSent(function (Request $request) {
            $body = $request->data()['store_context']['body'];

            $this->assertArrayNotHasKey('files', $body);
            $this->assertSame('A note', $body['notes'][0]['body']);
            $this->assertSame([], $body['notes'][0]['files'] ?? []);

            // The fields authorization might actually care about survive.
            $this->assertSame('With attachments', $body['title']);

            return true;
        });
    }
}
