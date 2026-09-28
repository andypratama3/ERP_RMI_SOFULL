#!/usr/bin/env sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
REL_DIR="$ROOT_DIR/output/internal_release"
DROP_DIR="$REL_DIR/drop"
REQ_ID="${RELEASE_REQUEST_ID:-$(uuidgen 2>/dev/null || openssl rand -hex 16)}"
TS="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"

fail() {
  echo "FAIL request_id=$REQ_ID step=$1 reason=$2"
  exit 1
}

req_file() {
  [ -f "$1" ] || fail "required_file" "$(printf '%s' "$1" | sed "s|$ROOT_DIR|[WORKDIR]|g") missing"
}

req_file "$REL_DIR/internal_release_last.json"
req_file "$REL_DIR/internal_release_last.md"
req_file "$REL_DIR/internal_manifest.json"
req_file "$REL_DIR/internal_manifest_history.jsonl"
req_file "$DROP_DIR/SHA256SUMS.txt"
req_file "$DROP_DIR/internal_manifest.json"
req_file "$DROP_DIR/download/index.html"
req_file "$DROP_DIR/download/qr_latest.txt"

apk_count="$(ls -1 "$DROP_DIR"/*.apk 2>/dev/null | wc -l | tr -d ' ')"
[ "${apk_count:-0}" -gt 0 ] || fail "apk_count" "no apk in drop folder"

status="$(python3 - "$REL_DIR/internal_release_last.json" <<'PY'
import json,sys
print(json.load(open(sys.argv[1],encoding='utf-8')).get("status","FAIL"))
PY
)"
[ "$status" = "PASS" ] || fail "verify_status" "internal_release_last.json status=$status"

echo "PASS request_id=$REQ_ID"
python3 - <<PY
import json
print(json.dumps({
  "state_version":1,
  "request_id":"$REQ_ID",
  "ts":"$TS",
  "status":"PASS",
  "checks":[
    {"name":"required_files","result":"PASS"},
    {"name":"apk_exists","result":"PASS","count":int("$apk_count")},
    {"name":"verify_status","result":"PASS","value":"$status"}
  ]
}, ensure_ascii=False, indent=2))
PY
