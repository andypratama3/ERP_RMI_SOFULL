#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

adb shell settings put global window_animation_scale 0 >/dev/null || true
adb shell settings put global transition_animation_scale 0 >/dev/null || true
adb shell settings put global animator_duration_scale 0 >/dev/null || true
adb shell settings put system screen_brightness 180 >/dev/null || true
adb shell svc power stayon true >/dev/null || true

echo "PASS emulator tuned"
