#!/usr/bin/env sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
RELEASE_DIR="$ROOT_DIR/output/internal_release"
DROP_DIR="$RELEASE_DIR/drop"
REQUEST_ID="${RELEASE_REQUEST_ID:-$(uuidgen 2>/dev/null || openssl rand -hex 16)}"
TS_COMPACT="$(date '+%Y%m%d_%H%M%S')"
EVIDENCE_ZIP="$RELEASE_DIR/evidence_internal_release_${TS_COMPACT}.zip"
TMP_DIR="$RELEASE_DIR/.evidence_tmp"

rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR/download"

cp "$RELEASE_DIR/internal_release_last.json" "$TMP_DIR/" 2>/dev/null || true
cp "$RELEASE_DIR/internal_release_last.md" "$TMP_DIR/" 2>/dev/null || true
cp "$RELEASE_DIR/internal_manifest.json" "$TMP_DIR/" 2>/dev/null || true
cp "$RELEASE_DIR/internal_manifest_history.jsonl" "$TMP_DIR/" 2>/dev/null || true
cp "$DROP_DIR/SHA256SUMS.txt" "$TMP_DIR/" 2>/dev/null || true
cp "$DROP_DIR/RELEASE_NOTES.txt" "$TMP_DIR/" 2>/dev/null || true
cp "$DROP_DIR/download/index.html" "$TMP_DIR/download/" 2>/dev/null || true
cp "$DROP_DIR/download/qr_latest.txt" "$TMP_DIR/download/" 2>/dev/null || true
cp "$DROP_DIR/download/qr_latest.png" "$TMP_DIR/download/" 2>/dev/null || true

if command -v apksigner >/dev/null 2>&1; then
  apk_file="$(ls -1t "$DROP_DIR"/*.apk 2>/dev/null | head -n 1 || true)"
  if [ -n "$apk_file" ]; then
    apksigner verify --verbose "$apk_file" > "$TMP_DIR/apksigner_verify.txt" 2>&1 || true
    sed "s|$ROOT_DIR|[WORKDIR]|g" "$TMP_DIR/apksigner_verify.txt" > "$TMP_DIR/apksigner_verify_masked.txt" || true
    rm -f "$TMP_DIR/apksigner_verify.txt"
  fi
fi

(cd "$TMP_DIR" && zip -qr "$EVIDENCE_ZIP" .)
rm -rf "$TMP_DIR"

echo "PASS request_id=$REQUEST_ID evidence=$(printf '%s' "$EVIDENCE_ZIP" | sed "s|$ROOT_DIR|[WORKDIR]|g")"
