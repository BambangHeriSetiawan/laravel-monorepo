<?php

namespace Simx\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

/**
 * HeavyQueryController
 *
 * Exposes a developer/ops endpoint to inspect captured heavy query samples
 * stored in the cache ring-buffer by HeavyQuerySampler.
 *
 * Routes (registered by HeavyQueryServiceProvider when debug_endpoint is enabled):
 *
 *   GET  /_debug/heavy-queries              → paged index of recent samples
 *   GET  /_debug/heavy-queries/{id}         → full detail of one sample
 *   DELETE /_debug/heavy-queries            → flush all samples from cache
 */
class HeavyQueryController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────────
    // Index — recent samples (newest first)
    // ──────────────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $prefix = config('heavy-query.cache_key_prefix', 'heavy_query');
        $store  = config('heavy-query.cache_store', null);
        $cache  = $store ? Cache::store($store) : Cache::store();

        $index = $cache->get("{$prefix}:index", []);

        $perPage = min((int) $request->query('per_page', 25), 200);
        $page    = max((int) $request->query('page', 1), 1);
        $offset  = ($page - 1) * $perPage;
        $slice   = array_slice($index, $offset, $perPage);

        // Enrich with threshold config
        $threshold = config('heavy-query.threshold_ms', 200);

        return response()->json([
            'meta' => [
                'total'        => count($index),
                'page'         => $page,
                'per_page'     => $perPage,
                'threshold_ms' => $threshold,
                'sample_rate'  => config('heavy-query.sample_rate', 1.0),
                'enabled'      => config('heavy-query.enabled', true),
            ],
            'data' => $slice,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Show — full detail for one sample
    // ──────────────────────────────────────────────────────────────────────────

    public function show(string $id): JsonResponse
    {
        $prefix = config('heavy-query.cache_key_prefix', 'heavy_query');
        $store  = config('heavy-query.cache_store', null);
        $cache  = $store ? Cache::store($store) : Cache::store();

        $sample = $cache->get("{$prefix}:sample:{$id}");

        if ($sample === null) {
            return response()->json([
                'error'   => 'Sample not found (may have expired).',
                'id'      => $id,
                'ttl_seconds' => config('heavy-query.ttl_seconds', 3600),
            ], 404);
        }

        return response()->json(['data' => $sample]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Flush — clear all captured samples
    // ──────────────────────────────────────────────────────────────────────────

    public function flush(): JsonResponse
    {
        $prefix = config('heavy-query.cache_key_prefix', 'heavy_query');
        $store  = config('heavy-query.cache_store', null);
        $cache  = $store ? Cache::store($store) : Cache::store();

        $index  = $cache->get("{$prefix}:index", []);
        $flushed = 0;

        foreach ($index as $entry) {
            $cache->forget("{$prefix}:sample:{$entry['id']}");
            $flushed++;
        }

        $cache->forget("{$prefix}:index");

        return response()->json([
            'message'       => 'Heavy query samples flushed.',
            'flushed_count' => $flushed,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Stats — aggregate summary
    // ──────────────────────────────────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        $prefix = config('heavy-query.cache_key_prefix', 'heavy_query');
        $store  = config('heavy-query.cache_store', null);
        $cache  = $store ? Cache::store($store) : Cache::store();

        $index = $cache->get("{$prefix}:index", []);

        if (empty($index)) {
            return response()->json([
                'total'       => 0,
                'threshold_ms'=> config('heavy-query.threshold_ms', 200),
                'message'     => 'No heavy queries sampled yet.',
            ]);
        }

        $durations = array_column($index, 'duration_ms');
        sort($durations);
        $count = count($durations);

        return response()->json([
            'total'        => $count,
            'threshold_ms' => config('heavy-query.threshold_ms', 200),
            'duration_ms'  => [
                'min'  => round(min($durations), 2),
                'max'  => round(max($durations), 2),
                'avg'  => round(array_sum($durations) / $count, 2),
                'p50'  => round($durations[(int) ($count * 0.50)] ?? 0, 2),
                'p90'  => round($durations[(int) ($count * 0.90)] ?? 0, 2),
                'p99'  => round($durations[(int) ($count * 0.99)] ?? 0, 2),
            ],
            'slowest_queries' => array_slice($index, 0, 5),
        ]);
    }
}
