#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

zip_name="appium_evidence_${RUN_ID}.zip"
zip_path="$RUN_DIR/$zip_name"

(
  cd "$RUN_DIR"
  rm -f "$zip_name"
  zip -rq "$zip_name" run_meta.json junit screenshots logs videos || true
)

if command -v shasum >/dev/null 2>&1; then
  (cd "$RUN_DIR" && shasum -a 256 "$zip_name" > SHA256SUMS.txt) || true
fi

echo "PASS evidence_zip=$(mask_path "$zip_path")"
