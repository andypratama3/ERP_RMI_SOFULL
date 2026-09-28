#!/usr/bin/env sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
RELEASE_DIR="$ROOT_DIR/output/internal_release"
DROP_DIR="$RELEASE_DIR/drop"
BUILD_CTX="$RELEASE_DIR/.build_context.json"
REQUEST_ID="${RELEASE_REQUEST_ID:-$(uuidgen 2>/dev/null || openssl rand -hex 16)}"
ALLOWLIST_FILE="$ROOT_DIR/tools/release/permissions_allowlist.txt"

if [ ! -f "$BUILD_CTX" ]; then
  echo "Missing build context"
  exit 1
fi

APK_FILENAME="$(python3 - "$BUILD_CTX" <<'PY'
import json,sys
j=json.load(open(sys.argv[1],encoding="utf-8"))
print(j.get("apk_filename",""))
PY
)"
VERSION_CODE="$(python3 - "$BUILD_CTX" <<'PY'
import json,sys
j=json.load(open(sys.argv[1],encoding="utf-8"))
print(j.get("version_code",0))
PY
)"
VERSION_NAME="$(python3 - "$BUILD_CTX" <<'PY'
import json,sys
j=json.load(open(sys.argv[1],encoding="utf-8"))
print(j.get("version_name","unknown"))
PY
)"
APP_ID="$(python3 - "$BUILD_CTX" <<'PY'
import json,sys
j=json.load(open(sys.argv[1],encoding="utf-8"))
print(j.get("app_id",""))
PY
)"

APK_PATH="$DROP_DIR/$APK_FILENAME"
if [ ! -f "$APK_PATH" ]; then
  echo "APK not found: $APK_FILENAME"
  exit 1
fi

CHECKS_TSV="$RELEASE_DIR/.checks.tsv"
FAIL_TSV="$RELEASE_DIR/.failures.tsv"
: > "$CHECKS_TSV"
: > "$FAIL_TSV"

add_check() {
  printf '%s|%s|%s\n' "$1" "$2" "$3" >> "$CHECKS_TSV"
}

add_failure() {
  printf '%s|%s|%s\n' "$1" "$2" "$3" >> "$FAIL_TSV"
}

APK_BYTES="$(wc -c < "$APK_PATH" | tr -d ' ')"
if [ "$APK_BYTES" -gt 0 ]; then
  add_check "apk_exists_nonzero" "PASS" "APK exists and size > 0"
else
  add_check "apk_exists_nonzero" "FAIL" "APK size is 0"
  add_failure "apk_exists_nonzero" "APK size is 0" "Rebuild release APK."
fi

SHA256="$(shasum -a 256 "$APK_PATH" | awk '{print $1}')"
echo "$SHA256  $APK_FILENAME" > "$DROP_DIR/SHA256SUMS.txt"
add_check "sha256_generated" "PASS" "SHA256SUMS.txt generated"

MANIFEST_DUMP=""
if command -v apkanalyzer >/dev/null 2>&1; then
  MANIFEST_DUMP="$(apkanalyzer manifest print "$APK_PATH" 2>/dev/null || true)"
elif command -v aapt >/dev/null 2>&1; then
  MANIFEST_DUMP="$(aapt dump xmltree "$APK_PATH" AndroidManifest.xml 2>/dev/null || true)"
fi

if [ -n "$MANIFEST_DUMP" ]; then
  if printf '%s\n' "$MANIFEST_DUMP" | awk 'BEGIN{ok=1} /debuggable/ && /0xffffffff/{ok=0} END{exit ok?0:1}'; then
    add_check "debuggable_false" "PASS" "No debuggable=true flag found"
  else
    add_check "debuggable_false" "FAIL" "debuggable=true detected"
    add_failure "debuggable_false" "debuggable=true detected" "Set release build non-debuggable."
  fi

  if printf '%s\n' "$MANIFEST_DUMP" | awk 'BEGIN{bad=0} /usesCleartextTraffic/ && /0xffffffff/{bad=1} END{exit bad?1:0}'; then
    add_check "cleartext_traffic_false" "PASS" "usesCleartextTraffic not true"
  else
    add_check "cleartext_traffic_false" "FAIL" "usesCleartextTraffic=true detected"
    add_failure "cleartext_traffic_false" "usesCleartextTraffic=true detected" "Set usesCleartextTraffic=false."
  fi
else
  add_check "manifest_inspection" "WARN" "apkanalyzer/aapt unavailable"
fi

if command -v apksigner >/dev/null 2>&1; then
  if apksigner verify "$APK_PATH" >/dev/null 2>&1; then
    add_check "signature_verify" "PASS" "apksigner verify PASS"
  else
    add_check "signature_verify" "FAIL" "apksigner verify failed"
    add_failure "signature_verify" "apksigner verify failed" "Check signing config and keystore."
  fi
else
  add_check "signature_verify" "WARN" "apksigner not available"
fi

PERMS=""
if command -v apkanalyzer >/dev/null 2>&1; then
  PERMS="$(apkanalyzer manifest permissions "$APK_PATH" 2>/dev/null || true)"
elif command -v aapt >/dev/null 2>&1; then
  PERMS="$(aapt dump permissions "$APK_PATH" 2>/dev/null | awk -F"'" '/uses-permission/{print $2}')"
fi

if [ -n "$PERMS" ]; then
  BAD_PERM=""
  for p in $PERMS; do
    case "$p" in
      android.permission.CAMERA|android.permission.RECORD_AUDIO|android.permission.READ_CONTACTS|android.permission.WRITE_CONTACTS|android.permission.ACCESS_FINE_LOCATION|android.permission.ACCESS_COARSE_LOCATION|android.permission.READ_CALL_LOG|android.permission.WRITE_CALL_LOG|android.permission.READ_SMS|android.permission.SEND_SMS|android.permission.RECEIVE_SMS|android.permission.READ_EXTERNAL_STORAGE|android.permission.WRITE_EXTERNAL_STORAGE|android.permission.BODY_SENSORS|android.permission.POST_NOTIFICATIONS)
        if ! awk -v pp="$p" 'BEGIN{ok=0} $0==pp{ok=1} END{exit ok?0:1}' "$ALLOWLIST_FILE"; then
          BAD_PERM="$BAD_PERM $p"
        fi
        ;;
    esac
  done
  if [ -z "$BAD_PERM" ]; then
    add_check "permissions_policy" "PASS" "No disallowed dangerous permissions"
  else
    add_check "permissions_policy" "FAIL" "Disallowed dangerous permissions:$BAD_PERM"
    add_failure "permissions_policy" "Disallowed dangerous permissions:$BAD_PERM" "Update manifest or allowlist."
  fi
else
  add_check "permissions_policy" "WARN" "permission inspection tool unavailable"
fi

STATUS="PASS"
if awk -F'|' '$2=="FAIL"{exit 1}' "$CHECKS_TSV"; then
  STATUS="PASS"
else
  STATUS="FAIL"
fi

TS_ISO="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
MASKED_APK_PATH="$(printf '%s' "$APK_PATH" | sed "s|$ROOT_DIR|[WORKDIR]|g")"

export _REL_REQUEST_ID="$REQUEST_ID"
export _REL_TS_ISO="$TS_ISO"
export _REL_STATUS="$STATUS"
export _REL_APP_ID="$APP_ID"
export _REL_VERSION_NAME="$VERSION_NAME"
export _REL_VERSION_CODE="$VERSION_CODE"
export _REL_MASKED_APK_PATH="$MASKED_APK_PATH"
export _REL_SHA256="$SHA256"
export _REL_APK_BYTES="$APK_BYTES"

python3 - "$RELEASE_DIR/internal_release_last.json" "$RELEASE_DIR/internal_release_last.md" "$CHECKS_TSV" "$FAIL_TSV" <<'PY'
import json,sys
import os
json_out=sys.argv[1]
md_out=sys.argv[2]
checks_tsv=sys.argv[3]
fail_tsv=sys.argv[4]

checks=[]
for line in open(checks_tsv,encoding="utf-8"):
    parts=line.rstrip("\n").split("|",2)
    if len(parts)==3:
        checks.append({"name":parts[0],"result":parts[1],"notes":parts[2]})

fails=[]
for line in open(fail_tsv,encoding="utf-8"):
    parts=line.rstrip("\n").split("|",2)
    if len(parts)==3:
        fails.append({"step":parts[0],"reason":parts[1],"how_to_fix":parts[2]})

data={
  "state_version":1,
  "request_id":os.environ.get("_REL_REQUEST_ID",""),
  "ts":os.environ.get("_REL_TS_ISO",""),
  "status":os.environ.get("_REL_STATUS","FAIL"),
  "app_id":os.environ.get("_REL_APP_ID",""),
  "version_name":os.environ.get("_REL_VERSION_NAME","unknown"),
  "version_code":int(os.environ.get("_REL_VERSION_CODE","0")),
  "apk":{"path_masked":os.environ.get("_REL_MASKED_APK_PATH",""),"sha256":os.environ.get("_REL_SHA256",""),"bytes":int(os.environ.get("_REL_APK_BYTES","0"))},
  "checks":checks,
  "failures":fails
}
open(json_out,"w",encoding="utf-8").write(json.dumps(data, ensure_ascii=False, indent=2)+"\n")

lines=[]
lines.append(f"# Internal Release Verification ({data['status']})")
lines.append("")
lines.append(f"- request_id: `{data['request_id']}`")
lines.append(f"- ts: `{data['ts']}`")
lines.append(f"- app_id: `{data['app_id']}`")
lines.append(f"- version: `{data['version_name']}` (`{data['version_code']}`)")
lines.append(f"- apk: `{data['apk']['path_masked']}`")
lines.append(f"- sha256: `{data['apk']['sha256']}`")
lines.append("")
lines.append("## Checks")
for c in checks:
    lines.append(f"- {c['name']}: **{c['result']}** - {c['notes']}")
if fails:
    lines.append("")
    lines.append("## Failures")
    for f in fails:
        lines.append(f"- {f['step']}: {f['reason']} (Fix: {f['how_to_fix']})")
open(md_out,"w",encoding="utf-8").write("\n".join(lines)+"\n")
PY

echo "$STATUS request_id=$REQUEST_ID"
[ "$STATUS" = "PASS" ]
