#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

if [[ -f "$PID_DIR/screenrecord.pid" ]]; then
  kill "$(cat "$PID_DIR/screenrecord.pid")" >/dev/null 2>&1 || true
fi
adb shell pkill -f screenrecord >/dev/null 2>&1 || true
sleep 1

if [[ "$RECORD_VIDEO" == "1" ]]; then
  adb pull /sdcard/e2e_watch_run.mp4 "$VIDEO_DIR/run.mp4" >/dev/null 2>&1 || true
fi

echo "PASS video stop/pull done"
