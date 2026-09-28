#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

yes | sdkmanager --licenses >/dev/null 2>&1 || true
sdkmanager \
  "platform-tools" \
  "emulator" \
  "platforms;android-${AVD_API_LEVEL}" \
  "$AVD_SYSTEM_IMAGE" > "$LOG_DIR/sdk_prepare.log" 2>&1

echo "PASS android sdk prepared"
