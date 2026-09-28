#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
DROP_DIR="$ROOT_DIR/output/internal_release/drop"
APP_ID="${APP_ID:-com.company.internalerp}"
APK_PATH="${1:-}"

if ! command -v adb >/dev/null 2>&1; then
  echo "FAIL: adb tidak ditemukan. Install Android platform-tools dulu."
  exit 1
fi

if [ -z "$APK_PATH" ]; then
  APK_PATH="$(ls -1t "$DROP_DIR"/*.apk 2>/dev/null | head -n 1 || true)"
fi

if [ -z "$APK_PATH" ] || [ ! -f "$APK_PATH" ]; then
  echo "FAIL: APK tidak ditemukan. Berikan path APK atau jalankan release dulu."
  echo "Contoh: bash tools/release/install_apk_connected_device.sh \"$DROP_DIR/InternalERP_v1_xxx.apk\""
  exit 1
fi

DEVICE_LINE_COUNT="$(adb devices | awk 'NR>1 && $2=="device"{count++} END{print count+0}')"
if [ "$DEVICE_LINE_COUNT" -lt 1 ]; then
  echo "FAIL: Tidak ada device online. Aktifkan USB debugging / emulator dulu."
  exit 1
fi

MASKED_APK_PATH="$(printf '%s' "$APK_PATH" | sed "s|$ROOT_DIR|[WORKDIR]|g")"
echo "INFO: installing $MASKED_APK_PATH"

set +e
INSTALL_OUTPUT="$(adb install -r "$APK_PATH" 2>&1)"
INSTALL_CODE=$?
set -e

if [ "$INSTALL_CODE" -eq 0 ]; then
  echo "PASS: APK ter-install."
  adb shell am start -n "$APP_ID/.MainActivity" >/dev/null 2>&1 || true
  echo "INFO: app launch trigger done ($APP_ID)."
  exit 0
fi

echo "$INSTALL_OUTPUT"
if printf '%s' "$INSTALL_OUTPUT" | awk '/INSTALL_FAILED_UPDATE_INCOMPATIBLE/{found=1} END{exit found?0:1}'; then
  echo "INFO: Signature beda dengan app lama."
  echo "INFO: Jalankan uninstall lalu install ulang:"
  echo "  adb uninstall $APP_ID"
  echo "  adb install \"$APK_PATH\""
fi

echo "FAIL: Install APK gagal."
exit 1
