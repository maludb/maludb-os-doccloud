#!/usr/bin/env bash
# Slice 5's proof — the document graph (docs/build-specs/graph.md "Proof") — on a fresh SCRATCH database (dc_dev5) with a SCRATCH store, the app on :8701,
# the fake kernel on :8702, the fake MaluDB + MaluMail on :8706. Two modes:
#   bash tests/phase3/slice5/run.sh               WITHOUT the engine: graph_unavailable everywhere, keyword search still working, the queue waiting
#   DC_ENGINE=1 bash tests/phase3/slice5/run.sh   WITH the engine: the real MaluDB API code (/home/maludb/maludb-python-api-server) on :8703 over a scratch
#                                                 memory database (dc_dev5_memory, maludb_core, created and dropped here) and Phase 0's fake model provider on
#                                                 :8704 (tests/phase0/engine/fake_provider.py) — the recipe of tests/phase0/engine.sh; the token minted here is
#                                                 written into the scratch env as MALUDB_DC_API_URL / MALUDB_DC_API_TOKEN. The live API on :8000 is never touched.
# DC_ONLY="find json" runs some. Exit 0 only when every proof passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export DC_DEV_DB=${DC_DEV_DB:-dc_dev5} DC_DEV_ENV=${DC_DEV_ENV:-/tmp/dc-dev5.env} DC_DEV_STATE=${DC_DEV_STATE:-/tmp/dc-dev5-state} SHOTS=${SHOTS:-/tmp/dc-shots} DC_STORE=${DC_STORE:-/tmp/dc-dev5-storage}
export DC_ENGINE=${DC_ENGINE:-0}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
mkdir -p "$SHOTS" "$DC_DEV_STATE"
if [ "${DC_STOP:-0}" != 1 ]; then
rm -rf "$DC_STORE"; mkdir -p "$DC_STORE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
fi
sed -i "s#^DC_STORAGE_DIR=.*#DC_STORAGE_DIR=$DC_STORE#" "$DC_DEV_ENV"
status=0
cd "$ROOT"
ONLY=${DC_ONLY:-schema policy queue find explore admin json browser registry}
has() { [[ " $ONLY " == *" $1 "* ]]; }

# ---- the engine (DC_ENGINE=1): a scratch memory database, the API, the fake provider, a token ----
MEMDB=dc_dev5_memory; MEMROLE=dc_dev5_memory; P_API=8703; P_FAKE=8704
PY=/home/maludb/maludb-python-api-server/.venv/bin/python; API_DIR=/home/maludb/maludb-python-api-server
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
if [ "$DC_ENGINE" = 1 ] && [ "${DC_STOP:-0}" != 1 ]; then
  [ -x "$PY" ] && [ -d "$API_DIR/app" ] || { echo "ABORT: the MaluDB API checkout or its venv is missing"; exit 2; }
  for p in $P_API $P_FAKE; do ss -ltn "sport = :$p" | grep -q LISTEN && { echo "ABORT: port $p is in use"; exit 2; }; done
  "${PSQLE[@]}" -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$MEMDB'" >/dev/null 2>&1
  "${PSQLE[@]}" -d postgres -c "DROP DATABASE IF EXISTS $MEMDB" >/dev/null 2>&1; "${PSQLE[@]}" -d postgres -c "DROP ROLE IF EXISTS $MEMROLE" >/dev/null 2>&1
  rm -rf "$ENGINE_DIR"; mkdir -p "$ENGINE_DIR/log"; chmod 700 "$ENGINE_DIR"
  MEMPASS="d5_$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  ENGINE_CREATED=1
  "${PSQLE[@]}" -d postgres -c "CREATE DATABASE $MEMDB" >/dev/null && "${PSQLE[@]}" -d $MEMDB -c "CREATE EXTENSION maludb_core CASCADE" >/dev/null \
    && "${PSQLE[@]}" -d $MEMDB -c "CREATE USER $MEMROLE PASSWORD '$MEMPASS'" >/dev/null && "${PSQLE[@]}" -d $MEMDB -c "CREATE SCHEMA $MEMROLE AUTHORIZATION $MEMROLE" >/dev/null \
    && "${PSQLE[@]}" -d $MEMDB -c "SELECT * FROM maludb_core.enable_memory_schema('$MEMROLE')" >/dev/null && "${PSQLE[@]}" -d $MEMDB -c "SELECT maludb_core.grant_memory_access('$MEMROLE', 'write')" >/dev/null \
    && "${PSQLE[@]}" -d postgres -c "GRANT maludb_secret_consumer TO $MEMROLE" >/dev/null || { echo "ABORT: the scratch memory database could not be made"; engine_stop; exit 2; }
  export MALUDB_AUTH_STORE="$ENGINE_DIR/auth.db" MALUDB_PG_HOST=127.0.0.1 MALUDB_LOG_DIR="$ENGINE_DIR/log" MALUDB_HTTP_TIMEOUT=20
  unset MALUDB_STORE_KEY MALUDB_EMBED_BASE_URL MALUDB_EMBED_TOKEN MALUDB_EMBED_MODEL MALUDB_LLM_TOKEN
  "$PY" "$ROOT/tests/phase0/engine/fake_provider.py" $P_FAKE >"$ENGINE_DIR/fake.log" 2>&1 & ENGINE_PIDS+=($!)
  (cd "$API_DIR" && exec "$PY" -B -m uvicorn app.main:app --host 127.0.0.1 --port $P_API --log-level warning) >"$ENGINE_DIR/api.log" 2>&1 & ENGINE_PIDS+=($!)
  up=0; for i in $(seq 1 60); do curl -sf "http://127.0.0.1:$P_API/health" >/dev/null && curl -sf "http://127.0.0.1:$P_FAKE/health" >/dev/null && up=1 && break; sleep 0.5; done
  [ $up = 1 ] || { tail -20 "$ENGINE_DIR"/*.log; echo "ABORT: the engine did not start"; engine_stop; exit 2; }
  TOKEN=$(curl -s -X POST "http://127.0.0.1:$P_API/v1/tokens" -H 'Content-Type: application/json' -d "{\"pg_dbname\":\"$MEMDB\",\"pg_user\":\"$MEMROLE\",\"pg_password\":\"$MEMPASS\",\"device_name\":\"doccloud-slice5\"}" | "$PY" -c 'import json,sys;print(json.load(sys.stdin).get("token",""))')
  [ -n "$TOKEN" ] || { echo "ABORT: no token from the scratch API"; engine_stop; exit 2; }
  sed -i "s#^MALUDB_DC_API_URL=.*#MALUDB_DC_API_URL=http://127.0.0.1:$P_API#; s#^MALUDB_DC_API_TOKEN=.*#MALUDB_DC_API_TOKEN=$TOKEN#" "$DC_DEV_ENV"
  export DC_FAKE_PROVIDER="http://127.0.0.1:$P_FAKE" DC_ENGINE_API="http://127.0.0.1:$P_API"
  echo "== the engine: the real API on :$P_API over $MEMDB, the fake provider on :$P_FAKE"
fi
# DC_KEEP=1 leaves the servers and the engine running at the end (to look at a failure by hand: `set -a; . $DC_DEV_ENV; set +a` and
# `php -r 'require "tests/phase3/slice5/lib.php"; …'`); stop them with `DC_STOP=1 bash tests/phase3/slice5/run.sh`.
cleanup() { [ "${DC_KEEP:-0}" = 1 ] && { printf '%s\n' "${ENGINE_PIDS[@]:-}" > "$DC_DEV_STATE/engine.pids"; echo "== left running (DC_KEEP=1): the app, the fake kernel, the engine (pids in $DC_DEV_STATE/engine.pids); DC_STOP=1 bash $0 stops them"; return; }; "$ROOT/tests/phase2/servers.sh" stop >/dev/null 2>&1; engine_stop; }
if [ "${DC_STOP:-0}" = 1 ]; then
  "$ROOT/tests/phase2/servers.sh" stop >/dev/null 2>&1
  [ -f "$DC_DEV_STATE/engine.pids" ] && { mapfile -t ENGINE_PIDS < "$DC_DEV_STATE/engine.pids"; ENGINE_CREATED=1; engine_stop; rm -f "$DC_DEV_STATE/engine.pids"; }
  echo "stopped"; exit 0
fi
trap cleanup EXIT INT TERM

if has schema; then
  echo "== the schema (db/proof/phase0_proof.sql, rolled back)"
  out=$(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$DC_DEV_DB" -f db/proof/phase0_proof.sql 2>&1); summary=$(echo "$out" | grep -E '[0-9]+ ok, [0-9]+ failed' | head -1 | sed 's/^ *//')
  if echo "$summary" | grep -q ' 0 failed'; then echo "  ok   schema proof: $summary"; else echo "  FAIL schema proof: $summary"; echo "$out" | grep -E '^ *FAIL|ERROR' | head -5; status=1; fi
fi
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
set -a; . "$DC_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$DC_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$DC_DEV_STATE/maludb.log"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
for p in policy queue find explore admin json; do
  has $p || continue
  echo "== $p"; php "tests/phase3/slice5/$p.php" || status=1
done
if has browser; then
  # the JSON proof's rebuild and retry leave jobs queued: the worker settles the graph before the browser reads it
  [ "$DC_ENGINE" = 1 ] && { php bin/worker.php --passes=graph_jobs >/dev/null 2>&1; php bin/worker.php --passes=graph_jobs >/dev/null 2>&1; }
  echo "== browser"; node tests/phase3/slice5/browser.mjs || status=1
fi
if has registry; then
  echo "== registry"
  php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
  php -r '$r = json_decode(file_get_contents("mcp/action_registry.json"), true); $ids = ["graph","graph-explore","subject-view","graph-path","graph-admin"]; $b = array_filter($ids, fn($i) => !empty($r["screens"][$i]["built"])); $names = ["graph_policy_set","graph_budget_set","graph_model_set","graph_rebuild_all","graph_job_retry","graph_job_skip","graph_test_extraction"]; $a = array_filter($r["actions"], fn($x) => in_array($x["action"], $names, true)); $ab = array_filter($a, fn($x) => $x["built"]); $paused = []; foreach ($a as $x) { $c = $x["approval"] ?? $x["agent_approval"] ?? ""; if ($c !== "") $paused[$x["action"]] = $c; } ksort($paused); $stubs = array_filter($r["screens"], fn($x) => !empty($x["stub"])); $ok = count($b) === 5 && count($ab) === 7 && $paused === ["graph_budget_set" => "other", "graph_model_set" => "other", "graph_policy_set" => "other", "graph_rebuild_all" => "other"] && count($stubs) <= 3; echo ($ok ? "  ok   " : "  FAIL ") . "the registry marks the 5 screens and " . count($ab) . "/7 actions of slice 5 built, four paused (other), " . count($stubs) . " placeholders left\n"; exit($ok ? 0 : 1);' || status=1
fi
log=""; for f in "$DC_DEV_STATE/app.log" "$DC_DEV_STATE/apache/apache.err"; do [ -f "$f" ] && log="$log$(cat "$f")"; done
if echo "$log" | grep -Eq 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught'; then echo "  FAIL PHP warnings in the server log:"; echo "$log" | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught' | cut -c1-220 | sort | uniq -c | head -5; status=1; else echo "  ok   no PHP warning, notice or uncaught error in the server log"; fi
[ $status -eq 0 ] && echo "ALL SLICE 5 PROOFS PASSED (engine: $DC_ENGINE)" || echo "SOME SLICE 5 PROOFS FAILED (engine: $DC_ENGINE)"
exit $status
