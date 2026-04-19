<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * LoadTestController
 *
 * Exposes endpoints that deliberately exercise different categories of
 * expensive database queries so the HeavyQuerySampler can capture and
 * record them. Each endpoint is designed to be:
 *
 *  1. Realistic (similar to real production queries)
 *  2. Measurably slow (50ms–2000ms depending on data volume)
 *  3. Named so Grafana / sampler logs identify the pattern
 *
 * Routes (registered in routes/api.php):
 *
 *  GET  /api/loadtest/full-scan          — full table scan on un-indexed column
 *  GET  /api/loadtest/like-search        — LIKE '%term%' on large TEXT column
 *  GET  /api/loadtest/n-plus-one         — classic N+1: posts + per-post comments
 *  GET  /api/loadtest/aggregate          — GROUP BY + SUM + ORDER BY on 10k rows
 *  GET  /api/loadtest/subquery           — correlated subquery for ranking
 *  GET  /api/loadtest/sleep              — explicit SLEEP(n) to control duration
 *  GET  /api/loadtest/sampler-stats      — proxy to HeavyQuerySampler ring-buffer stats
 *  GET  /api/loadtest/sampler-index      — proxy to HeavyQuerySampler recent samples
 */
class LoadTestController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────────
    // 1. Full Table Scan — WHERE on un-indexed column
    //
    // Pattern: SELECT ... WHERE status = ? ORDER BY view_count DESC
    // Why slow: `status` has no index → MySQL/SQLite does a sequential scan
    //           of all 10k rows, then sorts.
    // Typical: 80–400ms on 10k rows
    // ──────────────────────────────────────────────────────────────────────────
    public function fullScan(Request $request): JsonResponse
    {
        $status = $request->query('status', 'published');
        $limit  = min((int) $request->query('limit', 50), 500);

        $rows = DB::table('load_test_posts')
            ->select([
                'id', 'user_id', 'title', 'status',
                'view_count', 'comment_count', 'score', 'created_at',
            ])
            ->where('status', $status)
            ->orderByDesc('view_count')
            ->limit($limit)
            ->get();

        return response()->json([
            'query_type' => 'full_table_scan',
            'filter'     => ['status' => $status],
            'count'      => $rows->count(),
            'data'       => $rows,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 2. LIKE Search — wildcard on TEXT column
    //
    // Pattern: SELECT ... WHERE body LIKE '%term%'
    // Why slow: Leading wildcard disables index usage entirely.
    //           Full scan + string comparison on every row's TEXT body.
    // Typical: 150–800ms on 10k rows
    // ──────────────────────────────────────────────────────────────────────────
    public function likeSearch(Request $request): JsonResponse
    {
        $term  = $request->query('q', 'lorem');
        $limit = min((int) $request->query('limit', 25), 100);

        $rows = DB::table('load_test_posts')
            ->select(['id', 'title', 'status', 'view_count', 'created_at'])
            ->where('body', 'LIKE', "%{$term}%")
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'query_type' => 'like_search',
            'term'       => $term,
            'count'      => $rows->count(),
            'data'       => $rows,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 3. N+1 Query — posts then per-post comment lookup
    //
    // Pattern: SELECT posts LIMIT n, then for EACH post: SELECT comments WHERE...
    // Why slow: n+1 round-trips to the database. With n=20 → 21 queries.
    //           Demonstrates classic ORM anti-pattern.
    // Typical: 200ms–1500ms depending on n
    // ──────────────────────────────────────────────────────────────────────────
    public function nPlusOne(Request $request): JsonResponse
    {
        $n = min((int) $request->query('n', 20), 100);

        // Deliberately NOT using ->with('comments') to trigger N+1
        $posts = DB::table('load_test_posts')
            ->select(['id', 'title', 'status', 'user_id', 'view_count'])
            ->where('status', 'published')
            ->orderByDesc('view_count')
            ->limit($n)
            ->get();

        // N additional queries — one per post
        $result = $posts->map(function ($post) {
            $comments = DB::table('load_test_comments')
                ->select(['id', 'user_id', 'body', 'created_at'])
                ->where('post_id', $post->id)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();

            return array_merge((array) $post, ['recent_comments' => $comments]);
        });

        return response()->json([
            'query_type'   => 'n_plus_one',
            'n'            => $n,
            'total_queries'=> $n + 1,
            'data'         => $result,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 4. Aggregate Query — GROUP BY + SUM + HAVING on full table
    //
    // Pattern: GROUP BY user_id, SUM(view_count), AVG(score) ORDER BY total DESC
    // Why slow: Aggregation requires reading all rows, no shortcut possible.
    // Typical: 100–500ms on 10k rows
    // ──────────────────────────────────────────────────────────────────────────
    public function aggregate(Request $request): JsonResponse
    {
        $minPosts = (int) $request->query('min_posts', 10);
        $limit    = min((int) $request->query('limit', 20), 100);

        $rows = DB::table('load_test_posts')
            ->select([
                'user_id',
                DB::raw('COUNT(*) as post_count'),
                DB::raw('SUM(view_count) as total_views'),
                DB::raw('SUM(comment_count) as total_comments'),
                DB::raw('AVG(score) as avg_score'),
                DB::raw('MAX(view_count) as max_views'),
                DB::raw("SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published_count"),
            ])
            ->groupBy('user_id')
            ->having('post_count', '>=', $minPosts)
            ->orderByDesc('total_views')
            ->limit($limit)
            ->get();

        return response()->json([
            'query_type' => 'aggregate',
            'min_posts'  => $minPosts,
            'count'      => $rows->count(),
            'data'       => $rows,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 5. Correlated Subquery — per-row ranking
    //
    // Pattern: SELECT *, (SELECT COUNT(*) FROM ... WHERE ...) as rank FROM posts
    // Why slow: Correlated subqueries execute once per outer row.
    //           With LIMIT 50: 50 subquery executions.
    // Typical: 300ms–2000ms
    // ──────────────────────────────────────────────────────────────────────────
    public function subquery(Request $request): JsonResponse
    {
        $status = $request->query('status', 'published');
        $limit  = min((int) $request->query('limit', 30), 100);

        $rows = DB::table('load_test_posts as p')
            ->select([
                'p.id', 'p.title', 'p.status', 'p.view_count', 'p.score',
                // Correlated subquery: how many posts with MORE views?
                DB::raw('(SELECT COUNT(*) FROM load_test_posts p2
                          WHERE p2.view_count > p.view_count
                          AND p2.status = p.status) + 1 AS rank_by_views'),
                // Correlated subquery: actual comment count from comments table
                DB::raw('(SELECT COUNT(*) FROM load_test_comments c
                          WHERE c.post_id = p.id) AS actual_comment_count'),
            ])
            ->where('p.status', $status)
            ->orderByDesc('p.view_count')
            ->limit($limit)
            ->get();

        return response()->json([
            'query_type' => 'correlated_subquery',
            'status'     => $status,
            'count'      => $rows->count(),
            'data'       => $rows,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 6. Explicit Sleep — controllable duration for threshold testing
    //
    // Pattern: SELECT SLEEP(n) (MySQL) or SELECT datetime('now') (SQLite)
    // Why useful: Lets the k6 test precisely control when HPA sampler fires.
    //             HEAVY_QUERY_THRESHOLD_MS=200, sleep=0.3 → always sampled.
    // ──────────────────────────────────────────────────────────────────────────
    public function sleep(Request $request): JsonResponse
    {
        // Clamp between 0.1s and 5s for safety
        $seconds = min(max((float) $request->query('s', 0.3), 0.1), 5.0);

        $start    = microtime(true);
        $isMysql  = DB::getDriverName() === 'mysql';

        if ($isMysql) {
            DB::selectOne('SELECT SLEEP(?)', [$seconds]);
        } else {
            // SQLite fallback — PHP-level sleep
            usleep((int) ($seconds * 1_000_000));
            DB::selectOne('SELECT 1');
        }

        $elapsed = round((microtime(true) - $start) * 1000, 2);

        return response()->json([
            'query_type'  => 'explicit_sleep',
            'requested_s' => $seconds,
            'elapsed_ms'  => $elapsed,
            'driver'      => DB::getDriverName(),
            'sampled'     => $elapsed >= (float) config('heavy-query.threshold_ms', 200),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 7. Sampler Stats — proxy to HeavyQuerySampler ring-buffer
    //
    // k6 calls this to verify the sampler is actually capturing queries.
    // ──────────────────────────────────────────────────────────────────────────
    public function samplerStats(): JsonResponse
    {
        $prefix = config('heavy-query.cache_key_prefix', 'heavy_query');
        $store  = config('heavy-query.cache_store', null);
        $cache  = $store ? Cache::store($store) : Cache::store();

        $index = $cache->get("{$prefix}:index", []);

        if (empty($index)) {
            return response()->json([
                'total'        => 0,
                'threshold_ms' => config('heavy-query.threshold_ms', 200),
                'enabled'      => config('heavy-query.enabled', true),
                'message'      => 'No heavy queries captured yet.',
            ]);
        }

        $durations = array_column($index, 'duration_ms');
        sort($durations);
        $count = count($durations);

        return response()->json([
            'total'        => $count,
            'threshold_ms' => config('heavy-query.threshold_ms', 200),
            'sample_rate'  => config('heavy-query.sample_rate', 1.0),
            'enabled'      => config('heavy-query.enabled', true),
            'duration_ms'  => [
                'min'  => round(min($durations), 2),
                'max'  => round(max($durations), 2),
                'avg'  => round(array_sum($durations) / $count, 2),
                'p50'  => round($durations[(int) ($count * 0.50)] ?? 0, 2),
                'p90'  => round($durations[(int) ($count * 0.90)] ?? 0, 2),
                'p99'  => round($durations[(int) ($count * 0.99)] ?? 0, 2),
            ],
            'slowest' => array_slice($index, 0, 3),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 8. Sampler Index — recent captured samples (newest first)
    // ──────────────────────────────────────────────────────────────────────────
    public function samplerIndex(Request $request): JsonResponse
    {
        $prefix  = config('heavy-query.cache_key_prefix', 'heavy_query');
        $store   = config('heavy-query.cache_store', null);
        $cache   = $store ? Cache::store($store) : Cache::store();
        $perPage = min((int) $request->query('per_page', 10), 100);

        $index = $cache->get("{$prefix}:index", []);
        $slice = array_slice($index, 0, $perPage);

        return response()->json([
            'total' => count($index),
            'data'  => $slice,
        ]);
    }
}
