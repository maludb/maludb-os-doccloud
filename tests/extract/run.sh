#!/usr/bin/env bash
# The extraction proof: app/doccloud/extract/ against synthetic fixtures (tests/extract/run.php). No database, no network.
#   bash tests/extract/run.sh          exit 0 = every check passed (checks needing pdftotext are skipped, not failed, when it is absent)
set -uo pipefail
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
command -v pdftotext >/dev/null || echo "NOTE: pdftotext not on PATH (set DC_PDFTOTEXT) — the PDF checks will be skipped"
exec php "$ROOT/tests/extract/run.php"
