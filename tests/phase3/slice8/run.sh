#!/usr/bin/env bash
# Slice 8's proof — the agents, the Filing Clerk and notifications (docs/build-specs/agents-clerk-notifications.md "Proof") — on a fresh SCRATCH database (dc_dev8) with a SCRATCH store, the app on :8701,
# the fake kernel on :8702 (its chat endpoint answers canned replies, an approval, a refusal or a 500), the fake MaluDB + MaluMail on :8706. DC_ONLY="report dispatches" runs some. DC_APP=apache runs the
# vhost's own rewrites. Exit 0 only when every proof passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export DC_DEV_DB=${DC_DEV_DB:-dc_dev8} DC_DEV_ENV=${DC_DEV_ENV:-/tmp/dc-dev8.env} DC_DEV_STATE=${DC_DEV_STATE:-/tmp/dc-dev8-state} SHOTS=${SHOTS:-/tmp/dc-shots} DC_STORE=${DC_STORE:-/tmp/dc-dev8-storage}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
mkdir -p "$SHOTS" "$DC_DEV_STATE"
rm -rf "$DC_STORE"; mkdir -p "$DC_STORE"; rm -f "$DC_DEV_STATE/world.json"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
sed -i "s#^DC_STORAGE_DIR=.*#DC_STORAGE_DIR=$DC_STORE#" "$DC_DEV_ENV"
status=0
cd "$ROOT"
ONLY=${DC_ONLY:-schema agents report proposals dispatches notifications k6 json limits browser registry}
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
for p in agents report proposals dispatches notifications k6 json limits; do
  has $p || continue
  echo "== $p"; php "tests/phase3/slice8/$p.php" || status=1
done
if has browser; then echo "== browser"; node tests/phase3/slice8/browser.mjs || status=1; fi
if has registry; then
  echo "== registry"
  php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
  php bin/sync_approvals.php --check >/dev/null 2>&1 && echo "  ok   sync_approvals --check" || { echo "  FAIL sync_approvals --check"; status=1; }
  php -r '$r = json_decode(file_get_contents("mcp/action_registry.json"), true); $scr = array_filter(["proposals","admin-agents"], fn($i) => !empty($r["screens"][$i]["built"]) && empty($r["screens"][$i]["stub"])); $names = ["clerk_propose","clerk_proposal_decide","notification","dispatch_retry","dispatch_cancel","tags_propose_request","agents_settings_save"]; $a = array_filter($r["actions"], fn($x) => in_array($x["action"], $names, true)); $built = array_filter($a, fn($x) => $x["built"]); $paused = array_filter($a, fn($x) => ($x["approval"] ?? "") !== ""); $people = array_filter($a, fn($x) => !$x["agent"]); $ok = count($scr) === 2 && count($built) === 7 && count($paused) === 1 && count($people) === 2; echo ($ok ? "  ok   " : "  FAIL ") . "the registry marks the 2 screens and " . count($built) . "/7 actions built, " . count($paused) . " paused (agents_settings_save), " . count($people) . " people-only\n"; exit($ok ? 0 : 1);' || status=1
fi
log=""; for f in "$DC_DEV_STATE/app.log" "$DC_DEV_STATE/apache/apache.err"; do [ -f "$f" ] && log="$log$(cat "$f")"; done
if echo "$log" | grep -Eq 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught'; then echo "  FAIL PHP warnings in the server log:"; echo "$log" | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught' | cut -c1-220 | sort | uniq -c | head -5; status=1; else echo "  ok   no PHP warning, notice or uncaught error in the server log"; fi
[ $status -eq 0 ] && echo "ALL SLICE 8 PROOFS PASSED" || echo "SOME SLICE 8 PROOFS FAILED"
exit $status
