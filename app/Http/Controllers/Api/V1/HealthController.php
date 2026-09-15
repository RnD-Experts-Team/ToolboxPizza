<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    /**
     * Liveness, plus confirmation that the caller's token resolved to a local
     * user. Deliberately trivial: this is the route the middleware tests point
     * at, so an auth regression fails here rather than inside a domain test.
     *
     * @return array<string, mixed>
     */
    public function __invoke(Request $request): array
    {
        return ['data' => [
            'status' => 'ok',
            'service' => (string) config('services.auth_server.service_name'),
            'user_id' => $request->user()?->id,
            'time' => now()->utc()->toIso8601String(),
        ]];
    }
}
