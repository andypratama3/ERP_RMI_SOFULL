#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"
export MOBILE_BASE_URL_DEBUG

if [[ -n "$APK_PATH_OVERRIDE" ]]; then
  APK_PATH="$APK_PATH_OVERRIDE"
else
  if [[ "$APK_VARIANT" == "release" ]]; then
    ./gradlew :app:assembleRelease --no-daemon > "$LOG_DIR/gradle_build.log" 2>&1
    APK_PATH="$ROOT_DIR/app/build/outputs/apk/release/app-release.apk"
  else
    ./gradlew :app:assembleDebug --no-daemon > "$LOG_DIR/gradle_build.log" 2>&1
    APK_PATH="$ROOT_DIR/app/build/outputs/apk/debug/app-debug.apk"
  fi
fi

if [[ ! -f "$APK_PATH" ]]; then
  echo "FAIL apk not found: $(mask_path "$APK_PATH")" >&2
  exit 1
fi

echo "$APK_PATH" > "$RUN_DIR/apk_path.txt"
echo "PASS apk=$(mask_path "$APK_PATH")"
