<?php

namespace App\Providers;

use App\Services\Breaks\WorkDayResolver;
use App\Services\Tickets\TicketAccessService;
use App\Services\Workbooks\WorkbookAccessService;
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
        $this->app->scoped(WorkbookAccessService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
