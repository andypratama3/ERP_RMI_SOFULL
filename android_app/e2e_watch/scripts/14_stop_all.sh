#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

for f in appium.pid logcat.pid; do
  if [[ -f "$PID_DIR/$f" ]]; then
    kill "$(cat "$PID_DIR/$f")" >/dev/null 2>&1 || true
  fi
done

if [[ "$KEEP_EMULATOR_OPEN" != "1" ]]; then
  adb emu kill >/dev/null 2>&1 || true
  if [[ -f "$PID_DIR/emulator.pid" ]]; then
    kill "$(cat "$PID_DIR/emulator.pid")" >/dev/null 2>&1 || true
  fi
  echo "Run selesai. Emulator dimatikan."
else
  echo "Run selesai. Emulator tetap terbuka untuk inspeksi."
fi
