#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"
export REQUEST_ID RUN_ID

date +%s > "$RUN_DIR/start_epoch.txt"
write_env_snapshot

exit_code=0

cleanup() {
  set +e
  bash "$E2E_DIR/scripts/13_stop_and_pull_video.sh" || true
  bash "$E2E_DIR/scripts/14_stop_all.sh" || true
  bash "$E2E_DIR/scripts/15_generate_run_meta.sh" || true
  bash "$E2E_DIR/scripts/16_package_evidence_zip.sh" || true
  set -e
}
trap cleanup EXIT

bash "$E2E_DIR/scripts/00_check_env.sh"
bash "$E2E_DIR/scripts/01_prepare_node_deps.sh"
bash "$E2E_DIR/scripts/02_prepare_android_sdk.sh"
bash "$E2E_DIR/scripts/03_create_avd.sh"
if [[ "$WATCH_MODE" == "1" ]]; then
  bash "$E2E_DIR/scripts/04_start_emulator_visible.sh"
else
  bash "$E2E_DIR/scripts/04_start_emulator_headless.sh"
fi
bash "$E2E_DIR/scripts/05_wait_boot.sh"
bash "$E2E_DIR/scripts/06_tune_emulator.sh"
bash "$E2E_DIR/scripts/07_build_apk.sh"
bash "$E2E_DIR/scripts/08_install_apk.sh"
bash "$E2E_DIR/scripts/09_start_logcat.sh"
bash "$E2E_DIR/scripts/10_start_screenrecord.sh"
bash "$E2E_DIR/scripts/11_start_appium.sh"

if ! bash "$E2E_DIR/scripts/12_run_wdio.sh"; then
  exit_code=1
fi

echo "DONE request_id=$REQUEST_ID run_id=$RUN_ID out=$(mask_path "$RUN_DIR")"
exit $exit_code
