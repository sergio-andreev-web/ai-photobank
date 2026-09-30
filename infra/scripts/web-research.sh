#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
PID_FILE="$ROOT/.run/web-research.pid"
LOG_FILE="$ROOT/.run/web-research.log"
case "${1:-status}" in
 start)
  if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then echo 'Web research already running'; exit 0; fi
  if [ ! -d "$ROOT/services/web-research/node_modules" ]; then echo 'Web research not installed; run make research-install'; exit 0; fi
  mkdir -p "$ROOT/.run"
  cd "$ROOT/services/web-research"
  # Credentials are read by Node's env-file loader, never echoed or evaluated as shell code.
  nohup node --env-file-if-exists=../../.env src/server.mjs > "$LOG_FILE" 2>&1 < /dev/null &
  echo "$!" > "$PID_FILE"
  sleep 1
  if ! kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then rm -f "$PID_FILE"; echo 'Web research did not start; configure WEB_RESEARCH_TOKEN, see .run/web-research.log'; exit 0; fi
  echo 'Web research started'
  ;;
 stop)
  if [ -f "$PID_FILE" ]; then kill "$(cat "$PID_FILE")" 2>/dev/null || true; rm -f "$PID_FILE"; fi
  ;;
 status)
  if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then echo 'Web research running'; else echo 'Web research stopped'; fi
  ;;
 *) echo 'Usage: web-research.sh start|stop|status'; exit 1 ;;
esac
