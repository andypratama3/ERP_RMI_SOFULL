#!/usr/bin/env sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
RELEASE_DIR="$ROOT_DIR/output/internal_release"
DROP_DIR="$RELEASE_DIR/drop"
LAST_JSON="$RELEASE_DIR/internal_release_last.json"
HISTORY_JSONL="$RELEASE_DIR/internal_manifest_history.jsonl"
LATEST_JSON="$RELEASE_DIR/internal_manifest.json"
REQUEST_ID="${RELEASE_REQUEST_ID:-$(uuidgen 2>/dev/null || openssl rand -hex 16)}"

if [ ! -f "$LAST_JSON" ]; then
  echo "Missing verification result"
  exit 1
fi

STATUS="$(python3 - "$LAST_JSON" <<'PY'
import json,sys
print(json.load(open(sys.argv[1],encoding='utf-8')).get("status","FAIL"))
PY
)"
if [ "$STATUS" != "PASS" ]; then
  echo "Verification status is not PASS"
  exit 1
fi

APK_FILENAME="$(python3 - "$RELEASE_DIR/.build_context.json" <<'PY'
import json,sys
print(json.load(open(sys.argv[1],encoding='utf-8')).get("apk_filename",""))
PY
)"
SHA256="$(python3 - "$LAST_JSON" <<'PY'
import json,sys
print(json.load(open(sys.argv[1],encoding='utf-8')).get("apk",{}).get("sha256",""))
PY
)"
BYTES="$(python3 - "$LAST_JSON" <<'PY'
import json,sys
print(json.load(open(sys.argv[1],encoding='utf-8')).get("apk",{}).get("bytes",0))
PY
)"
VERSION_NAME="$(python3 - "$LAST_JSON" <<'PY'
import json,sys
print(json.load(open(sys.argv[1],encoding='utf-8')).get("version_name","unknown"))
PY
)"
VERSION_CODE="$(python3 - "$LAST_JSON" <<'PY'
import json,sys
print(json.load(open(sys.argv[1],encoding='utf-8')).get("version_code",0))
PY
)"
TS_ISO="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"

NOTES="$(awk 'NR<=40{print}' "$DROP_DIR/RELEASE_NOTES.txt" 2>/dev/null | tr '\n' ' ' | cut -c1-2000)"

python3 - "$LATEST_JSON" <<PY
import json
data={
  "state_version":1,
  "generated_at":"$TS_ISO",
  "latest":{
    "version_name":"$VERSION_NAME",
    "version_code":int("$VERSION_CODE"),
    "apk_filename":"$APK_FILENAME",
    "sha256":"$SHA256",
    "bytes":int("$BYTES"),
    "release_notes":"$NOTES"
  }
}
open("$LATEST_JSON","w",encoding="utf-8").write(json.dumps(data, ensure_ascii=False, indent=2)+"\n")
PY

printf '{"ts":"%s","version_code":%s,"apk_filename":"%s","sha256":"%s","bytes":%s,"request_id":"%s"}\n' \
  "$TS_ISO" "$VERSION_CODE" "$APK_FILENAME" "$SHA256" "$BYTES" "$REQUEST_ID" >> "$HISTORY_JSONL"

cp "$LATEST_JSON" "$DROP_DIR/internal_manifest.json"

# retention 10 APK terakhir
count=0
for f in $(ls -1t "$DROP_DIR"/*.apk 2>/dev/null); do
  count=$((count+1))
  if [ "$count" -gt 10 ]; then
    rm -f "$f"
  fi
done

echo "PASS request_id=$REQUEST_ID"
