#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

write_env_snapshot

err=()
[[ -n "${BASE_URL:-}" ]] || err+=("BASE_URL wajib diisi")
[[ -n "${TEST_USER_USERNAME:-}" ]] || err+=("TEST_USER_USERNAME wajib diisi")
[[ -n "${TEST_USER_PASSWORD:-}" ]] || err+=("TEST_USER_PASSWORD wajib diisi")
command -v node >/dev/null 2>&1 || err+=("node tidak ditemukan")
command -v npm >/dev/null 2>&1 || err+=("npm tidak ditemukan")
command -v adb >/dev/null 2>&1 || err+=("adb tidak ditemukan")
command -v emulator >/dev/null 2>&1 || err+=("emulator tidak ditemukan")
command -v sdkmanager >/dev/null 2>&1 || err+=("sdkmanager tidak ditemukan")
command -v avdmanager >/dev/null 2>&1 || err+=("avdmanager tidak ditemukan")
[[ -f "$ROOT_DIR/gradlew" ]] || err+=("gradlew tidak ditemukan")

if (( ${#err[@]} > 0 )); then
  printf 'FAIL request_id=%s run_id=%s\n' "$REQUEST_ID" "$RUN_ID"
  printf '%s\n' "${err[@]}"
  exit 1
fi

printf 'PASS request_id=%s run_id=%s out=%s\n' \
  "$REQUEST_ID" "$RUN_ID" "$(mask_path "$RUN_DIR")"
