#!/usr/bin/env bash
# Slice 9's proof — the homes, curate, admin and reports (docs/build-specs/home-curate-admin.md "Proof") — on a fresh SCRATCH database (dc_dev9) with a SCRATCH store, the app on :8701, the fake kernel on :8702
# (it also answers the connections route when its state says so), the fake MaluDB + MaluMail on :8706. The worlds of slices 1–8 composed. DC_ONLY="home notes" runs some. DC_APP=apache runs the vhost's own rewrites.
# Exit 0 only when every proof passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export DC_DEV_DB=${DC_DEV_DB:-dc_dev9} DC_DEV_ENV=${DC_DEV_ENV:-/tmp/dc-dev9.env} DC_DEV_STATE=${DC_DEV_STATE:-/tmp/dc-dev9-state} SHOTS=${SHOTS:-/tmp/dc-shots} DC_STORE=${DC_STORE:-/tmp/dc-dev9-storage}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
mkdir -p "$SHOTS" "$DC_DEV_STATE"
rm -rf "$DC_STORE"; mkdir -p "$DC_STORE"; rm -f "$DC_DEV_STATE/world.json" "$DC_DEV_STATE/world9.json"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
sed -i "s#^DC_STORAGE_DIR=.*#DC_STORAGE_DIR=$DC_STORE#" "$DC_DEV_ENV"
status=0
cd "$ROOT"
ONLY=${DC_ONLY:-schema home curate admin notes json browser registry}
has() { [[ " $ONLY " == *" $1 "* ]]; }
cleanup() { [ "${DC_KEEP:-0}" = 1 ] && { echo "== left running (DC_KEEP=1)"; return; }; "$ROOT/tests/phase2/servers.sh" stop >/dev/null 2>&1; }
trap cleanup EXIT INT TERM
if has schema; then
  echo "== the schema (db/proof/phase0_proof.sql, rolled back)"
  out=$(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$DC_DEV_DB" -f db/proof/phase0_proof.sql 2>&1); summary=$(echo "$out" | grep -E '[0-9]+ ok, [0-9]+ failed' | head -1 | sed 's/^ *//')
  if echo "$summary" | grep -q ' 0 failed'; then echo "  ok   schema proof: $summary"; else echo "  FAIL schema proof: $summary"; status=1; fi
fi
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
set -a; . "$DC_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$DC_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$DC_DEV_STATE/maludb.log" FAKE_MALUMAIL_LOG="$DC_DEV_STATE/malumail.log"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
for p in home curate admin notes json; do
  has $p || continue
  echo "== $p"; php "tests/phase3/slice9/$p.php" || status=1
done
if has browser; then echo "== browser"; node tests/phase3/slice9/browser.mjs || status=1; fi
if has registry; then
  echo "== registry"
  php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
  php bin/sync_approvals.php --check >/dev/null 2>&1 && echo "  ok   sync_approvals --check" || { echo "  FAIL sync_approvals --check"; status=1; }
  php -r '$r = json_decode(file_get_contents("mcp/action_registry.json"), true); $scr = $r["screens"]; $a = $r["actions"]; $sb = count(array_filter($scr, fn($s) => !empty($s["built"]) && empty($s["stub"]))); $ab = count(array_filter($a, fn($x) => !empty($x["built"]))); $stub = count(array_filter($scr, fn($s) => !empty($s["stub"])));
    $mine = ["curate","curate-ownership","curate-storage","curate-search","admin","admin-settings","admin-links","admin-libraries","admin-storage"]; $mineBuilt = count(array_filter($scr, fn($s) => in_array($s["screen"] ?? "", $mine, true) && !empty($s["built"])));
    $act = ["settings_save","retention_set","ownership_transfer","note_add","note_delete","storage_export"]; $actBuilt = count(array_filter($a, fn($x) => in_array($x["action"], $act, true) && !empty($x["built"])));
    $by = []; foreach ($a as $x) { $by[$x["action"]] = $x; }
    $paused = ["settings_save" => "other", "retention_set" => "other", "ownership_transfer" => "deletion"]; $pok = true; foreach ($paused as $n => $c) { if (($by[$n]["approval"] ?? "") !== $c) { $pok = false; } }
    $ok = $sb === count($scr) && $stub === 0 && $ab === count($a) && $mineBuilt === 9 && $actBuilt === 6 && $pok && count(array_filter($a, fn($x) => ($x["approval"] ?? "") !== "")) === 23;
    echo ($ok ? "  ok   " : "  FAIL ") . "the registry: $sb of " . count($scr) . " screens built (no stub), $ab of " . count($a) . " actions built; slice 9\x27s nine screens and six actions built; the 23 approvals; settings_save, retention_set, ownership_transfer paused\n"; exit($ok ? 0 : 1);' || status=1
fi
log=""; for f in "$DC_DEV_STATE/app.log" "$DC_DEV_STATE/apache/apache.err"; do [ -f "$f" ] && log="$log$(cat "$f")"; done
if echo "$log" | grep -Eq 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught'; then echo "  FAIL PHP warnings in the server log:"; echo "$log" | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught' | cut -c1-220 | sort | uniq -c | head -5; status=1; else echo "  ok   no PHP warning, notice or uncaught error in the server log"; fi
[ $status -eq 0 ] && echo "ALL SLICE 9 PROOFS PASSED" || echo "SOME SLICE 9 PROOFS FAILED"
exit $status
