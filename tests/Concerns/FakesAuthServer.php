<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Stands in for pizzasys. This service has no local login: every request goes
 * through AuthTokenStoreScopeMiddleware, which POSTs the caller's token to the
 * auth server. Faking that one call exercises the real middleware chain rather
 * than bypassing it, which is why tests do NOT use actingAs().
 */
trait FakesAuthServer
{
    protected User $authUser;

    protected bool $tokenActive = true;

    protected bool $tokenAuthorized = true;

    protected string $tokenSubjectType = 'user';

    /** @var array<int, string> */
    protected array $tokenRoles = [];

    /** @var array<int, string> */
    protected array $tokenPermissions = [];

    /**
     * stores.id values where the caller holds `view tickets` through a STORE
     * role: not in `permissions`, only visible to pizzasys' per-store check.
     *
     * @var array<int, int>
     */
    protected array $tokenQueueStoreIds = [];

    /**
     * Registered ONCE per test. Http::fake() MERGES stubs and the first match
     * wins, so re-faking mid-test would silently keep the original response —
     * flip the $token* properties instead. preventStrayRequests() means any
     * unmatched request fails loudly rather than escaping to the network.
     */
    protected function fakeAuthServer(?User $user = null): User
    {
        config([
            'services.auth_server.base_url' => 'http://auth.test',
            'services.auth_server.verify_path' => '/api/v1/auth/token/verify',
            'services.auth_server.service_name' => 'Toolbox',
            'services.auth_server.call_token' => 'service-token',
        ]);

        // Created explicitly, not via a factory: replicated models carry no
        // HasFactory in this house, because their rows arrive over NATS with
        // the source service's primary key rather than being minted locally.
        $this->authUser = $user ?? User::query()->create([
            'id' => 9,
            'name' => 'Dana Whitfield',
            'email' => 'dana@example.com',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'auth.test/*' => function ($request) {
                $ext = ['authorized' => $this->tokenAuthorized];

                // TicketQueueAccess asking "may the caller list these stores'
                // tickets?" (the list route, with `stores` in the query).
                // Answer the way pizzasys does for a scoped rule: one verdict
                // per store, true only for those the test says hold the
                // permission.
                $asked = data_get($request->data(), 'store_context.query.stores');

                if (is_array($asked) && data_get($request->data(), 'route_name') === 'api.v1.stores.tickets.index') {
                    $perStore = [];
                    foreach ($asked as $id) {
                        $perStore[(int) $id] = in_array((int) $id, $this->tokenQueueStoreIds, true);
                    }

                    $ext = [
                        'authorized' => ! in_array(false, $perStore, true),
                        'store' => ['store_mode' => 'scoped', 'per_store' => $perStore],
                    ];
                }

                return Http::response([
                    'active' => $this->tokenActive,
                    'subject_type' => $this->tokenSubjectType,
                    'user' => [
                        'id' => $this->authUser->id,
                        'name' => $this->authUser->name,
                        'email' => $this->authUser->email,
                    ],
                    'roles' => $this->tokenRoles,
                    'permissions' => $this->tokenPermissions,
                    'ext' => $ext,
                ]);
            },
        ]);

        return $this->authUser;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return ['Authorization' => 'Bearer 1|test-token', 'Accept' => 'application/json'];
    }
}
