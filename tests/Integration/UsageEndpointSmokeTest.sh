#!/usr/bin/env bash
# Integration smoke test for df-ai's /_internal/ai/usage endpoint.
#
# Runs against a live DF container at $DF_URL (default http://localhost:8080)
# using the admin credentials in $DF_ADMIN_EMAIL / $DF_ADMIN_PASSWORD.
#
# Usage:
#   bash tests/Integration/UsageEndpointSmokeTest.sh
#
# Exit code 0 on pass, non-zero on first failure.

set -euo pipefail

DF_URL="${DF_URL:-http://localhost:8080}"
DF_ADMIN_EMAIL="${DF_ADMIN_EMAIL:-admin@dreamfactory.com}"
DF_ADMIN_PASSWORD="${DF_ADMIN_PASSWORD:-passwordpassword}"

PASS=0
FAIL=0

ok() { printf "  [32mPASS[0m %s\n" "$1"; PASS=$((PASS+1)); }
fail() { printf "  [31mFAIL[0m %s\n     %s\n" "$1" "$2"; FAIL=$((FAIL+1)); }

# ---------------------------------------------------------------------------
# Setup: get an admin session token.
# ---------------------------------------------------------------------------
echo "==> Setup: authenticating as admin"
SESSION=$(curl -sk --max-time 10 -X POST \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"email\":\"${DF_ADMIN_EMAIL}\",\"password\":\"${DF_ADMIN_PASSWORD}\"}" \
  "${DF_URL}/api/v2/system/admin/session" \
  | python3 -c 'import sys,json; print(json.load(sys.stdin).get("session_token",""))')

if [ -z "$SESSION" ]; then
  fail "admin login" "could not obtain session token from ${DF_URL}"
  exit 1
fi
ok "admin login (token: ${SESSION:0:20}…)"

auth_curl() {
  curl -sk --max-time 30 \
    -H "X-DreamFactory-Session-Token: ${SESSION}" \
    -H "Accept: application/json" \
    "$@"
}

# ---------------------------------------------------------------------------
# Test: /_internal/ai/usage requires admin (no auth -> 403)
# ---------------------------------------------------------------------------
echo "==> Test: unauthenticated -> 403"
code=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 10 \
  -H "Accept: application/json" \
  "${DF_URL}/_internal/ai/usage")
if [ "$code" = "403" ]; then
  ok "unauthenticated request returns 403"
else
  fail "unauthenticated request -> ${code} (expected 403)"
fi

# ---------------------------------------------------------------------------
# Test: with admin auth -> 200 + expected top-level keys
# ---------------------------------------------------------------------------
echo "==> Test: admin -> 200 with expected shape"
auth_curl "${DF_URL}/_internal/ai/usage?period=7d" -o /tmp/df_usage.json -w "%{http_code}\n" > /tmp/df_usage_code.txt
code=$(cat /tmp/df_usage_code.txt)
if [ "$code" != "200" ]; then
  fail "admin GET /_internal/ai/usage" "status=${code}; body: $(head -c 200 /tmp/df_usage.json)"
else
  ok "admin GET returns 200"
fi

python3 -c '
import sys, json
d = json.load(open("/tmp/df_usage.json"))
required = ["period", "since", "total_requests", "total_input_tokens",
            "total_output_tokens", "errors", "avg_latency_ms",
            "by_service", "by_user", "by_role", "by_provider",
            "by_model", "by_resource", "series"]
missing = [k for k in required if k not in d]
sys.exit(1 if missing else 0)
' && ok "response has every expected top-level key" || fail "shape" "missing keys"

# ---------------------------------------------------------------------------
# Test: period filter changes the "since" timestamp
# ---------------------------------------------------------------------------
echo "==> Test: period filter changes since"
auth_curl "${DF_URL}/_internal/ai/usage?period=24h" -o /tmp/df_usage_24h.json
auth_curl "${DF_URL}/_internal/ai/usage?period=30d" -o /tmp/df_usage_30d.json
since_24=$(python3 -c "import json; print(json.load(open('/tmp/df_usage_24h.json'))['since'])")
since_30=$(python3 -c "import json; print(json.load(open('/tmp/df_usage_30d.json'))['since'])")

if [ "$since_24" != "$since_30" ]; then
  ok "period 24h since ($since_24) differs from 30d since ($since_30)"
else
  fail "period filter" "since identical between 24h and 30d"
fi

# ---------------------------------------------------------------------------
# Test: aggregations are arrays
# ---------------------------------------------------------------------------
echo "==> Test: aggregations are arrays"
python3 -c '
import sys, json
d = json.load(open("/tmp/df_usage.json"))
for k in ["by_service","by_user","by_role","by_provider","by_model","by_resource","series"]:
    if not isinstance(d[k], list):
        print(f"{k} is not a list (type={type(d[k]).__name__})")
        sys.exit(1)
sys.exit(0)
' && ok "by_* + series are all arrays" || fail "shape" "non-array aggregation key"

# ---------------------------------------------------------------------------
# Test: /_internal/ai/test-connection requires admin
# ---------------------------------------------------------------------------
echo "==> Test: test-connection without auth -> 403"
code=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 10 \
  -X POST -H "Content-Type: application/json" \
  -d '{"provider":"anthropic","api_key":"sk-test"}' \
  "${DF_URL}/_internal/ai/test-connection")
if [ "$code" = "403" ]; then
  ok "unauthenticated test-connection returns 403"
else
  fail "test-connection unauth" "got ${code}, expected 403"
fi

# ---------------------------------------------------------------------------
# Test: test-connection requires "provider" param
# ---------------------------------------------------------------------------
echo "==> Test: test-connection without provider -> 422"
code=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 10 \
  -X POST -H "Content-Type: application/json" \
  -H "X-DreamFactory-Session-Token: ${SESSION}" \
  -d '{}' \
  "${DF_URL}/_internal/ai/test-connection")
if [ "$code" = "422" ]; then
  ok "test-connection without provider returns 422"
else
  fail "test-connection no provider" "got ${code}, expected 422"
fi

# ---------------------------------------------------------------------------
# Test: per-connection usage endpoint (/api/v2/{conn}/usage) — only if a
# connection exists. Otherwise skip.
# ---------------------------------------------------------------------------
echo "==> Test: per-connection /api/v2/{conn}/usage"
auth_curl "${DF_URL}/api/v2/system/service?filter=type=%22ai_connection%22&fields=name&limit=1" \
  -o /tmp/df_conn.json
conn=$(python3 -c "import json; r=json.load(open('/tmp/df_conn.json')); print(r['resource'][0]['name'] if r.get('resource') else '')")
if [ -z "$conn" ]; then
  echo "  [33mSKIP[0m no AI connections configured"
else
  code=$(auth_curl -o /tmp/df_conn_usage.json -w "%{http_code}" \
    "${DF_URL}/api/v2/${conn}/usage?period=24h")
  if [ "$code" = "200" ]; then
    ok "per-connection usage endpoint works (conn=${conn})"
  else
    fail "per-connection usage" "got ${code}: $(head -c 200 /tmp/df_conn_usage.json)"
  fi
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
echo ""
echo "================================================================="
echo "  PASSED: ${PASS}    FAILED: ${FAIL}"
echo "================================================================="
exit $((FAIL > 0 ? 1 : 0))
