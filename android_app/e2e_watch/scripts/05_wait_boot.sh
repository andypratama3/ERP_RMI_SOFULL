#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

adb wait-for-device

deadline=$((SECONDS + RUN_TIMEOUT_SECONDS))
ok=0
while (( SECONDS < deadline )); do
  boot="$(adb shell getprop sys.boot_completed 2>/dev/null | tr -d '\r' || true)"
  if [[ "$boot" == "1" ]]; then
    ok=1
    break
  fi
  sleep 2
done

if [[ "$ok" != "1" ]]; then
  echo "FAIL emulator boot timeout" >&2
  exit 1
fi

echo "PASS emulator boot completed"
