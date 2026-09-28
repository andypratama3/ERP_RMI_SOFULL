#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

APK_PATH="$(cat "$RUN_DIR/apk_path.txt")"
adb install -r "$APK_PATH" > "$LOG_DIR/apk_install.log" 2>&1
echo "PASS apk installed"
