#!/usr/bin/env bash
# Slice 2's proof — uploads, versions, blobs, serving and preview (docs/build-specs/uploads-versions.md "Proof") — on a fresh SCRATCH database
# (dc_dev2) with a SCRATCH store (/tmp/dc-dev2-storage through DC_STORAGE_DIR), the app on :8701 (php -S with tests/dev_router.php; DC_APP=apache
# serves the rendered vhost), the fake kernel on :8702, the fake MaluDB + MaluMail on :8706: the schema proof, uploads, documents, versions,
# serving, the worker, json, the browser at 375 and 1280, the registry. DC_ONLY="uploads json" runs some. Exit 0 only when every proof passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export DC_DEV_DB=${DC_DEV_DB:-dc_dev2} DC_DEV_ENV=${DC_DEV_ENV:-/tmp/dc-dev2.env} DC_DEV_STATE=${DC_DEV_STATE:-/tmp/dc-dev2-state} SHOTS=${SHOTS:-/tmp/dc-shots} DC_STORE=${DC_STORE:-/tmp/dc-dev2-storage}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
mkdir -p "$SHOTS"
rm -rf "$DC_STORE"; mkdir -p "$DC_STORE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
sed -i "s#^DC_STORAGE_DIR=.*#DC_STORAGE_DIR=$DC_STORE#" "$DC_DEV_ENV"
status=0
cd "$ROOT"
ONLY=${DC_ONLY:-schema uploads documents versions serving worker json browser registry}
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
for p in uploads documents versions serving worker json; do
  has $p || continue
  echo "== $p"; php "tests/phase3/slice2/$p.php" || status=1
done
if has browser; then echo "== browser"; node tests/phase3/slice2/browser.mjs || status=1; fi
if has registry; then
  echo "== registry"
  php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
  php -r '$r = json_decode(file_get_contents("mcp/action_registry.json"), true); $s = array_filter($r["screens"], fn($x) => in_array($x["screen"], ["document","document-edit","document-move","document-versions","upload","exports"], true)); $b = array_filter($s, fn($x) => $x["built"]); $names = ["document_upload","document_rename","document_describe","document_move","document_copy","version_restore","version_purge","document_lock","document_unlock","export_folder","export_documents"]; $a = array_filter($r["actions"], fn($x) => in_array($x["action"], $names, true)); $ab = array_filter($a, fn($x) => $x["built"]); $paused = array_filter($a, fn($x) => !empty($x["approval"]) || !empty($x["agent_approval"])); $ok = count($b) === 6 && count($ab) === 11; echo ($ok ? "  ok   " : "  FAIL ") . "the registry marks the 6 screens and " . count($ab) . "/11 actions of slice 2 built\n"; exit($ok ? 0 : 1);' || status=1
fi
log=""; for f in "$DC_DEV_STATE/app.log" "$DC_DEV_STATE/apache/apache.err"; do [ -f "$f" ] && log="$log$(cat "$f")"; done
if echo "$log" | grep -Eq 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught'; then echo "  FAIL PHP warnings in the server log:"; echo "$log" | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught' | cut -c1-220 | sort | uniq -c | head -5; status=1; else echo "  ok   no PHP warning, notice or uncaught error in the server log"; fi
[ $status -eq 0 ] && echo "ALL SLICE 2 PROOFS PASSED" || echo "SOME SLICE 2 PROOFS FAILED"
exit $status
