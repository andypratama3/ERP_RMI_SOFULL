#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

UIA2_DRIVER_VERSION="${APPIUM_UIA2_DRIVER_VERSION:-2.39.0}"

pushd "$E2E_DIR/wdio" >/dev/null
npm install
npx appium driver list --installed > "$LOG_DIR/appium_driver_installed.txt" 2>&1 || true
npx appium driver install "uiautomator2@$UIA2_DRIVER_VERSION" >> "$LOG_DIR/appium_driver_installed.txt" 2>&1 || true
popd >/dev/null

echo "PASS node deps prepared"
