#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

if ! avdmanager list avd | awk -v n="$AVD_NAME" '$0 ~ "Name: "n {found=1} END{exit found?0:1}'; then
  echo "no" | avdmanager create avd -n "$AVD_NAME" -k "$AVD_SYSTEM_IMAGE" --device "$AVD_DEVICE_PROFILE" > "$LOG_DIR/avd_create.log" 2>&1
fi

echo "PASS avd ready: $AVD_NAME"
