#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")"
HOST="${HOST:-0.0.0.0}"
PORT="${PORT:-8080}"
WORKER_COUNT="${WORKER_COUNT:-2}"
WORKER_PIDS=()
PHP_PID=""

for cmd in php; do
  command -v "$cmd" >/dev/null 2>&1 || {
    echo "[start] Missing dependency: $cmd"
    exit 1
  }
done

mkdir -p storage/jobs

cleanup() {
  trap - EXIT INT TERM
  echo "[start] Stopping processes..."
  [[ -z "$PHP_PID" ]] || kill "$PHP_PID" 2>/dev/null || true
  for pid in "${WORKER_PIDS[@]}"; do
    [[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true
  done

  wait "$PHP_PID" 2>/dev/null || true
  for pid in "${WORKER_PIDS[@]}"; do
    wait "$pid" 2>/dev/null || true
  done
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

echo "[start] Starting $WORKER_COUNT PHP workers..."
for i in $(seq 1 "$WORKER_COUNT"); do
  php worker.php &
  WORKER_PIDS+=($!)
done

php -S "$HOST:$PORT" -t public &
PHP_PID=$!

echo "[start] All workers and PHP server started."
echo "[start] Port: $PORT"
echo "[start] Workers: $WORKER_COUNT"
echo "[start] Press Ctrl+C to stop both."

set +e
wait -n
status=$?
set -e
exit "$status"