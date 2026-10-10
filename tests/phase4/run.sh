#!/usr/bin/env bash
# Phase 4 — the two MCP servers, the bridge, the four shares, app_roles, the gate, the registry: tests/phase4/run.sh
# A fresh SCRATCH database (dc_dev10 — never the installed one) with a SCRATCH store, the application on :8701 (php -S, or DC_APP=apache: a real Apache, the vhost's internal port :8707), a fake kernel (:8702 — run-facts, K6),
# a fake MaluDB + MaluMail (:8706), and the servers under test on :8704 (records) and :8705 (activity). The fail-closed proofs stop the fake kernel for a moment. NOTHING REAL IS TOUCHED: no live service, Apache, unit,
# database or role password. DC_PROOFS="gate wall" runs some; DC_ENGINE=1 adds the real MaluDB API on :8703 (+ its fake model provider on :8707) over a scratch memory database — the recipe of slice 5.
# Needs `sudo -n -u postgres`, php, and a Python venv with FastMCP (see mcp_servers.sh). Exit 0 only when every proof passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export DC_DEV_DB=${DC_DEV_DB:-dc_dev10} DC_DEV_ENV=${DC_DEV_ENV:-/tmp/dc-dev10.env} DC_DEV_STATE=${DC_DEV_STATE:-/tmp/dc-dev10-state} DC_STORE=${DC_STORE:-/tmp/dc-dev10-storage}
export DC_ENGINE=${DC_ENGINE:-0}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
mkdir -p "$DC_DEV_STATE"
if [ "${DC_STOP:-0}" = 1 ]; then "$ROOT/tests/phase4/mcp_servers.sh" stop; "$ROOT/tests/phase2/servers.sh" stop; echo stopped; exit 0; fi
rm -rf "$DC_STORE"; mkdir -p "$DC_STORE"; rm -f "$DC_DEV_STATE"/world*.json
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
sed -i "s#^DC_STORAGE_DIR=.*#DC_STORAGE_DIR=$DC_STORE#" "$DC_DEV_ENV"
[ "${DC_APP:-php}" = "apache" ] && sed -i "s#^APP_INTERNAL_PORT=.*#APP_INTERNAL_PORT=8707#" "$DC_DEV_ENV"
cd "$ROOT"
status=0
MEMDB=dc_dev10_memory; MEMROLE=dc_dev10_memory; P_API=8703; P_FAKE=8707
PY_API=/home/maludb/maludb-python-api-server/.venv/bin/python; API_DIR=/home/maludb/maludb-python-api-server
ENGINE_DIR="$DC_DEV_STATE/engine"; ENGINE_PIDS=(); ENGINE_CREATED=0
PSQLE=(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -qtA)
engine_stop() {
  for p in "${ENGINE_PIDS[@]:-}"; do [ -n "$p" ] && kill "$p" 2>/dev/null; done
  sleep 0.5
  for p in "${ENGINE_PIDS[@]:-}"; do [ -n "$p" ] && kill -9 "$p" 2>/dev/null; done
  if [ "$ENGINE_CREATED" = 1 ]; then
    "${PSQLE[@]}" -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$MEMDB'" >/dev/null 2>&1
    "${PSQLE[@]}" -d postgres -c "DROP DATABASE IF EXISTS $MEMDB" >/dev/null 2>&1 || echo "WARN: could not drop $MEMDB"
    "${PSQLE[@]}" -d postgres -c "DROP ROLE IF EXISTS $MEMROLE" >/dev/null 2>&1 || true
  fi
}
cleanup() { [ "${DC_KEEP:-0}" = 1 ] && { echo "== left running (DC_KEEP=1): DC_STOP=1 bash $0 stops them"; return; }; "$ROOT/tests/phase4/mcp_servers.sh" stop >/dev/null 2>&1; "$ROOT/tests/phase2/servers.sh" stop >/dev/null 2>&1; engine_stop; rm -rf "$ROOT/storage/proof"; }
trap cleanup EXIT INT TERM
if [ "$DC_ENGINE" = 1 ]; then
  [ -x "$PY_API" ] && [ -d "$API_DIR/app" ] || { echo "ABORT: the MaluDB API checkout or its venv is missing"; exit 2; }
  for p in $P_API $P_FAKE; do ss -ltn "sport = :$p" | grep -q LISTEN && { echo "ABORT: port $p is in use"; exit 2; }; done
  "${PSQLE[@]}" -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$MEMDB'" >/dev/null 2>&1
  "${PSQLE[@]}" -d postgres -c "DROP DATABASE IF EXISTS $MEMDB" >/dev/null 2>&1; "${PSQLE[@]}" -d postgres -c "DROP ROLE IF EXISTS $MEMROLE" >/dev/null 2>&1
  rm -rf "$ENGINE_DIR"; mkdir -p "$ENGINE_DIR/log"; chmod 700 "$ENGINE_DIR"
  MEMPASS="d10_$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  ENGINE_CREATED=1
  "${PSQLE[@]}" -d postgres -c "CREATE DATABASE $MEMDB" >/dev/null && "${PSQLE[@]}" -d $MEMDB -c "CREATE EXTENSION maludb_core CASCADE" >/dev/null \
    && "${PSQLE[@]}" -d $MEMDB -c "CREATE USER $MEMROLE PASSWORD '$MEMPASS'" >/dev/null && "${PSQLE[@]}" -d $MEMDB -c "CREATE SCHEMA $MEMROLE AUTHORIZATION $MEMROLE" >/dev/null \
    && "${PSQLE[@]}" -d $MEMDB -c "SELECT * FROM maludb_core.enable_memory_schema('$MEMROLE')" >/dev/null && "${PSQLE[@]}" -d $MEMDB -c "SELECT maludb_core.grant_memory_access('$MEMROLE', 'write')" >/dev/null \
    && "${PSQLE[@]}" -d postgres -c "GRANT maludb_secret_consumer TO $MEMROLE" >/dev/null || { echo "ABORT: the scratch memory database could not be made"; exit 2; }
  export MALUDB_AUTH_STORE="$ENGINE_DIR/auth.db" MALUDB_PG_HOST=127.0.0.1 MALUDB_LOG_DIR="$ENGINE_DIR/log" MALUDB_HTTP_TIMEOUT=20
  unset MALUDB_STORE_KEY MALUDB_EMBED_BASE_URL MALUDB_EMBED_TOKEN MALUDB_EMBED_MODEL MALUDB_LLM_TOKEN
  "$PY_API" "$ROOT/tests/phase0/engine/fake_provider.py" $P_FAKE >"$ENGINE_DIR/fake.log" 2>&1 & ENGINE_PIDS+=($!)
  (cd "$API_DIR" && exec "$PY_API" -B -m uvicorn app.main:app --host 127.0.0.1 --port $P_API --log-level warning) >"$ENGINE_DIR/api.log" 2>&1 & ENGINE_PIDS+=($!)
  up=0; for i in $(seq 1 60); do curl -sf "http://127.0.0.1:$P_API/health" >/dev/null && curl -sf "http://127.0.0.1:$P_FAKE/health" >/dev/null && up=1 && break; sleep 0.5; done
  [ $up = 1 ] || { tail -20 "$ENGINE_DIR"/*.log; echo "ABORT: the engine did not start"; exit 2; }
  TOKEN=$(curl -s -X POST "http://127.0.0.1:$P_API/v1/tokens" -H 'Content-Type: application/json' -d "{\"pg_dbname\":\"$MEMDB\",\"pg_user\":\"$MEMROLE\",\"pg_password\":\"$MEMPASS\",\"device_name\":\"doccloud-phase4\"}" | "$PY_API" -c 'import json,sys;print(json.load(sys.stdin).get("token",""))')
  [ -n "$TOKEN" ] || { echo "ABORT: no token from the scratch API"; exit 2; }
  sed -i "s#^MALUDB_DC_API_URL=.*#MALUDB_DC_API_URL=http://127.0.0.1:$P_API#; s#^MALUDB_DC_API_TOKEN=.*#MALUDB_DC_API_TOKEN=$TOKEN#" "$DC_DEV_ENV"
  export DC_FAKE_PROVIDER="http://127.0.0.1:$P_FAKE" DC_ENGINE_API="http://127.0.0.1:$P_API"
  echo "== the engine: the real API on :$P_API over $MEMDB, the fake provider on :$P_FAKE"
fi
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
set -a; . "$DC_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$DC_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$DC_DEV_STATE/maludb.log" FAKE_MALUMAIL_LOG="$DC_DEV_STATE/malumail.log"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
"$ROOT/tests/phase4/mcp_servers.sh" start >/dev/null || { echo "mcp servers failed"; exit 1; }
: > "$DC_DEV_STATE/p4-called.txt"
mkdir -p storage/proof
proofs=${DC_PROOFS-gate bridge wall libraries documents access tags_search graph people shares activity registry coverage}
for p in $proofs; do
  echo "== $p"
  php "tests/phase4/$p.php" || status=1
done
[ $status -eq 0 ] && echo "ALL PHASE 4 PROOFS PASSED" || echo "SOME PHASE 4 PROOFS FAILED"
exit $status
