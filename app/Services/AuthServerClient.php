<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * The one call to pizzasys' token-verify endpoint: "may THIS token do THIS
 * method on THIS path, given this store context?".
 *
 * Shared by the auth middleware (the request being served) and by the ticket
 * queue check (a question about a DIFFERENT path - may the caller list a
 * store's tickets - asked on behalf of the request being served).
 *
 * pizzasys answers 200 for every token it could judge, valid or not. Anything
 * else - a 429, a 5xx, a timeout - means it could not judge, and comes back as
 * ['active' => false, 'unavailable' => true]: callers that only ask "yes or
 * no?" still get "no", and the middleware turns it into a 503 rather than a
 * 401, so a busy auth server does not log people out.
 */
class AuthServerClient
{
    /**
     * @param  array<string, mixed>  $storeContext  {path, query, body, header}
     * @return array<string, mixed>
     */
    public function verify(string $userToken, string $method, string $path, ?string $routeName, array $storeContext): array
    {
        $cfg = (array) config('services.auth_server', []);

        $endpoint = rtrim((string) ($cfg['base_url'] ?? ''), '/').'/'.ltrim((string) ($cfg['verify_path'] ?? ''), '/');
        $retries = (int) ($cfg['retries'] ?? 1);

        $payload = [
            'service' => (string) ($cfg['service_name'] ?? ''),
            'token' => $userToken,
            'method' => strtoupper($method),
            'path' => '/'.ltrim($path, '/'),
            'route_name' => (string) ($routeName ?? ''),
            'store_context' => $storeContext,
        ];

        $unavailable = ['active' => false, 'unavailable' => true];

        try {
            $http = Http::timeout((int) ($cfg['timeout'] ?? 3))
                ->acceptJson()
                ->withToken((string) ($cfg['call_token'] ?? ''));

            if ($retries > 0) {
                // Never retry a 429: pizzasys is asking us to back off.
                $http = $http->retry(
                    $retries,
                    (int) ($cfg['retry_ms'] ?? 100),
                    fn ($e) => ! ($e instanceof RequestException && $e->response->status() === 429),
                    throw: false,
                );
            }

            $resp = $http->post($endpoint, $payload);

            if (! $resp->ok()) {
                return $unavailable;
            }

            $data = $resp->json();

            return is_array($data) ? $data : $unavailable;
        } catch (\Throwable $e) {
            return $unavailable;
        }
    }
}
