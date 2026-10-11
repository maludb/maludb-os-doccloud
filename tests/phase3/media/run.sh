#!/usr/bin/env bash
# The grid-and-media proof (docs/build-specs/grid-and-media.md "Proof") — on a fresh SCRATCH database (dc_devm) with a SCRATCH store
# (/tmp/dc-devm-storage through DC_STORAGE_DIR), the app on :8701 (php -S with tests/dev_router.php; DC_APP=apache serves the rendered vhost and
# adds the concurrent-stream check), the fake kernel on :8702, the fake MaluDB + MaluMail on :8706: thumbnails (ffmpeg, pdftoppm, GD), the two
# actions, the grid, serving, the browser at 375 and 1280, the registry. Needs ffmpeg. DC_ONLY="grid serving" runs some. Exit 0 only when all pass.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export DC_DEV_DB=${DC_DEV_DB:-dc_devm} DC_DEV_ENV=${DC_DEV_ENV:-/tmp/dc-devm.env} DC_DEV_STATE=${DC_DEV_STATE:-/tmp/dc-devm-state} SHOTS=${SHOTS:-/tmp/dc-shots} DC_STORE=${DC_STORE:-/tmp/dc-devm-storage}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
command -v ffmpeg >/dev/null 2>&1 || { echo "ABORT: ffmpeg is not installed (deploy/ROOT_STEPS.sh step 0c)"; exit 2; }
mkdir -p "$SHOTS"
rm -rf "$DC_STORE"; mkdir -p "$DC_STORE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
sed -i "s#^DC_STORAGE_DIR=.*#DC_STORAGE_DIR=$DC_STORE#" "$DC_DEV_ENV"
status=0
cd "$ROOT"
ONLY=${DC_ONLY:-thumbs actions grid serving types browser registry}
has() { [[ " $ONLY " == *" $1 "* ]]; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$DC_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$DC_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$DC_DEV_STATE/maludb.log"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
for p in thumbs actions grid serving types; do
  has $p || continue
  echo "== $p"; php "tests/phase3/media/$p.php" || status=1
done
if has browser; then echo "== browser"; node tests/phase3/media/browser.mjs || status=1; fi
if has registry; then
  echo "== registry"
  php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
fi
log=""; for f in "$DC_DEV_STATE/app.log" "$DC_DEV_STATE/apache/apache.err"; do [ -f "$f" ] && log="$log$(cat "$f")"; done
if echo "$log" | grep -Eq 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught'; then echo "  FAIL PHP warnings in the server log:"; echo "$log" | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught' | cut -c1-220 | sort | uniq -c | head -5; status=1; else echo "  ok   no PHP warning, notice or uncaught error in the server log"; fi
[ $status -eq 0 ] && echo "ALL GRID-AND-MEDIA PROOFS PASSED" || echo "SOME GRID-AND-MEDIA PROOFS FAILED"
exit $status
