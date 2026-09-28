#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

pushd "$E2E_DIR/wdio" >/dev/null
npx appium --port "$APPIUM_PORT" --log-level "$APPIUM_LOG_LEVEL" --allow-insecure=adb_shell > "$LOG_DIR/appium_server.log" 2>&1 &
echo $! > "$PID_DIR/appium.pid"
popd >/dev/null

for _ in $(seq 1 30); do
  if curl -sf "http://127.0.0.1:${APPIUM_PORT}/status" >/dev/null 2>&1; then
    echo "PASS appium ready"
    exit 0
  fi
  sleep 1
done

echo "FAIL appium start timeout" >&2
exit 1
