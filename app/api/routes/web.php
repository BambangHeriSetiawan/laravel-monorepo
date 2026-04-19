<?php

use App\Http\Controllers\LoadTestController;
use Illuminate\Support\Facades\Route;

// ── Health Check ──────────────────────────────────────────────────────────────
Route::get('/', fn () => view('welcome'));
Route::get('/up', fn () => response()->json(['status' => 'ok']));

// ── Load Test Endpoints (heavy DB queries) ────────────────────────────────────
// Intentionally slow endpoints used to exercise HeavyQuerySampler.
// Remove or restrict with middleware in production.
//
// To disable entirely: set LOAD_TEST_ROUTES_ENABLED=false in .env
//
if (env('LOAD_TEST_ROUTES_ENABLED', true)) {
    Route::prefix('api/loadtest')
        ->name('loadtest.')
        ->group(function () {
            // Heavy query patterns
            Route::get('full-scan',   [LoadTestController::class, 'fullScan'])->name('full-scan');
            Route::get('like-search', [LoadTestController::class, 'likeSearch'])->name('like-search');
            Route::get('n-plus-one',  [LoadTestController::class, 'nPlusOne'])->name('n-plus-one');
            Route::get('aggregate',   [LoadTestController::class, 'aggregate'])->name('aggregate');
            Route::get('subquery',    [LoadTestController::class, 'subquery'])->name('subquery');
            Route::get('sleep',       [LoadTestController::class, 'sleep'])->name('sleep');

            // Sampler introspection (for k6 assertions)
            Route::get('sampler/stats', [LoadTestController::class, 'samplerStats'])->name('sampler.stats');
            Route::get('sampler/index', [LoadTestController::class, 'samplerIndex'])->name('sampler.index');
        });
}

