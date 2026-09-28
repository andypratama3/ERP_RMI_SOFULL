#!/usr/bin/env sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
RELEASE_DIR="$ROOT_DIR/output/internal_release"
DROP_DIR="$RELEASE_DIR/drop"
DOWNLOAD_DIR="$DROP_DIR/download"
MANIFEST_JSON="$RELEASE_DIR/internal_manifest.json"
REQUEST_ID="${RELEASE_REQUEST_ID:-$(uuidgen 2>/dev/null || openssl rand -hex 16)}"

mkdir -p "$DOWNLOAD_DIR"

if [ ! -f "$MANIFEST_JSON" ]; then
  echo "Manifest not found"
  exit 1
fi

APP_NAME="${APP_NAME:-InternalERP}"
APK_FILENAME="$(python3 - "$MANIFEST_JSON" <<'PY'
import json,sys
print(json.load(open(sys.argv[1],encoding='utf-8')).get("latest",{}).get("apk_filename",""))
PY
)"
VERSION_CODE="$(python3 - "$MANIFEST_JSON" <<'PY'
import json,sys
print(json.load(open(sys.argv[1],encoding='utf-8')).get("latest",{}).get("version_code",0))
PY
)"
VERSION_NAME="$(python3 - "$MANIFEST_JSON" <<'PY'
import json,sys
print(json.load(open(sys.argv[1],encoding='utf-8')).get("latest",{}).get("version_name","unknown"))
PY
)"
SHA256="$(python3 - "$MANIFEST_JSON" <<'PY'
import json,sys
print(json.load(open(sys.argv[1],encoding='utf-8')).get("latest",{}).get("sha256",""))
PY
)"

cat > "$DOWNLOAD_DIR/index.html" <<HTML
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>${APP_NAME} Internal APK</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 24px; line-height: 1.5; }
    .card { border: 1px solid #ccc; border-radius: 8px; padding: 16px; max-width: 860px; }
    code { background: #f5f5f5; padding: 2px 6px; }
  </style>
</head>
<body>
  <div class="card">
    <h2>${APP_NAME} - Internal APK</h2>
    <p><b>Version:</b> ${VERSION_NAME} (${VERSION_CODE})</p>
    <p><a href="../${APK_FILENAME}">Download APK terbaru</a></p>
    <p><b>SHA256:</b><br/><code>${SHA256}</code></p>
    <h3>Instruksi Install</h3>
    <ol>
      <li>Download/copy file APK.</li>
      <li>Enable "Install unknown apps" untuk File Manager/Browser.</li>
      <li>Install APK.</li>
    </ol>
    <h3>Catatan Rollback</h3>
    <ul>
      <li>Downgrade tidak bisa tanpa uninstall.</li>
      <li>Rollback yang direkomendasikan: build revert dengan <b>versionCode lebih tinggi</b>.</li>
    </ul>
  </div>
</body>
</html>
HTML

QR_TEXT="latest_apk=../${APK_FILENAME}
sha256=${SHA256}
request_id=${REQUEST_ID}"

if command -v qrencode >/dev/null 2>&1; then
  printf '%s\n' "$QR_TEXT" | qrencode -o "$DOWNLOAD_DIR/qr_latest.png" >/dev/null 2>&1 || true
fi

printf '%s\n' "$QR_TEXT" > "$DOWNLOAD_DIR/qr_latest.txt"

echo "PASS request_id=$REQUEST_ID"
