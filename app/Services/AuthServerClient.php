<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * The one call to pizzasys' token-verify endpoint: "may THIS token do THIS
 * method on THIS path, given this store context?".
 *
 * Shared by the auth middleware (the request being served) and by the ticket
 * visibility check (a question about a DIFFERENT path - may the caller list a
 * store's tickets - asked on behalf of the request being served).
 *
 * Any failure answers ['active' => false]: callers treat that as "no".
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

        try {
            $http = Http::timeout((int) ($cfg['timeout'] ?? 3))
                ->acceptJson()
                ->withToken((string) ($cfg['call_token'] ?? ''));

            if ($retries > 0) {
                $http = $http->retry($retries, (int) ($cfg['retry_ms'] ?? 100));
            }

            $resp = $http->post($endpoint, $payload);

            if (! $resp->ok()) {
                return ['active' => false];
            }

            $data = $resp->json();

            return is_array($data) ? $data : ['active' => false];
        } catch (\Throwable $e) {
            return ['active' => false];
        }
    }
}
