import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

// ──────────────────────────────────────────────────────────────────────────────
// Custom Metrics
// ──────────────────────────────────────────────────────────────────────────────
const errorRate   = new Rate('http_error_rate');
const p99Latency  = new Trend('p99_latency', true);
const totalErrors = new Counter('total_errors');

// ──────────────────────────────────────────────────────────────────────────────
// Environment — override with k6 -e TARGET_HOST=... 
// ──────────────────────────────────────────────────────────────────────────────
const TARGET_HOST = __ENV.TARGET_HOST || 'http://api.simxstudio.test';

// ──────────────────────────────────────────────────────────────────────────────
// Scenarios
// ──────────────────────────────────────────────────────────────────────────────
export const options = {
  scenarios: {

    // ── Scenario 1: Baseline Smoke Test ──────────────────────────────────────
    // Goal: Verify the system works with minimal load (1 VU, 1 min)
    // Expected: 0% errors, <200ms P99
    smoke_test: {
      executor: 'constant-vus',
      vus: 1,
      duration: '1m',
      tags: { scenario: 'smoke' },
      exec: 'smokeTest',
      startTime: '0s',
    },

    // ── Scenario 2: Ramp-up to find saturation point ──────────────────────────
    // Goal: Find exactly how many concurrent users cause HPA scale-out
    // Watch: When CPU hits 70% → pods should scale up
    ramp_up: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '2m', target: 10  },   // warm up (10 VUs)
        { duration: '3m', target: 25  },   // moderate load
        { duration: '3m', target: 50  },   // ← HPA should trigger here
        { duration: '3m', target: 75  },   // heavy load
        { duration: '3m', target: 100 },   // stress
        { duration: '2m', target: 0   },   // cool down
      ],
      tags: { scenario: 'ramp_up' },
      exec: 'loadTest',
      startTime: '2m', // starts after smoke_test finishes
    },

    // ── Scenario 3: Spike Test ────────────────────────────────────────────────
    // Goal: Test HPA reaction time to sudden traffic spikes
    // Watch: Time from spike → new pod Ready → load shared
    spike_test: {
      executor: 'ramping-vus',
      startVUs: 5,
      stages: [
        { duration: '30s', target: 5   },  // baseline
        { duration: '10s', target: 100 },  // sudden spike
        { duration: '1m',  target: 100 },  // sustain spike (HPA kicks in)
        { duration: '10s', target: 5   },  // drop (scale-down cooldown starts)
        { duration: '5m',  target: 5   },  // observe scale-down (5 min window)
      ],
      tags: { scenario: 'spike' },
      exec: 'loadTest',
      startTime: '20m', // starts after ramp-up
    },

    // ── Scenario 4: Soak / Endurance Test ────────────────────────────────────
    // Goal: Confirm system stability at moderate load for 30 minutes
    // Watch: Memory leaks, pod restarts, latency drift
    soak_test: {
      executor: 'constant-vus',
      vus: 30,
      duration: '30m',
      tags: { scenario: 'soak' },
      exec: 'loadTest',
      startTime: '30m', // runs after spike
    },
  },

  // ── Global Thresholds ──────────────────────────────────────────────────────
  thresholds: {
    'http_req_duration':                    ['p(99)<2000'],  // 99% under 2s
    'http_req_duration{scenario:smoke}':    ['p(99)<300'],   // smoke: 99% under 300ms
    'http_req_duration{scenario:ramp_up}':  ['p(95)<1500'],  // ramp: 95% under 1.5s
    'http_req_duration{scenario:spike}':    ['p(90)<3000'],  // spike: 90% under 3s
    'http_req_duration{scenario:soak}':     ['p(99)<1000'],  // soak: 99% under 1s
    'http_error_rate':                      ['rate<0.01'],   // <1% errors globally
    'checks':                               ['rate>0.99'],   // >99% checks pass
  },
};

// ──────────────────────────────────────────────────────────────────────────────
// Smoke Test — simple health check
// ──────────────────────────────────────────────────────────────────────────────
export function smokeTest() {
  const res = http.get(`${TARGET_HOST}/up`, {
    headers: { 'Accept': 'application/json' },
    tags: { name: 'health_check' },
  });

  const ok = check(res, {
    'smoke: status 200':         (r) => r.status === 200,
    'smoke: response time <300ms': (r) => r.timings.duration < 300,
  });

  if (!ok) {
    errorRate.add(1);
    totalErrors.add(1);
  } else {
    errorRate.add(0);
  }

  p99Latency.add(res.timings.duration);
  sleep(1);
}

// ──────────────────────────────────────────────────────────────────────────────
// Load Test — realistic API traffic mix
// ──────────────────────────────────────────────────────────────────────────────
export function loadTest() {
  const requests = [
    { name: 'health',    url: '/up',         weight: 20 },
    { name: 'home',      url: '/',           weight: 40 },
    { name: 'api_users', url: '/api/users',  weight: 25 },
    { name: 'api_data',  url: '/api/health', weight: 15 },
  ];

  // Weighted random endpoint selection
  const total  = requests.reduce((sum, r) => sum + r.weight, 0);
  let rand     = Math.random() * total;
  let selected = requests[0];
  for (const req of requests) {
    rand -= req.weight;
    if (rand <= 0) { selected = req; break; }
  }

  const res = http.get(`${TARGET_HOST}${selected.url}`, {
    headers: {
      'Accept':       'application/json',
      'X-Test-Run':   'k6-autoscale-test',
      'Cache-Control': 'no-cache',
    },
    tags: { name: selected.name },
    timeout: '10s',
  });

  const ok = check(res, {
    'status not 5xx': (r) => r.status < 500,
    'response <5s':   (r) => r.timings.duration < 5000,
  });

  errorRate.add(!ok ? 1 : 0);
  if (!ok) totalErrors.add(1);
  p99Latency.add(res.timings.duration);

  // Simulate real user think-time (0.1–1.5s)
  sleep(Math.random() * 1.4 + 0.1);
}
