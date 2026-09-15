<?php

namespace App\Providers;

use App\Services\Breaks\WorkDayResolver;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
