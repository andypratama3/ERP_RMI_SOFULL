#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

adb emu kill >/dev/null 2>&1 || true

args=(-avd "$AVD_NAME" -no-snapshot -no-boot-anim -gpu swiftshader_indirect -no-window -no-audio)
if [[ "$WIPE_DATA" == "1" ]]; then
  args+=(-wipe-data)
fi

emulator "${args[@]}" > "$LOG_DIR/emulator_headless.log" 2>&1 &
echo $! > "$PID_DIR/emulator.pid"
echo "PASS emulator headless started pid=$(cat "$PID_DIR/emulator.pid")"
