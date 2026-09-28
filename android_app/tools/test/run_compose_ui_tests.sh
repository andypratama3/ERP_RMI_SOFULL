#!/usr/bin/env sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
ANDROID_SDK_ROOT="${ANDROID_SDK_ROOT:-/usr/local/share/android-commandlinetools}"
ANDROID_HOME="${ANDROID_HOME:-$ANDROID_SDK_ROOT}"
PATH="$ANDROID_HOME/platform-tools:$ANDROID_HOME/emulator:$PATH"

if [ -n "${JAVA_HOME:-}" ]; then
  PATH="$JAVA_HOME/bin:$PATH"
fi

cd "$ROOT_DIR"
sh ./gradlew :app:connectedDebugAndroidTest --no-daemon
