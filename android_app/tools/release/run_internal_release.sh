#!/usr/bin/env sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
RELEASE_DIR="$ROOT_DIR/output/internal_release"
REQUEST_ID="${RELEASE_REQUEST_ID:-$(uuidgen 2>/dev/null || openssl rand -hex 16)}"
export RELEASE_REQUEST_ID="$REQUEST_ID"

mkdir -p "$RELEASE_DIR/drop/download"

sh "$ROOT_DIR/tools/release/check_release_env.sh"
sh "$ROOT_DIR/tools/release/build_release_apk.sh"
sh "$ROOT_DIR/tools/release/verify_release_apk.sh"
sh "$ROOT_DIR/tools/release/generate_internal_manifest.sh"
sh "$ROOT_DIR/tools/release/generate_download_page.sh"
sh "$ROOT_DIR/tools/release/generate_evidence_pack.sh"
sh "$ROOT_DIR/tools/release/generate_final_signoff_summary.sh"
sh "$ROOT_DIR/tools/release/smoke_internal_release_output.sh"

echo "DONE request_id=$REQUEST_ID"
echo "drop=[WORKDIR]/output/internal_release/drop"
