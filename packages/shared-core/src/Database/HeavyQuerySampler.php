<?php

namespace Simx\Core\Database;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * HeavyQuerySampler
 *
 * Listens to every executed DB query and samples those that exceed a
 * configurable threshold.  Samples are stored in Redis (or the default
 * cache driver) with a TTL and are visible in the Grafana dashboard via
 * a custom log channel and/or the /api/_debug/heavy-queries endpoint.
 *
 * Flow:
 *   DB::listen  →  check duration ≥ threshold
 *               →  probabilistic sampling (sample_rate)
 *               →  collect context (SQL, bindings, backtrace, request info)
 *               →  store in cache ring-buffer (keyed by hash)
 *               →  write structured warning log
 */
class HeavyQuerySampler
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Handle a QueryExecuted event emitted by Laravel's DB layer.
     */
    public function handle(QueryExecuted $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $durationMs = $event->time; // already in milliseconds

        if ($durationMs < $this->threshold()) {
            return;
        }

        if (! $this->shouldSample()) {
            return;
        }

        if ($this->isIgnoredPath()) {
            return;
        }

        $sample = $this->buildSample($event, $durationMs);

        $this->store($sample);
        $this->log($sample);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    private function threshold(): float
    {
        return (float) ($this->config['threshold_ms'] ?? 200.0);
    }

    private function shouldSample(): bool
    {
        $rate = (float) ($this->config['sample_rate'] ?? 1.0);

        // rate=1.0 → capture everything; rate=0.1 → capture ~10%
        return $rate >= 1.0 || (mt_rand(1, 1000) / 1000) <= $rate;
    }

    private function isIgnoredPath(): bool
    {
        $ignored = (array) ($this->config['ignore_paths'] ?? []);

        if (empty($ignored)) {
            return false;
        }

        $path = Request::path();

        foreach ($ignored as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a rich sample payload with everything needed to diagnose the query.
     *
     * @return array<string, mixed>
     */
    private function buildSample(QueryExecuted $event, float $durationMs): array
    {
        $sql      = $event->sql;
        $bindings = $event->bindings;

        // Interpolate bindings into SQL for readability (safe — display only)
        $interpolated = $this->interpolate($sql, $bindings);

        // Capture a filtered backtrace (strip vendor frames)
        $trace = $this->captureTrace();

        return [
            'id'           => (string) Str::uuid(),
            'sampled_at'   => now()->toIso8601String(),
            'duration_ms'  => round($durationMs, 3),
            'threshold_ms' => $this->threshold(),
            'connection'   => $event->connectionName,
            'sql'          => $sql,
            'bindings'     => $bindings,
            'interpolated' => $interpolated,
            'trace'        => $trace,
            'request'      => [
                'method'     => Request::method(),
                'url'        => Request::fullUrl(),
                'route'      => optional(Request::route())->getName(),
                'ip'         => Request::ip(),
                'user_agent' => Request::userAgent(),
                'user_id'    => optional(Request::user())->getKey(),
            ],
            'process'      => [
                'pid'        => getmypid(),
                'memory_mb'  => round(memory_get_usage(true) / 1_048_576, 2),
                'peak_mem_mb'=> round(memory_get_peak_usage(true) / 1_048_576, 2),
            ],
        ];
    }

    /**
     * Persist the sample into the cache ring-buffer.
     * Uses a sorted set approach: hash → sample stored individually,
     * plus an ordered index list capped at max_samples.
     */
    private function store(array $sample): void
    {
        try {
            $ttl     = (int) ($this->config['ttl_seconds'] ?? 3600);
            $max     = (int) ($this->config['max_samples'] ?? 500);
            $prefix  = $this->config['cache_key_prefix'] ?? 'heavy_query';
            $driver  = $this->config['cache_store'] ?? null;

            $cache = $driver
                ? Cache::store($driver)
                : Cache::store();

            // Store individual sample
            $sampleKey = "{$prefix}:sample:{$sample['id']}";
            $cache->put($sampleKey, $sample, $ttl);

            // Maintain an ordered index (newest first), capped at max_samples
            $indexKey = "{$prefix}:index";
            $index    = $cache->get($indexKey, []);

            // Prepend newest
            array_unshift($index, [
                'id'          => $sample['id'],
                'sampled_at'  => $sample['sampled_at'],
                'duration_ms' => $sample['duration_ms'],
                'sql_preview' => mb_substr($sample['interpolated'], 0, 120),
            ]);

            // Cap index length
            if (count($index) > $max) {
                $removed = array_splice($index, $max);
                // Clean up orphaned sample keys
                foreach ($removed as $old) {
                    $cache->forget("{$prefix}:sample:{$old['id']}");
                }
            }

            $cache->put($indexKey, $index, $ttl);
        } catch (\Throwable $e) {
            // Never let sampling break the application
            Log::channel('stderr')->error('[HeavyQuerySampler] Store failed: '.$e->getMessage());
        }
    }

    /**
     * Write a structured WARNING log entry.
     * Visible in `kubectl logs` and picked up by Prometheus log-based metrics.
     */
    private function log(array $sample): void
    {
        try {
            $channel = $this->config['log_channel'] ?? 'stderr';

            Log::channel($channel)->warning('HEAVY_QUERY_SAMPLED', [
                'duration_ms'  => $sample['duration_ms'],
                'threshold_ms' => $sample['threshold_ms'],
                'sql_preview'  => mb_substr($sample['interpolated'], 0, 300),
                'connection'   => $sample['connection'],
                'route'        => $sample['request']['route'],
                'url'          => $sample['request']['url'],
                'user_id'      => $sample['request']['user_id'],
                'sample_id'    => $sample['id'],
                'memory_mb'    => $sample['process']['memory_mb'],
                'trace_top'    => $sample['trace'][0] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Swallow — logging must not crash the request
        }
    }

    /**
     * Return a filtered, human-readable backtrace.
     * Strips Laravel/vendor frames to show only application code.
     *
     * @return list<string>
     */
    private function captureTrace(): array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);

        $appPath   = base_path('app');
        $routesPath = base_path('routes');
        $clean     = [];

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? '';

            // Only include app/ and routes/ frames (and skip this sampler itself)
            if (
                (str_starts_with($file, $appPath) || str_starts_with($file, $routesPath)) &&
                ! str_contains($file, 'HeavyQuerySampler')
            ) {
                $clean[] = sprintf(
                    '%s:%d  %s%s%s()',
                    str_replace(base_path().DIRECTORY_SEPARATOR, '', $file),
                    $frame['line'] ?? 0,
                    $frame['class'] ?? '',
                    $frame['type'] ?? '',
                    $frame['function'] ?? ''
                );
            }

            if (count($clean) >= 8) {
                break;
            }
        }

        return $clean;
    }

    /**
     * Safely interpolate PDO bindings into the SQL string (for display only).
     *
     * @param array<mixed> $bindings
     */
    private function interpolate(string $sql, array $bindings): string
    {
        if (empty($bindings)) {
            return $sql;
        }

        $prepared = array_map(function ($value): string {
            if ($value === null) {
                return 'NULL';
            }
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
            if ($value instanceof \DateTimeInterface) {
                return "'".$value->format('Y-m-d H:i:s')."'";
            }
            // Truncate very large string values
            $str = (string) $value;
            if (mb_strlen($str) > 200) {
                $str = mb_substr($str, 0, 200).'…';
            }
            return "'".addslashes($str)."'";
        }, $bindings);

        // Replace ? placeholders
        $result = $sql;
        foreach ($prepared as $value) {
            $pos = strpos($result, '?');
            if ($pos !== false) {
                $result = substr_replace($result, $value, $pos, 1);
            }
        }

        return $result;
    }
}
