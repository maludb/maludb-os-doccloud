#!/usr/bin/env bash
# Slice 1's proof — libraries, groups and folders (docs/build-specs/libraries-groups-folders.md "Proof") — on a fresh SCRATCH database
# (dc_dev1), the app on :8701 (php -S with tests/dev_router.php; DC_APP=apache serves the rendered vhost instead), the fake kernel on :8702,
# the fake MaluDB + MaluMail on :8706: the schema proof, then folders, libraries, groups, favourites, json, the browser at 375 and 1280,
# the registry. DC_ONLY="folders json" runs some. Exit 0 only when every proof passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export DC_DEV_DB=${DC_DEV_DB:-dc_dev1} DC_DEV_ENV=${DC_DEV_ENV:-/tmp/dc-dev1.env} DC_DEV_STATE=${DC_DEV_STATE:-/tmp/dc-dev1-state} SHOTS=${SHOTS:-/tmp/dc-shots}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
mkdir -p "$SHOTS"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
status=0
cd "$ROOT"
ONLY=${DC_ONLY:-schema folders libraries groups favourites json browser registry}
has() { [[ " $ONLY " == *" $1 "* ]]; }
if has schema; then
  echo "== the schema (db/proof/phase0_proof.sql, rolled back)"
  out=$(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$DC_DEV_DB" -f db/proof/phase0_proof.sql 2>&1); summary=$(echo "$out" | grep -E '[0-9]+ ok, [0-9]+ failed' | head -1 | sed 's/^ *//')
  if echo "$summary" | grep -q ' 0 failed'; then echo "  ok   schema proof: $summary"; else echo "  FAIL schema proof: $summary"; echo "$out" | grep -E '^ *FAIL|ERROR' | head -5; status=1; fi
fi
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$DC_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$DC_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$DC_DEV_STATE/maludb.log"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
for p in folders libraries groups favourites json; do
  has $p || continue
  echo "== $p"; php "tests/phase3/slice1/$p.php" || status=1
done
if has browser; then echo "== browser"; node tests/phase3/slice1/browser.mjs || status=1; fi
if has registry; then
  echo "== registry"
  php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
  php -r '$r = json_decode(file_get_contents("mcp/action_registry.json"), true); $s = array_filter($r["screens"], fn($x) => in_array($x["screen"], ["my-files","library-list","library-view","library-add","library-edit","group-list","group-view","group-add","group-edit","group-members","folder","folder-add","folder-edit","folder-move","recent","favourites"], true)); $b = array_filter($s, fn($x) => $x["built"]); $a = array_filter($r["actions"], fn($x) => str_starts_with($x["section"], "Libraries, groups and folders") || in_array($x["action"], ["favourite_add","favourite_remove"], true)); $ab = array_filter($a, fn($x) => $x["built"]); $stubs = array_filter($r["screens"], fn($x) => !empty($x["stub"])); $ok = count($b) === 16 && count($ab) === count($a) && count($a) === 21 && count($stubs) === 7; echo ($ok ? "  ok   " : "  FAIL ") . "the registry marks the 16 screens and " . count($ab) . "/" . count($a) . " actions of slice 1 built, " . count($stubs) . " placeholders left\n"; exit($ok ? 0 : 1);' || status=1
fi
log=""; for f in "$DC_DEV_STATE/app.log" "$DC_DEV_STATE/apache/apache.err"; do [ -f "$f" ] && log="$log$(cat "$f")"; done
if echo "$log" | grep -Eq 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught'; then echo "  FAIL PHP warnings in the server log:"; echo "$log" | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught' | cut -c1-220 | sort | uniq -c | head -5; status=1; else echo "  ok   no PHP warning, notice or uncaught error in the server log"; fi
[ $status -eq 0 ] && echo "ALL SLICE 1 PROOFS PASSED" || echo "SOME SLICE 1 PROOFS FAILED"
exit $status
