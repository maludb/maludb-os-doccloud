#!/usr/bin/env bash
# tests/phase4/mcp_servers.sh start|stop — the two MCP servers on SCRATCH loopback ports from the scratch environment ($DC_DEV_ENV):
# records :8704, activity :8705 (never the installed application's ports 8841/8842 or its services). The records server reaches the application's own door (the bridge) on $APP_INTERNAL_PORT — :8701 under php -S,
# :8707 under DC_APP=apache (the vhost's internal port). Pid files live in $DC_DEV_STATE. The Python is the application's own mcp/venv (python3 -m venv mcp/venv && mcp/venv/bin/pip install -r mcp/requirements.txt;
# deploy/ROOT_STEPS.sh does it for the installed copy); otherwise $DC_MCP_VENV, else a sibling application's venv that already holds FastMCP, asyncpg, httpx and uvicorn — nothing is installed here.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${DC_DEV_ENV:-/tmp/dc-dev.env}
STATE=${DC_DEV_STATE:-/tmp/dc-dev-state}
mkdir -p "$STATE"
VENV=
for c in "${DC_MCP_VENV:-}" "$ROOT/mcp/venv" /srv/apps/spaces/mcp/venv /srv/apps/helpdesk/mcp/venv /srv/apps/consultant_tracking/mcp/venv /srv/apps/projects/mcp/venv; do
  [ -n "$c" ] && [ -x "$c/bin/python" ] && "$c/bin/python" -c 'import mcp, asyncpg, httpx, uvicorn' 2>/dev/null && { VENV=$c; break; }
done
[ -n "$VENV" ] || { echo "no venv with FastMCP, asyncpg, httpx and uvicorn found" >&2; exit 1; }
PY="$VENV/bin/python"
stop() {
  for f in "$STATE"/mcp-*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  for i in $(seq 1 30); do ss -ltn 2>/dev/null | grep -qE ':(8704|8705) ' || break; sleep 0.2; done
}
case "${1:-}" in
  stop) stop ;;
  venv) echo "$VENV" ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    INTERNAL=8701; [ "${DC_APP:-php}" = "apache" ] && INTERNAL=8707
    launch() {  # name, then VAR=value pairs, then the interpreter and script — exec so $! is the python process itself
      local name=$1; shift
      ( cd "$ROOT/mcp" && exec env "$@" >"$STATE/mcp-$name.log" 2>&1 ) &
      echo $! > "$STATE/mcp-$name.pid"
    }
    launch records MCP_RECORDS_PORT=8704 MCP_ACTIVITY_PORT=8705 APP_INTERNAL_PORT=$INTERNAL "$PY" "$ROOT/mcp/records_server.py"
    launch activity MCP_RECORDS_PORT=8704 MCP_ACTIVITY_PORT=8705 APP_INTERNAL_PORT=$INTERNAL "$PY" "$ROOT/mcp/activity_server.py"
    for p in 8704 8705; do
      for i in $(seq 1 60); do curl -s -o /dev/null -m 1 "http://127.0.0.1:$p/mcp" && break; sleep 0.2; done
    done
    echo "mcp servers up (records :8704, activity :8705, the bridge on :$INTERNAL; python $VENV)" ;;
  *) echo "usage: $0 start|stop|venv" >&2; exit 2 ;;
esac
