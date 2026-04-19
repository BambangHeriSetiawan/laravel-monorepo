# 🚀 SimXStudio — Monitoring, Autoscaling & Capacity Analysis

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Monitoring Stack (Grafana + Prometheus)](#monitoring-stack)
3. [Autoscaling (HPA) Configuration](#autoscaling-hpa)
4. [Capacity Analysis — How Many Requests Before Scale-Up?](#capacity-analysis)
5. [Load Test Scenarios](#load-test-scenarios)
6. [Running the Tests](#running-the-tests)
7. [Interpreting Results in Grafana](#interpreting-results-in-grafana)
8. [Tuning Guide](#tuning-guide)

---

## Architecture Overview

```
Internet / curl
      │
      ▼
Nginx Ingress Controller
      │
      ├──► admin.simxstudio.test  ──► admin Deployment   ───┐
      ├──► api.simxstudio.test    ──► api Deployment     ───┤
      └──► simxstudio.test        ──► leading Deployment ───┤
                                                            │
                                        ┌───────────────────┘
                                        ▼
                                   FrankenPHP / Octane
                                        │
                              ┌─────────┴─────────┐
                              ▼                   ▼
                            MySQL              Redis
                              │
                              ▼
                        Prometheus ←── scrape ─── pods
                              │
                              ▼
                           Grafana (monitoring.simxstudio.test)
                              │
                        ┌─────┴──────┐
                        ▼            ▼
                  Laravel Dashboard  HPA Dashboard
```

---

## Monitoring Stack

### What's deployed

| Component                 | Purpose                             | Version |
| ------------------------- | ----------------------------------- | ------- |
| **kube-prometheus-stack** | All-in-one Helm chart               | 65.3.1  |
| **Prometheus**            | Metrics collection & alerting       | bundled |
| **Grafana**               | Dashboards & visualization          | bundled |
| **AlertManager**          | Alert routing                       | bundled |
| **Node Exporter**         | Host CPU / memory / disk metrics    | bundled |
| **kube-state-metrics**    | Kubernetes object state (pods, HPA) | bundled |
| **Metrics Server**        | HPA live CPU/memory data            | 3.12.1  |

### Accessing Grafana

```bash
# Option A — via Ingress (after adding to /etc/hosts)
open http://monitoring.simxstudio.test

# Option B — port-forward
kubectl port-forward -n simxstudio svc/kube-prometheus-stack-grafana 3000:80
open http://localhost:3000

# Credentials
Username: admin
Password: simxstudio-admin   (set via grafana_admin_password variable)
```

### Add to /etc/hosts

```bash
# Get Ingress IP
INGRESS_IP=$(kubectl get svc -n ingress-nginx ingress-nginx-controller -o jsonpath='{.status.loadBalancer.ingress[0].ip}' 2>/dev/null || echo "127.0.0.1")

sudo tee -a /etc/hosts <<EOF
$INGRESS_IP  simxstudio.test api.simxstudio.test admin.simxstudio.test monitoring.simxstudio.test
EOF
```

### Pre-loaded Dashboards

| Dashboard                          | UID                           | What it shows                                                  |
| ---------------------------------- | ----------------------------- | -------------------------------------------------------------- |
| **SimXStudio — Laravel Overview**  | `simxstudio-laravel-overview` | RPS, P99 latency, error rate, pod count, CPU/mem, MySQL, Redis |
| **SimXStudio — HPA & Autoscaling** | `simxstudio-hpa-overview`     | Replica count, CPU/mem gauges, scale events timeline           |
| **Kubernetes / Compute Resources** | built-in                      | Node and pod resource consumption                              |
| **Node Exporter / Full**           | built-in                      | Host disk, network, CPU pressure                               |

---

## Autoscaling (HPA)

### Current Configuration

```
app_cpu_request    = 250m   (0.25 vCPU)
app_cpu_limit      = 1000m  (1.0 vCPU)
app_memory_request = 256Mi
app_memory_limit   = 512Mi

hpa_min_replicas     = 1
hpa_max_replicas     = 5
hpa_cpu_threshold    = 70%   → scale-out when avg CPU > 175m (70% of 250m request)
hpa_memory_threshold = 75%   → scale-out when avg mem > 192Mi (75% of 256Mi request)
```

### Scaling Behavior

```
Scale-UP  policy: add up to 2 pods every 30s (fast response)
Scale-DOWN policy: remove 1 pod every 60s, with 5-min stabilization window
                   (prevents flapping after a spike)
```

### Useful kubectl Commands

```bash
# Watch HPA in real-time
kubectl get hpa -n simxstudio -w

# See scale events and current targets
kubectl describe hpa -n simxstudio

# Watch pod count change
kubectl get pods -n simxstudio -w

# Check current CPU/memory of pods (requires metrics-server)
kubectl top pods -n simxstudio
kubectl top nodes
```

---

## Capacity Analysis — How Many Requests Before Scale-Up?

> **Current Spec:** Each pod gets `250m` CPU request, `256Mi` memory.
> **HPA trigger:** CPU ≥ 70% of request = **175m CPU per pod**

### FrankenPHP / Octane Baseline Performance Estimates

FrankenPHP runs in worker mode — the Laravel app boots once and handles many requests without re-booting. This makes it significantly faster than plain PHP-FPM.

| Metric                         | Estimate (single pod, 250m CPU) |
| ------------------------------ | ------------------------------- |
| Worker threads                 | ~4 (FrankenPHP default)         |
| Simple endpoint (e.g., `/up`)  | **~200–400 RPS** at <50ms P99   |
| Light API endpoint (cached)    | **~80–150 RPS** at <100ms P99   |
| DB-heavy endpoint (1 query)    | **~30–60 RPS** at <300ms P99    |
| Complex endpoint (3–5 queries) | **~10–25 RPS** at <800ms P99    |

### When Does HPA Scale Up?

The HPA scales when **average CPU across all pods** > 175m (70% of 250m request).

A FrankenPHP worker at moderate load consumes roughly **50–100m CPU per 10 RPS** on simple endpoints.

| Load          | CPU/pod         | Memory/pod       | HPA Action                           |
| ------------- | --------------- | ---------------- | ------------------------------------ |
| < 20 RPS      | ~50m (20%)      | ~150Mi (58%)     | ✅ No scaling                        |
| 20–40 RPS     | ~100m (40%)     | ~180Mi (70%)     | ✅ No scaling                        |
| **40–70 RPS** | **~175m (70%)** | **~200Mi (78%)** | ⚠️ **CPU threshold hit → SCALE UP!** |
| 70–100 RPS    | ~250m (100%)    | ~220Mi (85%)     | ⚡ Scale to 2 pods                   |
| 100+ RPS      | saturated       | OOM risk         | ⚡ Scale to 3–5 pods                 |

### Effective Capacity per Replica Count

| Replicas | Sustainable RPS (mixed traffic) | Notes                                    |
| -------- | ------------------------------- | ---------------------------------------- |
| 1 (min)  | **~40–50 RPS**                  | Below 70% CPU threshold — steady state   |
| 2        | **~80–100 RPS**                 | After first scale-out                    |
| 3        | **~120–150 RPS**                |                                          |
| 4        | **~160–200 RPS**                |                                          |
| 5 (max)  | **~200–250 RPS**                | Current max, limited by MySQL bottleneck |

> ⚠️ **MySQL is the real bottleneck**: with the default `mysql:8.0` setup (no resource limits, single instance), expect connection saturation beyond ~100 concurrent requests. Consider adding HPA for MySQL or using PlanetScale / RDS for production.

### Increasing Capacity Without Increasing HPA Max

You can tune resources per-pod in `terraform.tfvars`:

```hcl
# For higher throughput per pod (costs more node resources)
app_cpu_request    = "500m"   # 0.5 vCPU
app_cpu_limit      = "2000m"  # 2.0 vCPU
app_memory_request = "512Mi"
app_memory_limit   = "1Gi"
```

---

## Load Test Scenarios

Four k6 scenarios are pre-configured in `load-test/autoscale-test.js`:

### Scenario 1 — Smoke Test (1 VU, 1 min)

**Goal**: Verify the system is alive before load testing.

- 1 virtual user, continuous for 1 minute
- Threshold: 100% success, P99 < 300ms
- **What to watch in Grafana**: RPS = ~1–2, pod count = 1

### Scenario 2 — Ramp-Up Test (0 → 100 VUs over 16 min)

**Goal**: Find the exact point where HPA triggers scale-out.

```
0 VUs ──2min──► 10 VUs ──3min──► 25 VUs ──3min──► 50 VUs ──3min──► 75 VUs ──3min──► 100 VUs ──2min──► 0
                                              ↑
                               HPA trigger expected here
                               (CPU > 70% → new pod spawned)
```

**What to watch in Grafana (HPA dashboard)**:

- `Current Replicas` counter → jumps from 1 to 2
- `CPU Utilization %` gauge → crosses 70%
- Timeline shows exactly when scaling happened

**Expected observations**:

- At ~40–50 VUs: P99 latency starts increasing
- At ~50–70 VUs: CPU crosses 175m threshold, HPA fires
- ~30–60 seconds later: new pod becomes Ready
- RPS redistributes across pods, latency drops

### Scenario 3 — Spike Test (5 → 100 → 5 VUs)

**Goal**: Test HPA reaction time to sudden bursts.

```
5 VUs ──30s──► 5 VUs ──10s──► 100 VUs ──1min──► 100 VUs ──10s──► 5 VUs ──5min──► 5 VUs
                                    ↑ sudden spike                       ↑ scale-down cooldown
```

**Key timings to measure**:

1. **Time-to-detect**: How long until HPA notices the CPU spike (~15s poll interval)
2. **Time-to-scale**: How long until a new pod is Ready (~30–60s for FrankenPHP with init migration)
3. **Time-to-recover**: How long until P99 latency normalizes
4. **Scale-down delay**: HPA waits 5 minutes before reducing replicas (our `stabilization_window_seconds = 300`)

### Scenario 4 — Soak Test (30 VUs, 30 min)

**Goal**: Confirm no memory leaks or latency drift over time.

**What to watch**:

- Memory trend should be **flat** after initial stabilization
- Latency P99 should remain **consistent**, not drift upward
- Pod restarts should be **zero** (`kubectl get pods -n simxstudio`)
- Redis connection pool should **not grow unbounded**

---

## Running the Tests

### Prerequisites

```bash
# 1. Install k6 (macOS)
brew install k6

# 2. Ensure the cluster is running
kubectl get pods -n simxstudio

# 3. Ensure ingress is reachable
curl -s http://api.simxstudio.test/up
```

### Quick Start

```bash
cd packages/minikube/load-test
chmod +x run-load-test.sh

# Run against API service (all 4 scenarios)
./run-load-test.sh api

# Run against admin service
./run-load-test.sh admin

# Run against leading (frontend) service
./run-load-test.sh leading
```

### Manual k6 Commands

```bash
# Just the smoke test
k6 run --env TARGET_HOST=http://api.simxstudio.test \
       --include-system-env-vars \
       autoscale-test.js

# Only ramp-up scenario
k6 run --env TARGET_HOST=http://api.simxstudio.test \
       autoscale-test.js

# Real-time output to Prometheus (for Grafana ingestion)
k6 run --env TARGET_HOST=http://api.simxstudio.test \
       --out experimental-prometheus-rw='http://localhost:9090/api/v1/write' \
       autoscale-test.js

# HTML report
k6 run autoscale-test.js --out web-dashboard  # opens live browser dashboard
```

---

## Interpreting Results in Grafana

### Scale-up happened — what to look for

In **HPA & Autoscaling dashboard**:

1. **`HPA Replicas Over Time`** — the `current` line jumps up by 1
2. **`CPU Utilization %`** gauge turns orange/red just before the jump
3. **`Scale-up Events`** stat increments

In **Laravel Overview dashboard**:

1. **`Total RPS`** stays stable or increases after scale-up (good)
2. **`P99 Latency`** should drop after the new pod is ready
3. **`CPU Usage — by Pod`** spread across 2+ pods after scaling

### Determining your real capacity

Run the ramp-up test and look for these markers in Grafana:

| Grafana Signal            | Meaning                         |
| ------------------------- | ------------------------------- |
| P99 latency crosses 500ms | You're approaching saturation   |
| CPU gauge hits 70%        | HPA trigger threshold           |
| Error rate > 0.5%         | Overloaded, needs more capacity |
| Replica count jumps       | HPA has acted                   |

---

## Tuning Guide

### Too many scale-ups? (flapping)

```hcl
# Increase threshold and stabilization
hpa_cpu_threshold    = 80   # was 70
hpa_memory_threshold = 85   # was 75
```

Also increase in `hpa.tf`:

```hcl
scale_down {
  stabilization_window_seconds = 600  # 10 min (was 5 min)
}
```

### Scale-up too slow?

```hcl
hpa_cpu_threshold = 60  # trigger earlier
```

Increase pod resources so each pod can handle more before hitting the threshold:

```hcl
app_cpu_request = "500m"
app_cpu_limit   = "2000m"
```

### Scale-up not happening at all?

Common causes:

1. `metrics-server` not running: `kubectl get pods -n kube-system | grep metrics-server`
2. Pod has no `resources.requests.cpu` — check `kubectl describe pod -n simxstudio <pod>`
3. HPA not watching the right deployment: `kubectl describe hpa -n simxstudio`

```bash
# Diagnostic
kubectl get hpa -n simxstudio
kubectl top pods -n simxstudio
kubectl describe hpa admin-hpa -n simxstudio
```

### Deploy changes

```bash
cd packages/minikube/terraform/minikube
terraform plan
terraform apply
```
