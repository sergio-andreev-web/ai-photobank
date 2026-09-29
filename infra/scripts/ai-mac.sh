#!/usr/bin/env bash
# Native macOS AI service (Qwen3-VL + SigLIP2 on MPS, §8, §58) as a background process,
# so `make start` / `make stop` can manage it together with the Docker stack.
#
#   infra/scripts/ai-mac.sh start    # start in the background, wait until the models are loaded
#   infra/scripts/ai-mac.sh stop
#   infra/scripts/ai-mac.sh status
#
# Same environment as `make ai-mac` (project-root .env, DEVICE=mps, transformers provider),
# without --reload. Pid and log live in .run/ (git-ignored). Variables:
#   AI_MAC_DEVICE        mps (default) or cpu
#   AI_READY_TIMEOUT     seconds to wait for /ready on start (default 600; 0 = do not wait)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RUN_DIR="$ROOT/.run"
PID_FILE="$RUN_DIR/ai-mac.pid"
LOG_FILE="$RUN_DIR/ai-mac.log"
PORT=8001
URL="http://localhost:$PORT"

running_pid() {
  [ -f "$PID_FILE" ] || return 1
  local pid
  pid="$(cat "$PID_FILE")"
  kill -0 "$pid" 2>/dev/null && echo "$pid"
}

wait_ready() {
  local timeout="${AI_READY_TIMEOUT:-600}" waited=0
  [ "$timeout" = 0 ] && return 0
  echo "Waiting for the models to load (up to ${timeout}s, log: .run/ai-mac.log)..."
  until curl -sf "$URL/ready" >/dev/null 2>&1; do
    if ! running_pid >/dev/null; then
      echo "❌ AI service exited during startup. Last log lines:" >&2
      tail -n 20 "$LOG_FILE" >&2 || true
      return 1
    fi
    if [ "$waited" -ge "$timeout" ]; then
      echo "❌ AI service is not ready after ${timeout}s (still loading? see .run/ai-mac.log)" >&2
      return 1
    fi
    sleep 5
    waited=$((waited + 5))
  done
  echo "✓ AI service ready: $(curl -s "$URL/ready")"
}

start() {
  if pid="$(running_pid)"; then
    echo "AI service already running (pid $pid)"
    wait_ready
    return
  fi
  if lsof -nP -iTCP:$PORT -sTCP:LISTEN >/dev/null 2>&1; then
    echo "❌ Port $PORT is taken by another process (not started by this script):" >&2
    lsof -nP -iTCP:$PORT -sTCP:LISTEN >&2
    echo "Stop it first, or use it as is and run only 'make up-mac'." >&2
    return 1
  fi
  [ -f "$ROOT/.env" ] || { echo "Run 'make .env' (or cp .env.example .env) first" >&2; return 1; }

  local python="$ROOT/services/ai/.venv/bin/python"
  if [ ! -x "$python" ]; then
    echo "❌ services/ai/.venv not found. Install the real-model dependencies once:" >&2
    echo "   cd services/ai && python3 -m venv .venv && .venv/bin/pip install -r requirements.txt -r requirements-model.txt" >&2
    return 1
  fi

  mkdir -p "$RUN_DIR"
  set -a
  # shellcheck disable=SC1091
  . "$ROOT/.env"
  set +a
  local hf_home="${AI_MAC_HF_HOME:-$HOME/.cache/photobank-ai/huggingface}"
  mkdir -p "$hf_home"

  echo "=== start $(date '+%F %T')" >>"$LOG_FILE"
  # Own session: the service outlives the terminal (and `make`) that started it, and Ctrl+C
  # in that terminal does not reach it.
  (
    cd "$ROOT/services/ai"
    export DEVICE="${AI_MAC_DEVICE:-mps}" AI_MODEL_PROVIDER=transformers \
      AI_CONCURRENCY="${AI_CONCURRENCY:-1}" HF_HOME="$hf_home" PYTHONUNBUFFERED=1
    "$python" - "$LOG_FILE" "$PORT" >"$PID_FILE" <<'PY'
import subprocess, sys
log = open(sys.argv[1], "ab")
proc = subprocess.Popen(
    [sys.executable, "-m", "uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", sys.argv[2]],
    stdout=log, stderr=subprocess.STDOUT, stdin=subprocess.DEVNULL, start_new_session=True,
)
print(proc.pid)
PY
  )
  echo "AI service started (pid $(cat "$PID_FILE"), device ${AI_MAC_DEVICE:-mps}, prompt ${PROMPT_VERSION:-default})"
  wait_ready
}

stop() {
  local pid
  if ! pid="$(running_pid)"; then
    echo "AI service is not running"
    rm -f "$PID_FILE"
    return
  fi
  kill "$pid"
  for _ in $(seq 1 30); do
    kill -0 "$pid" 2>/dev/null || break
    sleep 1
  done
  kill -0 "$pid" 2>/dev/null && kill -9 "$pid"
  rm -f "$PID_FILE"
  echo "AI service stopped"
}

status() {
  if pid="$(running_pid)"; then
    echo "AI service: running (pid $pid) — $(curl -s "$URL/ready" || echo 'not answering yet')"
  else
    echo "AI service: stopped"
  fi
}

case "${1:-}" in
  start) start ;;
  stop) stop ;;
  status) status ;;
  *) echo "usage: $0 start|stop|status" >&2; exit 2 ;;
esac
