#!/usr/bin/env sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
RELEASE_DIR="$ROOT_DIR/output/internal_release"
DROP_DIR="$RELEASE_DIR/drop"
SECURE_DIR="$RELEASE_DIR/.secure"
REQUEST_ID="${RELEASE_REQUEST_ID:-$(uuidgen 2>/dev/null || openssl rand -hex 16)}"

if [ -f "$ROOT_DIR/.env" ]; then
  set -a
  . "$ROOT_DIR/.env"
  set +a
fi

APP_NAME="${APP_NAME:-InternalERP}"
APP_ID="${APP_ID:-com.company.internalerp}"
RELEASE_NOTES_FILE="${RELEASE_NOTES_FILE:-android_app/release_notes.md}"

mkdir -p "$DROP_DIR" "$SECURE_DIR"
umask 077

KEYSTORE_PATH="${ANDROID_KEYSTORE_PATH:-}"
if [ -n "$KEYSTORE_PATH" ] && [ -f "$KEYSTORE_PATH" ]; then
  :
else
  KEYSTORE_PATH="$SECURE_DIR/release.keystore"
  python3 - "$KEYSTORE_PATH" <<'PY'
import base64, os, sys
out = sys.argv[1]
b64 = os.environ.get("ANDROID_KEYSTORE_BASE64", "").strip()
if not b64:
    raise SystemExit("ANDROID_KEYSTORE_BASE64 empty")
with open(out, "wb") as f:
    f.write(base64.b64decode(b64))
PY
fi

export ANDROID_KEYSTORE_PATH="$KEYSTORE_PATH"
export ANDROID_KEYSTORE_PASSWORD="${ANDROID_KEYSTORE_PASSWORD:-}"
export ANDROID_KEY_ALIAS="${ANDROID_KEY_ALIAS:-}"
export ANDROID_KEY_PASSWORD="${ANDROID_KEY_PASSWORD:-}"

if [ -n "${JAVA_HOME:-}" ]; then
  export PATH="$JAVA_HOME/bin:$PATH"
fi

sh "$ROOT_DIR/gradlew" clean :app:assembleRelease --no-daemon

APK_SRC="$ROOT_DIR/app/build/outputs/apk/release/app-release.apk"
if [ ! -f "$APK_SRC" ]; then
  APK_SRC="$ROOT_DIR/app/build/outputs/apk/release/app-release-unsigned.apk"
fi

if [ ! -f "$APK_SRC" ]; then
  echo "release APK not found"
  exit 1
fi

META_JSON="$ROOT_DIR/app/build/outputs/apk/release/output-metadata.json"
VERSION_CODE="0"
VERSION_NAME="unknown"
if [ -f "$META_JSON" ]; then
  VERSION_CODE="$(python3 - "$META_JSON" <<'PY'
import json,sys
j=json.load(open(sys.argv[1],encoding='utf-8'))
print(j.get("elements",[{}])[0].get("versionCode",0))
PY
)"
  VERSION_NAME="$(python3 - "$META_JSON" <<'PY'
import json,sys
j=json.load(open(sys.argv[1],encoding='utf-8'))
print(j.get("elements",[{}])[0].get("versionName","unknown"))
PY
)"
fi

TS_COMPACT="$(date '+%Y%m%d_%H%M%S')"
TS_ISO="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
SAFE_APP_NAME="$(printf '%s' "$APP_NAME" | tr ' ' '_' | tr -cd '[:alnum:]_-.')"
APK_FILENAME="${SAFE_APP_NAME}_v${VERSION_CODE}_${TS_COMPACT}.apk"
APK_DEST="$DROP_DIR/$APK_FILENAME"
cp "$APK_SRC" "$APK_DEST"

if [ -n "${RELEASE_NOTES_FILE}" ] && [ -f "$ROOT_DIR/${RELEASE_NOTES_FILE#android_app/}" ]; then
  cp "$ROOT_DIR/${RELEASE_NOTES_FILE#android_app/}" "$DROP_DIR/RELEASE_NOTES.txt"
elif [ -f "$ROOT_DIR/$RELEASE_NOTES_FILE" ]; then
  cp "$ROOT_DIR/$RELEASE_NOTES_FILE" "$DROP_DIR/RELEASE_NOTES.txt"
else
  {
    echo "Internal release notes"
    echo "request_id: $REQUEST_ID"
    echo "generated_at: $TS_ISO"
    echo "app_id: $APP_ID"
  } > "$DROP_DIR/RELEASE_NOTES.txt"
fi

GIT_COMMIT="$(git -C "$ROOT_DIR" rev-parse --short HEAD 2>/dev/null || echo N/A)"
APK_BYTES="$(wc -c < "$APK_DEST" | tr -d ' ')"
MASKED_APK_PATH="$(printf '%s' "$APK_DEST" | sed "s|$ROOT_DIR|[WORKDIR]|g")"

python3 - "$RELEASE_DIR/.build_context.json" <<PY
import json
data = {
  "request_id": "$REQUEST_ID",
  "generated_at": "$TS_ISO",
  "app_name": "$APP_NAME",
  "app_id": "$APP_ID",
  "version_code": int("$VERSION_CODE"),
  "version_name": "$VERSION_NAME",
  "git_commit": "$GIT_COMMIT",
  "apk_filename": "$APK_FILENAME",
  "apk_path_masked": "$MASKED_APK_PATH",
  "apk_bytes": int("$APK_BYTES")
}
open("$RELEASE_DIR/.build_context.json","w",encoding="utf-8").write(json.dumps(data, ensure_ascii=False, indent=2)+"\n")
PY

echo "PASS request_id=$REQUEST_ID apk=$APK_FILENAME"
