#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

adb logcat -c || true
adb logcat -v time > "$LOG_DIR/device_logcat.txt" 2>&1 &
echo $! > "$PID_DIR/logcat.pid"
echo "PASS logcat started"
