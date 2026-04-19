#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# run-load-test.sh — Run k6 autoscale scenarios against SimXStudio
# Usage: ./run-load-test.sh [api|admin|leading] [scenario]
# ──────────────────────────────────────────────────────────────────────────────
set -euo pipefail

APP="${1:-api}"
SCENARIO="${2:-all}"

case "$APP" in
  api)     HOST="http://api.simxstudio.test" ;;
  admin)   HOST="http://admin.simxstudio.test" ;;
  leading) HOST="http://simxstudio.test" ;;
  *)       echo "Unknown app: $APP. Use: api | admin | leading"; exit 1 ;;
esac

REPORT_DIR="./reports/$(date +%Y%m%d-%H%M%S)-${APP}"
mkdir -p "$REPORT_DIR"

echo ""
echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║       SimXStudio Autoscale Load Test                         ║"
echo "║  Target : $HOST"
echo "║  Scenario: $SCENARIO"
echo "║  Reports : $REPORT_DIR"
echo "╚═══════════════════════════════════════════════════════════════╝"
echo ""

# Verify k6 installed
if ! command -v k6 &>/dev/null; then
  echo "❌  k6 not found. Install: brew install k6"
  exit 1
fi

# Verify target is reachable
echo "🔎  Checking target reachability..."
if ! curl -sf --max-time 5 "${HOST}/up" >/dev/null 2>&1; then
  echo "⚠️   WARNING: ${HOST}/up did not respond. Make sure:"
  echo "    1. kubectl port-forward or ingress is up"
  echo "    2. /etc/hosts has the required entries"
  echo "    3. The app pod is Running (kubectl get pods -n simxstudio)"
  echo ""
  read -p "Continue anyway? [y/N] " -n 1 -r
  echo
  [[ ! $REPLY =~ ^[Yy]$ ]] && exit 1
fi

# Watch HPA in background
echo "👁️   Watching HPA (Ctrl+C to quit after test)..."
kubectl get hpa -n simxstudio -w > "$REPORT_DIR/hpa-watch.log" 2>&1 &
HPA_WATCH_PID=$!

# Watch pod count in background
kubectl get pods -n simxstudio -w > "$REPORT_DIR/pods-watch.log" 2>&1 &
PODS_WATCH_PID=$!

echo "🚀  Starting k6 test..."
k6 run \
  --out json="$REPORT_DIR/results.json" \
  -e TARGET_HOST="$HOST" \
  autoscale-test.js \
  2>&1 | tee "$REPORT_DIR/k6-output.log"

STATUS=$?

# Stop background watchers
kill "$HPA_WATCH_PID" "$PODS_WATCH_PID" 2>/dev/null || true

echo ""
echo "═══════════════════════════════════════════════════════════════"
echo "📊  Test complete. Reports saved to: $REPORT_DIR"
echo ""
echo "Quick summary:"
grep -E "(http_req_duration|http_error_rate|checks|iterations)" "$REPORT_DIR/k6-output.log" | tail -20
echo ""
echo "HPA events during test:"
kubectl describe hpa -n simxstudio 2>/dev/null | grep -A5 "Events" || true
echo "═══════════════════════════════════════════════════════════════"

exit $STATUS
