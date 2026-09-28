#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

if [[ "$RECORD_VIDEO" != "1" ]]; then
  echo "SKIP video recording disabled"
  exit 0
fi

adb shell rm -f /sdcard/e2e_watch_run.mp4 >/dev/null 2>&1 || true
adb shell "screenrecord --time-limit $MAX_VIDEO_SECONDS /sdcard/e2e_watch_run.mp4" > "$LOG_DIR/screenrecord.log" 2>&1 &
echo $! > "$PID_DIR/screenrecord.pid"
echo "PASS screenrecord started"
