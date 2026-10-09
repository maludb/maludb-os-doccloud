#!/usr/bin/env bash
# Runs every Phase 2 proof on a fresh SCRATCH database (never the installed one): tests/phase2/run.sh
#   DC_APP=apache tests/phase2/run.sh   serves the app from a real Apache with the rendered deploy vhost instead of php -S
#   needs: `sudo -n -u postgres`, php, node with the kernel's web/node_modules (Playwright + its Chromium, for the browser proof).
#   Screenshots go to $SHOTS (default /tmp/dc-shots). Ends with one line per proof and exit status 0 only if all passed.
#   (Phase 0's kit proof, tests/phase0/run.sh, is separate: it builds its own scratch database on the same ports — run them one after the other.)
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export DC_DEV_DB=${DC_DEV_DB:-dc_dev2} DC_DEV_ENV=${DC_DEV_ENV:-/tmp/dc-dev2.env} DC_DEV_STATE=${DC_DEV_STATE:-/tmp/dc-dev2-state} SHOTS=${SHOTS:-/tmp/dc-shots}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
mkdir -p "$SHOTS"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
status=0
cd "$ROOT"
echo "== the schema (db/proof/phase0_proof.sql on the empty scratch database, rolled back)"
out=$(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$DC_DEV_DB" -f db/proof/phase0_proof.sql 2>&1); summary=$(echo "$out" | grep -E '[0-9]+ ok, [0-9]+ failed' | head -1 | sed 's/^ *//')
if echo "$summary" | grep -q ' 0 failed'; then echo "  ok   schema proof: $summary"; else echo "  FAIL schema proof: $summary"; echo "$out" | grep -E '^ *FAIL|ERROR' | head -5; status=1; fi
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$DC_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$DC_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$DC_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
echo "== browser"; node tests/phase2/browser.mjs || status=1
for p in gates vhost picker switcher store; do
  echo "== $p"; php "tests/phase2/$p.php" || status=1
done
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL PHASE 2 PROOFS PASSED" || echo "SOME PHASE 2 PROOFS FAILED"
exit $status
