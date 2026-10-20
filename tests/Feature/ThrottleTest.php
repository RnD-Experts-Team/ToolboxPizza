<?php

namespace Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * ObservableThrottleRequests and the limits in AppServiceProvider.
 *
 * The real limits are far too big to walk past in a test, so most cases swap
 * in a tiny limiter under the same name; the last few check the real numbers.
 */
class ThrottleTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    private const PROBE = '/api/v1/break-types';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
    }

    private function tinyApiLimiter(int $real, int $target = 100): void
    {
        RateLimiter::for('api', fn (Request $request) => [
            Limit::perMinute($real)->by('t:'.$request->bearerToken()),
            Limit::perMinute($target)->by('target:t:'.$request->bearerToken()),
        ]);
    }

    public function test_passing_a_target_logs_once_and_blocks_nothing(): void
    {
        Log::spy();
        Log::shouldReceive('channel')->with('throttle')->andReturnSelf();
        $this->tinyApiLimiter(real: 100, target: 2);

        foreach (range(1, 5) as $_) {
            $this->getJson(self::PROBE, $this->headers())->assertOk();
        }

        Log::shouldHaveReceived('warning')
            ->with('throttle.usage', Mockery::on(fn ($ctx) => $ctx['limiter'] === 'api' && $ctx['max'] === 2))
            ->once();
    }

    public function test_throttle_lines_go_to_throttle_log_not_laravel_log(): void
    {
        $file = storage_path('logs/throttle-test-'.uniqid().'.log');
        config(['logging.channels.throttle.path' => $file]);
        $this->tinyApiLimiter(real: 100, target: 1);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertOk();

        try {
            $this->assertFileExists($file);
            $this->assertStringContainsString('throttle.usage', (string) file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }

    public function test_record_only_mode_lets_a_breach_through_and_logs_it_once(): void
    {
        config(['app.throttle_enforce' => false]);
        Log::spy();
        Log::shouldReceive('channel')->with('throttle')->andReturnSelf();
        $this->tinyApiLimiter(real: 2);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertOk();

        foreach (range(1, 3) as $_) {
            $this->getJson(self::PROBE, $this->headers())
                ->assertOk()
                ->assertHeader('X-RateLimit-Remaining', '0');
        }

        Log::shouldHaveReceived('warning')
            ->with('throttle.breach', Mockery::on(fn ($ctx) => $ctx['enforced'] === false))
            ->once();
    }

    public function test_enforce_mode_answers_429_with_retry_after(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 2);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertOk();

        $this->getJson(self::PROBE, $this->headers())
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_a_throttled_request_never_reaches_pizzasys(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 1);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $sent = count(Http::recorded());

        $this->getJson(self::PROBE, $this->headers())->assertStatus(429);

        $this->assertCount($sent, Http::recorded());
    }

    public function test_a_route_tier_also_runs_before_pizzasys(): void
    {
        config(['app.throttle_enforce' => true]);
        RateLimiter::for('api-poll', fn (Request $request) => Limit::perMinute(1)->by('t:'.$request->bearerToken()));

        $this->getJson('/api/v1/breaks/active', $this->headers())->assertOk();
        $sent = count(Http::recorded());

        $this->getJson('/api/v1/breaks/active', $this->headers())->assertStatus(429);

        $this->assertCount($sent, Http::recorded());
    }

    public function test_a_poll_route_is_not_capped_by_the_general_limit(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 1);

        foreach (range(1, 3) as $_) {
            $this->getJson('/api/v1/breaks/active', $this->headers())->assertOk();
        }

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertStatus(429);
    }

    public function test_two_tokens_do_not_share_a_bucket(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 1);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertStatus(429);

        $this->getJson(self::PROBE, ['Authorization' => 'Bearer 2|other-token', 'Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_an_unreachable_limiter_store_serves_the_request_unthrottled(): void
    {
        config(['app.throttle_enforce' => true]);

        $down = new class extends ArrayStore
        {
            public function get($key)
            {
                throw new \RuntimeException('redis down');
            }

            public function increment($key, $value = 1)
            {
                throw new \RuntimeException('redis down');
            }

            public function put($key, $value, $seconds)
            {
                throw new \RuntimeException('redis down');
            }
        };

        $limiter = new CacheRateLimiter(new Repository($down));
        $limiter->for('api', fn (Request $request) => Limit::perMinute(1)->by('t:'.$request->bearerToken()));
        $this->app->instance(CacheRateLimiter::class, $limiter);

        Log::spy();
        Log::shouldReceive('channel')->with('throttle')->andReturnSelf();

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertOk();

        Log::shouldHaveReceived('error')->with('throttle.store_unavailable', Mockery::any())->twice();
    }

    public function test_the_real_api_limits_split_reads_from_writes(): void
    {
        $limits = fn (string $method) => collect(RateLimiter::limiter('api')(
            Request::create('/api/v1/break-types', $method, server: ['HTTP_AUTHORIZATION' => 'Bearer 1|test-token'])
        ));

        $real = fn ($all) => $all->reject(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all();
        $target = fn ($all) => $all->filter(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all();

        $this->assertSame([600, 5000], $real($limits('GET')));
        $this->assertSame([120, 1200], $target($limits('GET')));
        $this->assertSame([300, 5000], $real($limits('POST')));
        $this->assertSame([60, 1200], $target($limits('POST')));
    }

    public function test_keys_hash_the_token_and_fall_back_to_the_ip(): void
    {
        $key = fn (array $server) => RateLimiter::limiter('api-upload')(Request::create('/x', 'POST', server: $server))[0]->key;

        $a = $key(['HTTP_AUTHORIZATION' => 'Bearer 1|aaa']);

        $this->assertSame($a, $key(['HTTP_AUTHORIZATION' => 'Bearer 1|aaa']));
        $this->assertNotSame($a, $key(['HTTP_AUTHORIZATION' => 'Bearer 1|bbb']));
        $this->assertStringNotContainsString('aaa', $a);
        $this->assertSame('ip:10.1.2.3', $key(['REMOTE_ADDR' => '10.1.2.3']));
    }
}
