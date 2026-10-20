<?php

namespace App\Providers;

use App\Services\Breaks\WorkDayResolver;
use App\Services\Tickets\TicketAccessService;
use App\Services\Tickets\TicketQueueAccess;
use App\Services\Workbooks\WorkbookAccessService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Constructed from config here rather than reading config() inside, so
        // the resolver stays pure arithmetic that a unit test can instantiate
        // with `new WorkDayResolver(6, 'UTC')` and no booted application.
        $this->app->singleton(WorkDayResolver::class, fn () => new WorkDayResolver(
            (int) config('toolbox.work_day.cutoff_hour'),
            (string) config('toolbox.work_day.timezone'),
        ));

        // SCOPED, not singleton: both memoise per-request state - the folder
        // tree, each resource's role list, and the caller's own grants - and a
        // process-lifetime instance would carry one request's answers into the
        // next under Octane.
        //
        // They have to be shared WITHIN a request, though. Resolving a fresh
        // instance per call throws the memo away, and the grid endpoint then
        // reloads the whole folder tree and re-reads the viewer's grants three
        // times over for one page.
        $this->app->scoped(TicketAccessService::class);
        $this->app->scoped(TicketQueueAccess::class);
        $this->app->scoped(WorkbookAccessService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Rate limits, applied by ObservableThrottleRequests (the `throttle` alias).
     *
     * Every throttle runs BEFORE auth.token.store - the framework sorts it ahead
     * of the api group's SubstituteBindings - so a throttled request never costs
     * a pizzasys round-trip. That also means no user id exists yet: limits are
     * keyed on a hash of the bearer token, or the IP when there is none.
     *
     * Phase 1: the real limits are generous and the `target:` limits are the
     * numbers we expect to end on - they only log when passed. Phase 2 sets the
     * real limits from those logs and drops the targets.
     */
    private function configureRateLimiting(): void
    {
        // Every route: per token, reads and writes counted apart, plus a
        // per-IP ceiling for floods of made-up tokens (a store's staff share
        // one NAT address, hence the size).
        RateLimiter::for('api', function (Request $request) {
            $token = self::tokenKey($request);
            $kind = $request->isMethodSafe() ? 'r:' : 'w:';
            $ip = 'ip:'.$request->ip();

            return [
                Limit::perMinute($request->isMethodSafe() ? 600 : 300)->by($kind.$token),
                Limit::perMinute(5000)->by($ip),
                Limit::perMinute($request->isMethodSafe() ? 120 : 60)->by('target:'.$kind.$token),
                Limit::perMinute(1200)->by('target:'.$ip),
            ];
        });

        // Poll routes drop `throttle:api` (withoutMiddleware) - its read limit
        // is lower than this one and would cap it - so they carry the same
        // per-IP ceiling themselves.
        RateLimiter::for('api-poll', fn (Request $request) => [
            Limit::perMinute(1200)->by(self::tokenKey($request)),
            Limit::perMinute(5000)->by('ip:'.$request->ip()),
            Limit::perMinute(300)->by('target:'.self::tokenKey($request)),
        ]);

        RateLimiter::for('api-heavy', fn (Request $request) => [
            Limit::perMinute(60)->by(self::tokenKey($request)),
            Limit::perMinute(20)->by('target:m:'.self::tokenKey($request)),
            Limit::perHour(200)->by('target:h:'.self::tokenKey($request)),
        ]);

        RateLimiter::for('api-export', fn (Request $request) => [
            Limit::perMinute(10)->by('m:'.self::tokenKey($request)),
            Limit::perHour(100)->by('h:'.self::tokenKey($request)),
            Limit::perMinute(3)->by('target:m:'.self::tokenKey($request)),
            Limit::perHour(20)->by('target:h:'.self::tokenKey($request)),
            Limit::perDay(60)->by('target:d:'.self::tokenKey($request)),
        ]);

        RateLimiter::for('api-upload', fn (Request $request) => [
            Limit::perMinute(60)->by(self::tokenKey($request)),
            Limit::perMinute(20)->by('target:'.self::tokenKey($request)),
        ]);
    }

    /**
     * The caller's bearer token, hashed - never stored or logged raw - or the
     * IP when there is no token.
     */
    private static function tokenKey(Request $request): string
    {
        $token = (string) $request->bearerToken();

        return $token !== '' ? 't:'.hash('sha256', $token) : 'ip:'.$request->ip();
    }
}
