#!/usr/bin/env bash
# The scale rule measured (design §3, §10): db/proof/phase0_timing.sql on a scratch database with every migration applied.
#   bash tests/phase0/timing.sh       (as maludb; exit 0 = every step under the ceiling; prints the timings)
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
DB=${DC_TIMING_DB:-dc_timing}
PSQL=(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q)
"${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB" || exit 2
for f in "$ROOT"/db/0*.sql; do "${PSQL[@]}" -d "$DB" -f "$f" >/dev/null || { echo "ABORT: $f failed"; exit 2; }; done
sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$DB" -f "$ROOT/db/proof/phase0_timing.sql"
rc=$?
[ -n "${DC_TIMING_KEEP:-}" ] || "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS $DB" >/dev/null 2>&1
exit $rc
