<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable / Disable
    |--------------------------------------------------------------------------
    |
    | Set to false to completely disable sampling (e.g., in testing or CI).
    | Can also be toggled per-environment via HEAVY_QUERY_ENABLED=false.
    |
    */
    'enabled' => (bool) env('HEAVY_QUERY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Slow Query Threshold (milliseconds)
    |--------------------------------------------------------------------------
    |
    | Any query that takes longer than this value will be considered "heavy"
    | and eligible for sampling.
    |
    | Recommended values:
    |   - Development   : 100ms  (catch most N+1 and missing indexes)
    |   - Staging       : 200ms  (balance noise vs. signal)
    |   - Production    : 500ms  (only genuine bottlenecks)
    |
    */
    'threshold_ms' => (float) env('HEAVY_QUERY_THRESHOLD_MS', 200),

    /*
    |--------------------------------------------------------------------------
    | Sampling Rate
    |--------------------------------------------------------------------------
    |
    | A float between 0.0 and 1.0.
    |
    |   1.0  →  capture every heavy query (dev/staging)
    |   0.25 →  capture 25% (busy staging)
    |   0.05 →  capture 5%  (high-traffic production)
    |
    | Probabilistic sampling reduces overhead under high load while still
    | providing a statistically representative sample set.
    |
    */
    'sample_rate' => (float) env('HEAVY_QUERY_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Maximum Samples Retained
    |--------------------------------------------------------------------------
    |
    | The ring-buffer size. Once this limit is reached, the oldest sample
    | is evicted (FIFO) to make room for the newest one.
    |
    */
    'max_samples' => (int) env('HEAVY_QUERY_MAX_SAMPLES', 500),

    /*
    |--------------------------------------------------------------------------
    | Sample TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | How long each captured sample persists in the cache before being
    | automatically evicted. Default: 1 hour (3600s).
    |
    */
    'ttl_seconds' => (int) env('HEAVY_QUERY_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | Which Laravel cache store to use for persisting samples.
    | Leave null to use the application's default cache driver.
    |
    | For multi-pod Kubernetes deployments, always use 'redis' so that
    | samples from any pod are visible in a single store.
    |
    */
    'cache_store' => env('HEAVY_QUERY_CACHE_STORE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix applied to all keys written to the cache.
    | Override per-app if you share a single Redis instance between apps.
    |
    */
    'cache_key_prefix' => env('HEAVY_QUERY_CACHE_PREFIX', 'heavy_query'),

    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    |
    | Laravel log channel to write structured warnings to.
    | Use 'stderr' for Kubernetes (picked up by kubectl logs).
    | Use 'daily' or 'single' for local file logging.
    |
    */
    'log_channel' => env('HEAVY_QUERY_LOG_CHANNEL', 'stderr'),

    /*
    |--------------------------------------------------------------------------
    | Ignored URL Paths
    |--------------------------------------------------------------------------
    |
    | A list of URL path patterns (supports * wildcards) that will NOT be
    | sampled. Useful to suppress noise from health-checks, metrics endpoints,
    | and internal housekeeping routes.
    |
    */
    'ignore_paths' => array_filter(
        explode(',', (string) env('HEAVY_QUERY_IGNORE_PATHS', 'up,health,_debugbar/*,telescope/*'))
    ),

    /*
    |--------------------------------------------------------------------------
    | Debug HTTP Endpoint
    |--------------------------------------------------------------------------
    |
    | When enabled, registers a set of routes to inspect captured samples:
    |
    |   GET    /{prefix}/heavy-queries          → paged index (newest first)
    |   GET    /{prefix}/heavy-queries/stats    → aggregate stats (min/max/p99)
    |   GET    /{prefix}/heavy-queries/{id}     → full detail with backtrace
    |   DELETE /{prefix}/heavy-queries          → flush ring-buffer
    |
    | ⚠️  Never enable in production without IP-restriction middleware.
    |
    */
    'debug_endpoint' => (bool) env('HEAVY_QUERY_DEBUG_ENDPOINT', false),

    /*
    |--------------------------------------------------------------------------
    | Debug Route Prefix
    |--------------------------------------------------------------------------
    |
    | URL prefix for the debug endpoints. Defaults to "_debug".
    |
    */
    'debug_route_prefix' => env('HEAVY_QUERY_DEBUG_PREFIX', '_debug'),

    /*
    |--------------------------------------------------------------------------
    | Debug Route Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to the debug routes.
    | Use 'auth' or a custom IP-restriction middleware in production.
    |
    */
    'debug_middleware' => explode(',', (string) env('HEAVY_QUERY_DEBUG_MIDDLEWARE', 'web')),

];
