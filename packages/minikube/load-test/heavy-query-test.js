/**
 * heavy-query-test.js — k6 Load Test for Heavy Query Sampling
 *
 * Tests 6 categories of deliberately slow DB queries, verifying that:
 *   1. Each query pattern responds correctly under load
 *   2. The HeavyQuerySampler captures samples (checked every 30s)
 *   3. Sampler stats grow as load increases
 *
 * Usage:
 *   k6 run heavy-query-test.js
 *   k6 run -e TARGET_HOST=http://api.simxstudio.test heavy-query-test.js
 *   k6 run -e SCENARIO=saturation heavy-query-test.js
 *
 * Scenarios (sequential):
 *   baseline   — 2 VUs, 2 min  (warm-up, verify sampler activates)
 *   gradual    — ramp 2→40 VUs (find per-endpoint saturation points)
 *   saturation — 40 VUs, 5 min (sustain heavy load, observe HPA + sampler)
 *   cooldown   — ramp 40→2 VUs (observe scale-down + sampler retention)
 */

import http from 'k6/http';
import { check, group, sleep, fail } from 'k6';
import { Rate, Trend, Counter, Gauge } from 'k6/metrics';

// ──────────────────────────────────────────────────────────────────────────────
// Config
// ──────────────────────────────────────────────────────────────────────────────
const HOST     = __ENV.TARGET_HOST  || 'http://api.simxstudio.test';
const SCENARIO = __ENV.SCENARIO     || 'all';   // all | baseline | gradual | saturation

// ──────────────────────────────────────────────────────────────────────────────
// Custom Metrics
// ──────────────────────────────────────────────────────────────────────────────
const httpErrors        = new Rate('http_errors');
const samplerTotal      = new Gauge('sampler_total_captured');
const samplerP99        = new Gauge('sampler_p99_ms');
const samplerMax        = new Gauge('sampler_max_ms');
const queryDuration     = new Trend('query_duration_ms', true);
const slowQueryCount    = new Counter('slow_query_count');   // queries that were sampled
const nPlusOneQueries   = new Counter('n_plus_one_calls');
const fullScanCalls     = new Counter('full_scan_calls');
const likeSearchCalls   = new Counter('like_search_calls');
const aggregateCalls    = new Counter('aggregate_calls');
const subqueryCalls     = new Counter('subquery_calls');
const samplerCheckFails = new Counter('sampler_check_fails');

// ──────────────────────────────────────────────────────────────────────────────
// Scenarios
// ──────────────────────────────────────────────────────────────────────────────
export const options = {
  scenarios: {

    // ── Phase 1: Baseline — verify sampler activates with minimal load ─────────
    // Run every heavy query type once at low concurrency.
    // Expect: all 6 pattern types to produce ≥1 sampler entry each.
    baseline: {
      executor:  'constant-vus',
      vus:       2,
      duration:  '2m',
      startTime: '0s',
      tags:      { phase: 'baseline' },
      exec:      'heavyQueryMix',
    },

    // ── Phase 2: Gradual ramp — find saturation per pattern ───────────────────
    // Increases VUs slowly. Each pattern has different saturation points:
    //   full-scan:  ~10 VUs before >500ms P99
    //   n-plus-one: ~5 VUs  → DB connection pool exhaustion
    //   subquery:   ~8 VUs  → exponential slowdown
    gradual: {
      executor:  'ramping-vus',
      startVUs:  2,
      stages: [
        { duration: '1m',  target: 5  },   // warm up
        { duration: '2m',  target: 10 },   // light load
        { duration: '2m',  target: 20 },   // moderate — most patterns slow here
        { duration: '2m',  target: 40 },   // heavy — N+1 and subquery saturate
        { duration: '1m',  target: 2  },   // cool down
      ],
      startTime: '2m',   // after baseline
      tags:      { phase: 'gradual' },
      exec:      'heavyQueryMix',
    },

    // ── Phase 3: Saturation — sustain heavy load, watch HPA + sampler ─────────
    // Holds at 40 VUs for 5 minutes.
    // Expect: HPA to scale out, sampler ring-buffer growing toward max_samples.
    saturation: {
      executor:  'constant-vus',
      vus:       40,
      duration:  '5m',
      startTime: '10m',   // after gradual finishes (2+8=10m)
      tags:      { phase: 'saturation' },
      exec:      'heavyQueryMix',
    },

    // ── Phase 4: Cooldown — observe scale-down and sampler retention ───────────
    cooldown: {
      executor:  'ramping-vus',
      startVUs:  40,
      stages: [
        { duration: '2m', target: 10 },
        { duration: '3m', target: 2  },
      ],
      startTime: '15m',
      tags:      { phase: 'cooldown' },
      exec:      'heavyQueryMix',
    },

    // ── Sampler Poller — checks sampler stats every 30s throughout ────────────
    // A separate, low-frequency scenario that reads sampler stats to confirm
    // heavy queries are being captured. Runs for the full test duration.
    sampler_poller: {
      executor:  'constant-arrival-rate',
      rate:      2,            // 2 iterations per minute
      timeUnit:  '1m',
      duration:  '20m',
      preAllocatedVUs: 1,
      maxVUs:          2,
      startTime: '0s',
      tags:      { phase: 'sampler_poll' },
      exec:      'pollSamplerStats',
    },

  },

  // ── Thresholds ───────────────────────────────────────────────────────────────
  thresholds: {
    // HTTP error rate: <2% across all phases
    'http_errors':                              ['rate<0.02'],

    // Per-endpoint latency budgets (realistic for heavy queries with 10k rows)
    'http_req_duration{name:full_scan}':        ['p(95)<2000'],
    'http_req_duration{name:like_search}':      ['p(95)<3000'],
    'http_req_duration{name:n_plus_one}':       ['p(90)<4000'],
    'http_req_duration{name:aggregate}':        ['p(95)<1500'],
    'http_req_duration{name:subquery}':         ['p(90)<5000'],
    'http_req_duration{name:sleep_300}':        ['p(99)<1500'],

    // Sampler must capture queries (confirms sampler is working)
    'sampler_total_captured':                   ['value>0'],

    // Baseline phase must not error
    'http_errors{phase:baseline}':              ['rate<0.001'],
  },
};

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

/** Weighted random endpoint selection */
function pick(endpoints) {
  const total = endpoints.reduce((s, e) => s + e.weight, 0);
  let   rand  = Math.random() * total;
  for (const e of endpoints) {
    rand -= e.weight;
    if (rand <= 0) return e;
  }
  return endpoints[0];
}

function get(url, params = {}) {
  return http.get(`${HOST}${url}`, {
    headers: {
      'Accept':          'application/json',
      'X-Load-Test-Run': 'k6-heavy-query',
      'Cache-Control':   'no-cache',
    },
    timeout: '30s',
    ...params,
  });
}

// ──────────────────────────────────────────────────────────────────────────────
// Main VU function — weighted mix of all heavy query patterns
// ──────────────────────────────────────────────────────────────────────────────
export function heavyQueryMix() {

  const endpoints = [
    // ── Full Table Scan (no index on status) ────────────────────────────────
    {
      weight: 25,
      name:   'full_scan',
      fn: () => {
        const statuses = ['published', 'draft', 'archived', 'pending'];
        const s = statuses[Math.floor(Math.random() * statuses.length)];

        group('full_scan', () => {
          const res = get(`/api/loadtest/full-scan?status=${s}&limit=50`, {
            tags: { name: 'full_scan' },
          });

          const ok = check(res, {
            'full_scan: status 200':      r => r.status === 200,
            'full_scan: has data field':  r => !!JSON.parse(r.body).data,
            'full_scan: query_type ok':   r => JSON.parse(r.body).query_type === 'full_table_scan',
          });

          queryDuration.add(res.timings.duration);
          fullScanCalls.add(1);
          httpErrors.add(!ok ? 1 : 0);
        });
      },
    },

    // ── LIKE Wildcard Search ─────────────────────────────────────────────────
    {
      weight: 15,
      name:   'like_search',
      fn: () => {
        const terms = ['the', 'and', 'ing', 'pre', 'con', 'ion', 'str'];
        const q = terms[Math.floor(Math.random() * terms.length)];

        group('like_search', () => {
          const res = get(`/api/loadtest/like-search?q=${q}&limit=25`, {
            tags: { name: 'like_search' },
          });

          const ok = check(res, {
            'like_search: status 200':    r => r.status === 200,
            'like_search: has data':      r => Array.isArray(JSON.parse(r.body).data),
            'like_search: term echoed':   r => JSON.parse(r.body).term === q,
          });

          queryDuration.add(res.timings.duration);
          likeSearchCalls.add(1);
          httpErrors.add(!ok ? 1 : 0);
        });
      },
    },

    // ── N+1 Query Pattern ────────────────────────────────────────────────────
    {
      weight: 20,
      name:   'n_plus_one',
      fn: () => {
        const n = [5, 10, 15, 20][Math.floor(Math.random() * 4)];

        group('n_plus_one', () => {
          const res = get(`/api/loadtest/n-plus-one?n=${n}`, {
            tags: { name: 'n_plus_one' },
          });

          const ok = check(res, {
            'n+1: status 200':           r => r.status === 200,
            'n+1: total_queries = n+1':  r => JSON.parse(r.body).total_queries === n + 1,
            'n+1: data array':           r => Array.isArray(JSON.parse(r.body).data),
          });

          queryDuration.add(res.timings.duration);
          nPlusOneQueries.add(n + 1);   // count the actual DB queries
          httpErrors.add(!ok ? 1 : 0);
        });
      },
    },

    // ── GROUP BY Aggregate ───────────────────────────────────────────────────
    {
      weight: 15,
      name:   'aggregate',
      fn: () => {
        const minPosts = [5, 10, 20, 50][Math.floor(Math.random() * 4)];

        group('aggregate', () => {
          const res = get(`/api/loadtest/aggregate?min_posts=${minPosts}&limit=20`, {
            tags: { name: 'aggregate' },
          });

          const ok = check(res, {
            'aggregate: status 200':    r => r.status === 200,
            'aggregate: has data':      r => !!JSON.parse(r.body).data,
            'aggregate: query_type ok': r => JSON.parse(r.body).query_type === 'aggregate',
          });

          queryDuration.add(res.timings.duration);
          aggregateCalls.add(1);
          httpErrors.add(!ok ? 1 : 0);
        });
      },
    },

    // ── Correlated Subquery ──────────────────────────────────────────────────
    {
      weight: 10,
      name:   'subquery',
      fn: () => {
        const limit = [10, 20, 30][Math.floor(Math.random() * 3)];

        group('subquery', () => {
          const res = get(`/api/loadtest/subquery?status=published&limit=${limit}`, {
            tags: { name: 'subquery' },
          });

          const ok = check(res, {
            'subquery: status 200':    r => r.status === 200,
            'subquery: has rank_by_views': r => {
              const d = JSON.parse(r.body).data;
              return Array.isArray(d) && (d.length === 0 || d[0].rank_by_views !== undefined);
            },
          });

          queryDuration.add(res.timings.duration);
          subqueryCalls.add(1);
          httpErrors.add(!ok ? 1 : 0);
        });
      },
    },

    // ── Explicit Sleep (controls threshold crossing precisely) ───────────────
    {
      weight: 15,
      name:   'sleep_controlled',
      fn: () => {
        // 0.3s > default threshold 0.2s → always captured
        const ms = [300, 500, 750, 1000][Math.floor(Math.random() * 4)];
        const s  = ms / 1000;

        group('sleep_controlled', () => {
          const res = get(`/api/loadtest/sleep?s=${s}`, {
            tags: { name: 'sleep_300' },
          });

          const body = JSON.parse(res.body);
          const ok = check(res, {
            'sleep: status 200':   r => r.status === 200,
            'sleep: was sampled':  () => body.sampled === true,
            'sleep: elapsed ok':   () => body.elapsed_ms >= ms * 0.8,   // ±20%
          });

          queryDuration.add(res.timings.duration);
          if (body.sampled) slowQueryCount.add(1);
          httpErrors.add(!ok ? 1 : 0);
        });
      },
    },
  ];

  const selected = pick(endpoints);
  selected.fn();

  // Think-time: heavier queries → longer pause to avoid connection starvation
  sleep(Math.random() * 0.8 + 0.2);
}

// ──────────────────────────────────────────────────────────────────────────────
// Sampler Poller — verifies the sampler is accumulating captures
// Runs as a separate low-frequency scenario (2x/min)
// ──────────────────────────────────────────────────────────────────────────────
export function pollSamplerStats() {
  group('sampler_poll', () => {
    const res = get('/api/loadtest/sampler/stats', {
      tags: { name: 'sampler_stats' },
    });

    if (res.status !== 200) {
      samplerCheckFails.add(1);
      return;
    }

    const body = JSON.parse(res.body);

    check(res, {
      'sampler: enabled is true':   () => body.enabled === true,
      'sampler: threshold_ms set':  () => body.threshold_ms > 0,
    });

    // Update gauges so Grafana/k6 dashboard shows sampler growth
    if (body.total > 0) {
      samplerTotal.add(body.total);
      samplerP99.add(body.duration_ms?.p99 ?? 0);
      samplerMax.add(body.duration_ms?.max ?? 0);

      console.log(
        `[sampler] total=${body.total} ` +
        `p99=${body.duration_ms?.p99}ms ` +
        `max=${body.duration_ms?.max}ms ` +
        `threshold=${body.threshold_ms}ms`
      );
    } else {
      console.log('[sampler] no captures yet');
      samplerCheckFails.add(1);
    }

    // Also fetch recent 5 samples for the console log
    const idxRes = get('/api/loadtest/sampler/index?per_page=5', {
      tags: { name: 'sampler_index' },
    });

    if (idxRes.status === 200) {
      const idx = JSON.parse(idxRes.body);
      if (idx.data && idx.data.length > 0) {
        console.log('[sampler] recent captures:');
        idx.data.forEach(s => {
          console.log(`  └─ ${s.duration_ms}ms — ${s.sql_preview?.slice(0, 80)}`);
        });
      }
    }
  });

  sleep(1);
}
