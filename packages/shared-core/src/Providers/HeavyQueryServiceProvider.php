<?php

namespace Simx\Core\Providers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Simx\Core\Database\HeavyQuerySampler;
use Simx\Core\Http\Controllers\HeavyQueryController;

/**
 * HeavyQueryServiceProvider
 *
 * Auto-discovered by Laravel via composer.json extra.laravel.providers.
 * Registers the HeavyQuerySampler as a singleton and wires it up to
 * Laravel's DB query event.
 *
 * Configuration is loaded from config/heavy-query.php, which each app
 * can publish and override independently:
 *
 *   php artisan vendor:publish --tag=heavy-query-config
 *
 * Debug routes (enabled when heavy-query.debug_endpoint is true):
 *
 *   GET    /_debug/heavy-queries          → paged index
 *   GET    /_debug/heavy-queries/stats    → aggregate stats
 *   GET    /_debug/heavy-queries/{id}     → full sample detail
 *   DELETE /_debug/heavy-queries          → flush all samples
 */
class HeavyQueryServiceProvider extends ServiceProvider
{
    /**
     * Register the sampler singleton.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/heavy-query.php',
            'heavy-query'
        );

        $this->app->singleton(HeavyQuerySampler::class, function ($app) {
            return new HeavyQuerySampler(
                $app['config']->get('heavy-query', [])
            );
        });
    }

    /**
     * Boot the DB listener and (optionally) the debug routes.
     */
    public function boot(): void
    {
        // Publish config so each app can override defaults
        $this->publishes([
            __DIR__.'/../../config/heavy-query.php' => config_path('heavy-query.php'),
        ], 'heavy-query-config');

        if (! config('heavy-query.enabled', true)) {
            return;
        }

        // ── DB Listener ────────────────────────────────────────────────────────
        $sampler = $this->app->make(HeavyQuerySampler::class);

        DB::listen(function (QueryExecuted $event) use ($sampler): void {
            $sampler->handle($event);
        });

        // ── Debug Endpoint ─────────────────────────────────────────────────────
        // Only register debug routes when explicitly enabled.
        // NEVER enable in production without IP-restriction middleware.
        if (config('heavy-query.debug_endpoint', false)) {
            $this->registerDebugRoutes();
        }
    }

    /**
     * Register the debug API routes under /_debug/heavy-queries.
     */
    private function registerDebugRoutes(): void
    {
        $prefix     = config('heavy-query.debug_route_prefix', '_debug');
        $middleware = config('heavy-query.debug_middleware', ['web']);

        Route::middleware($middleware)
            ->prefix($prefix)
            ->name('heavy-query.')
            ->group(function () {
                Route::get('heavy-queries',         [HeavyQueryController::class, 'index'])->name('index');
                Route::get('heavy-queries/stats',   [HeavyQueryController::class, 'stats'])->name('stats');
                Route::get('heavy-queries/{id}',    [HeavyQueryController::class, 'show'])->name('show');
                Route::delete('heavy-queries',      [HeavyQueryController::class, 'flush'])->name('flush');
            });
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [HeavyQuerySampler::class];
    }
}
