#!/usr/bin/env bash
# The DOCUMENT GRAPH proof (Phase 0): app/doccloud/graph.php against the REAL MaluDB API on a SCRATCH memory database.
#
# Re-run:   bash /srv/apps/doccloud/tests/phase0/engine.sh          (as maludb; exit 0 = every check passed)
#
# What it needs
#   * passwordless `sudo -n -u postgres` (to create and drop the scratch database and role), PostgreSQL 17 on 127.0.0.1
#     with maludb_core available and password logins over TCP;
#   * the MaluDB API checkout /home/maludb/maludb-python-api-server (API 0.3.1) run with its .venv python;
#   * php 8.3 with curl; free ports 8703 (the API), 8704 (the fake model provider).
# What it does
#   1. refuses to start if the database or role `doccloud_engine_scratch` already exists, or a port is taken;
#   2. creates the scratch memory database (README §2: database, CREATE EXTENSION maludb_core, login role + schema of the same
#      name, enable_memory_schema) plus the two steps README §2 omits — grant_memory_access and GRANT maludb_secret_consumer
#      (Knowledge's lesson, 2026-10-05); a random password is kept in tests/phase0/engine/scratch/ (gitignored);
#   3. starts the fake provider (engine/fake_provider.py) and the API with MALUDB_AUTH_STORE pointing at a private sqlite file in
#      that scratch directory, and mints a token by proving the login (POST /v1/tokens);
#   4. runs engine/proof.php, which drives ONLY app/doccloud/graph.php and prints PASS/FAIL/NOTE lines;
#   5. on exit, whatever happened (trap): stops both servers, drops the database and the role, removes the scratch dir.
# Never touched: the live API on :8000, its auth store, the live tenant databases, the installed application, any /etc file.
# The role is `doccloud_engine_scratch`, not `doccloud_mem` (the installed application's role-to-be; role names are cluster-wide).
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENG="$HERE/engine"
SCRATCH="$ENG/scratch"
API_DIR=${DC_API_DIR:-/home/maludb/maludb-python-api-server}
PY=${DC_API_PY:-$API_DIR/.venv/bin/python}
DB=doccloud_engine_scratch
ROLE=doccloud_engine_scratch
P_API=8703; P_FAKE=8704
PSQL=(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -qtA)
PIDS=()
CREATED=0

say() { printf '== %s\n' "$*"; }
die() { printf 'ABORT: %s\n' "$*" >&2; exit 2; }

teardown() {
  local rc=$?
  for p in "${PIDS[@]:-}"; do [ -n "$p" ] && kill "$p" 2>/dev/null; done
  sleep 1
  for p in "${PIDS[@]:-}"; do [ -n "$p" ] && kill -9 "$p" 2>/dev/null; done
  if [ "$CREATED" = 1 ]; then
    "${PSQL[@]}" -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$DB'" >/dev/null 2>&1
    "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS $DB" >/dev/null 2>&1 || echo "WARN: could not drop database $DB"
    "${PSQL[@]}" -d postgres -c "DROP ROLE IF EXISTS $ROLE" >/dev/null 2>&1 || echo "WARN: could not drop role $ROLE"
    left=$("${PSQL[@]}" -d postgres -c "SELECT (SELECT count(*) FROM pg_database WHERE datname='$DB') + (SELECT count(*) FROM pg_roles WHERE rolname='$ROLE')")
    [ "$left" = 0 ] && say "scratch database and role dropped" || echo "WARN: scratch objects remain ($left)"
  fi
  rm -rf "$SCRATCH"
  exit $rc
}
trap teardown EXIT INT TERM

# ---- 1. preconditions -------------------------------------------------------------------------------------------
command -v php >/dev/null || die "php not found"
php -r 'exit(function_exists("curl_init") ? 0 : 1);' || die "php curl extension missing"
[ -x "$PY" ] || die "$PY missing"
[ -d "$API_DIR/app" ] || die "$API_DIR (the MaluDB API checkout) missing"
sudo -n -u postgres true 2>/dev/null || die "passwordless sudo -u postgres is required"
exists=$("${PSQL[@]}" -d postgres -c "SELECT (SELECT count(*) FROM pg_database WHERE datname='$DB') + (SELECT count(*) FROM pg_roles WHERE rolname='$ROLE')")
[ "$exists" = 0 ] || die "database or role $DB already exists — not mine to drop; remove it by hand after checking"
for p in $P_API $P_FAKE; do
  ss -ltn "sport = :$p" | grep -q LISTEN && die "port $p is in use"
done

# ---- 2. the scratch memory database (README §2 + the two omitted steps) -------------------------------------------
rm -rf "$SCRATCH"; mkdir -p "$SCRATCH"; chmod 700 "$SCRATCH"
PASS="des_$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
CREATED=1
say "creating scratch memory database $DB"
"${PSQL[@]}" -d postgres -c "CREATE DATABASE $DB" >/dev/null || die "createdb failed"
"${PSQL[@]}" -d $DB -c "CREATE EXTENSION maludb_core CASCADE" >/dev/null || die "CREATE EXTENSION failed"
"${PSQL[@]}" -d $DB -c "CREATE USER $ROLE PASSWORD '$PASS'" >/dev/null || die "CREATE USER failed"
"${PSQL[@]}" -d $DB -c "CREATE SCHEMA $ROLE AUTHORIZATION $ROLE" >/dev/null || die "CREATE SCHEMA failed"
"${PSQL[@]}" -d $DB -c "SELECT * FROM maludb_core.enable_memory_schema('$ROLE')" >/dev/null || die "enable_memory_schema failed"
"${PSQL[@]}" -d $DB -c "SELECT maludb_core.grant_memory_access('$ROLE', 'write')" >/dev/null || die "grant_memory_access failed"
"${PSQL[@]}" -d postgres -c "GRANT maludb_secret_consumer TO $ROLE" >/dev/null || die "GRANT maludb_secret_consumer failed"
say "maludb_core $("${PSQL[@]}" -d $DB -c "SELECT maludb_core.maludb_core_version()")"

# ---- 3. servers --------------------------------------------------------------------------------------------------
export MALUDB_AUTH_STORE="$SCRATCH/auth.db" MALUDB_PG_HOST=127.0.0.1 MALUDB_LOG_DIR="$SCRATCH/log" MALUDB_HTTP_TIMEOUT=20
unset MALUDB_STORE_KEY MALUDB_EMBED_BASE_URL MALUDB_EMBED_TOKEN MALUDB_EMBED_MODEL MALUDB_LLM_TOKEN
mkdir -p "$SCRATCH/log"
"$PY" "$ENG/fake_provider.py" $P_FAKE >"$SCRATCH/fake.log" 2>&1 & PIDS+=($!); disown $!
(cd "$API_DIR" && exec "$PY" -B -m uvicorn app.main:app --host 127.0.0.1 --port $P_API --log-level warning) >"$SCRATCH/api.log" 2>&1 & PIDS+=($!); disown $!
for i in $(seq 1 60); do
  ok=0
  curl -sf "http://127.0.0.1:$P_API/health" >/dev/null && curl -sf "http://127.0.0.1:$P_FAKE/health" >/dev/null && ok=1
  [ $ok = 1 ] && break; sleep 0.5
done
[ $ok = 1 ] || { for f in "$SCRATCH"/*.log; do echo "--- $f"; tail -n 20 "$f"; done; die "servers did not start"; }
say "API on :$P_API ($(curl -s "http://127.0.0.1:$P_API/openapi.json" | "$PY" -c 'import json,sys;p=json.load(sys.stdin)["paths"];print(len(p),"routes;", "with" if "/v1/memory/answer" in p else "without", "the answer route")')), fake provider on :$P_FAKE"

TOKEN=$(curl -s -X POST "http://127.0.0.1:$P_API/v1/tokens" -H 'Content-Type: application/json' \
  -d "{\"pg_dbname\":\"$DB\",\"pg_user\":\"$ROLE\",\"pg_password\":\"$PASS\",\"device_name\":\"doccloud-graph-proof\"}" \
  | "$PY" -c 'import json,sys;print(json.load(sys.stdin).get("token",""))')
[ -n "$TOKEN" ] || die "could not mint a token"
say "token minted on the scratch auth store"

# ---- 4. the proof ------------------------------------------------------------------------------------------------
DC_PROOF_API_URL="http://127.0.0.1:$P_API" DC_PROOF_FAKE_URL="http://127.0.0.1:$P_FAKE" DC_PROOF_TOKEN="$TOKEN" \
DC_PROOF_DB="$DB" DC_PROOF_ROLE="$ROLE" php "$ENG/proof.php"
rc=$?
if [ $rc -ne 0 ]; then echo "--- api.log (tail)"; tail -30 "$SCRATCH/api.log"; fi
exit $rc
