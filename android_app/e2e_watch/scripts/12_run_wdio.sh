#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

APK_PATH="$(cat "$RUN_DIR/apk_path.txt")"

export WDIO_RUN_DIR="$RUN_DIR"
export WDIO_REQUEST_ID="$REQUEST_ID"
export WDIO_BASE_URL="$BASE_URL"
export WDIO_APP_PACKAGE="$APP_PACKAGE"
export WDIO_APP_ACTIVITY="$APP_ACTIVITY"
export WDIO_APPIUM_PORT="$APPIUM_PORT"
export WDIO_STEP_DELAY_MS="$STEP_DELAY_MS"
export WDIO_SCREENSHOT_ON_EACH_STEP="$SCREENSHOT_ON_EACH_STEP"
export WDIO_AVD_NAME="$AVD_NAME"
export WDIO_APK_PATH="$APK_PATH"

set +x
export WDIO_TEST_USERNAME="${TEST_USER_USERNAME:-}"
export WDIO_TEST_PASSWORD="${TEST_USER_PASSWORD:-}"
unset NODE_OPTIONS TS_NODE_PROJECT TS_NODE_TRANSPILE_ONLY || true

pushd "$E2E_DIR/wdio" >/dev/null
npx wdio run wdio.conf.js > "$LOG_DIR/appium_client.log" 2>&1
popd >/dev/null

echo "PASS wdio run"
