#!/usr/bin/env bash
# The large-uploads proof (docs/build-specs/large-uploads.md "Proof") — on a fresh SCRATCH database (dc_devb) with a SCRATCH store
# (/tmp/dc-devb-storage through DC_STORAGE_DIR), the app on :8701 (php -S with tests/dev_router.php; DC_APP=apache serves the rendered vhost),
# the fake kernel on :8702, the fake MaluDB + MaluMail on :8706: a 2.5 GB file through the parts routes, resume, the store's room, the export,
# the browser with a 2.1 GB file, the registry. Needs ~10 GB free on /tmp's filesystem. DC_ONLY="large" runs some. Exit 0 only when all pass.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export DC_DEV_DB=${DC_DEV_DB:-dc_devb} DC_DEV_ENV=${DC_DEV_ENV:-/tmp/dc-devb.env} DC_DEV_STATE=${DC_DEV_STATE:-/tmp/dc-devb-state} SHOTS=${SHOTS:-/tmp/dc-shots} DC_STORE=${DC_STORE:-/tmp/dc-devb-storage}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
free=$(df --output=avail -B1 /tmp | tail -1); [ "$free" -gt 10737418240 ] || { echo "ABORT: /tmp's filesystem has less than 10 GB free"; exit 2; }
mkdir -p "$SHOTS"
rm -rf "$DC_STORE"; mkdir -p "$DC_STORE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
sed -i "s#^DC_STORAGE_DIR=.*#DC_STORAGE_DIR=$DC_STORE#" "$DC_DEV_ENV"
status=0
cd "$ROOT"
ONLY=${DC_ONLY:-large browser registry}
has() { [[ " $ONLY " == *" $1 "* ]]; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop; rm -rf "$DC_STORE"' EXIT
set -a; . "$DC_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$DC_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$DC_DEV_STATE/maludb.log"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
if has large; then echo "== large"; php tests/phase3/bigfiles/large.php || status=1; fi
if has browser; then echo "== browser"; node tests/phase3/bigfiles/browser.mjs || status=1; fi
if has registry; then
  echo "== registry"
  php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
fi
log=""; for f in "$DC_DEV_STATE/app.log" "$DC_DEV_STATE/apache/apache.err"; do [ -f "$f" ] && log="$log$(cat "$f")"; done
if echo "$log" | grep -Eq 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught'; then echo "  FAIL PHP warnings in the server log:"; echo "$log" | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught' | cut -c1-220 | sort | uniq -c | head -5; status=1; else echo "  ok   no PHP warning, notice or uncaught error in the server log"; fi
[ $status -eq 0 ] && echo "ALL LARGE-UPLOADS PROOFS PASSED" || echo "SOME LARGE-UPLOADS PROOFS FAILED"
exit $status
