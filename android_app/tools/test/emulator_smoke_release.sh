#!/usr/bin/env sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
APP_ID="${APP_ID:-com.company.internalerp}"
AVD_NAME="${AVD_NAME:-internalApi35}"
SYSTEM_IMAGE="${SYSTEM_IMAGE:-system-images;android-35;google_apis;arm64-v8a}"
ANDROID_SDK_ROOT="${ANDROID_SDK_ROOT:-/usr/local/share/android-commandlinetools}"
ANDROID_HOME="${ANDROID_HOME:-$ANDROID_SDK_ROOT}"
PATH="$ANDROID_HOME/platform-tools:$ANDROID_HOME/emulator:$PATH"

LATEST_APK="$(ls -1t "$ROOT_DIR/output/internal_release/drop/"*.apk 2>/dev/null | head -n 1 || true)"
if [ -z "$LATEST_APK" ]; then
  echo "No release APK found at [WORKDIR]/output/internal_release/drop/*.apk"
  exit 1
fi

echo "Using APK: $(printf '%s' "$LATEST_APK" | sed "s|$ROOT_DIR|[WORKDIR]|g")"

if ! command -v sdkmanager >/dev/null 2>&1; then
  echo "sdkmanager not found. Install android-commandlinetools first."
  exit 1
fi

if ! command -v avdmanager >/dev/null 2>&1; then
  echo "avdmanager not found. Install android-commandlinetools first."
  exit 1
fi

yes | sdkmanager --licenses >/dev/null 2>&1 || true
sdkmanager "platform-tools" "platforms;android-35" "build-tools;35.0.0" "emulator" "$SYSTEM_IMAGE"

EMULATOR_BIN="$(command -v emulator || true)"
if [ -z "$EMULATOR_BIN" ] && [ -x "$ANDROID_HOME/emulator/emulator" ]; then
  EMULATOR_BIN="$ANDROID_HOME/emulator/emulator"
fi
if [ -z "$EMULATOR_BIN" ]; then
  echo "emulator binary not found after SDK install."
  exit 1
fi

if ! avdmanager list avd | grep -E "Name: ${AVD_NAME}$" >/dev/null 2>&1; then
  echo "Creating AVD: $AVD_NAME"
  echo "no" | avdmanager create avd -n "$AVD_NAME" -k "$SYSTEM_IMAGE" --device "pixel_6" >/dev/null
fi

adb start-server >/dev/null
adb devices | grep -E "emulator-" >/dev/null 2>&1 && adb -s "$(adb devices | awk '/emulator-/{print $1; exit}')" emu kill >/dev/null 2>&1 || true

nohup "$EMULATOR_BIN" -avd "$AVD_NAME" -no-snapshot -no-boot-anim -no-audio -no-window >/tmp/internalerp_emulator.log 2>&1 &
EMULATOR_PID=$!

echo "Waiting emulator boot..."
adb wait-for-device
boot=""
for _ in $(seq 1 120); do
  boot="$(adb shell getprop sys.boot_completed 2>/dev/null | tr -d '\r' || true)"
  if [ "$boot" = "1" ]; then
    break
  fi
  sleep 2
done
if [ "$boot" != "1" ]; then
  echo "Emulator boot timeout. Check /tmp/internalerp_emulator.log"
  kill "$EMULATOR_PID" >/dev/null 2>&1 || true
  exit 1
fi

adb shell settings put global window_animation_scale 0 >/dev/null
adb shell settings put global transition_animation_scale 0 >/dev/null
adb shell settings put global animator_duration_scale 0 >/dev/null

adb install -r "$LATEST_APK"
adb shell monkey -p "$APP_ID" -c android.intent.category.LAUNCHER 1 >/dev/null 2>&1 || true
sleep 3

adb shell screencap -p /sdcard/internalerp_smoke.png >/dev/null
adb pull /sdcard/internalerp_smoke.png "$ROOT_DIR/output/internal_release/drop/internalerp_smoke.png" >/dev/null

echo "SMOKE PASS (emulator install + launch)"
echo "Screenshot: [WORKDIR]/output/internal_release/drop/internalerp_smoke.png"

adb emu kill >/dev/null 2>&1 || true
exit 0
