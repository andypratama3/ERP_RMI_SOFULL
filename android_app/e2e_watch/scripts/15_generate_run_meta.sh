#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/_common.sh"

START_TS_FILE="$RUN_DIR/start_epoch.txt"
start_epoch="$(cat "$START_TS_FILE" 2>/dev/null || date +%s)"
end_epoch="$(date +%s)"
duration_ms=$(( (end_epoch - start_epoch) * 1000 ))
ts="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

APK_PATH="$(cat "$RUN_DIR/apk_path.txt" 2>/dev/null || true)"
APK_NAME="$(basename "${APK_PATH:-unknown.apk}")"
version_name="unknown"
version_code=0
if [[ -f "$ROOT_DIR/app/build.gradle.kts" ]]; then
  version_name="$(awk -F'"' '/versionName =/ {print $2; exit}' "$ROOT_DIR/app/build.gradle.kts")"
  version_code="$(awk -F'=' '/versionCode =/ {gsub(/ /,"",$2); print $2; exit}' "$ROOT_DIR/app/build.gradle.kts")"
fi

JUNIT_FILE="$JUNIT_DIR/results.xml"
tests=0
failed=0
skipped=0
if [[ -f "$JUNIT_FILE" ]]; then
  tests="$(python3 - <<PY
import re
s=open("$JUNIT_FILE","r",encoding="utf-8",errors="ignore").read()
m=re.search(r'tests="(\\d+)"', s); print(m.group(1) if m else "0")
PY
)"
  failed="$(python3 - <<PY
import re
s=open("$JUNIT_FILE","r",encoding="utf-8",errors="ignore").read()
f=0
for k in ("failures","errors"):
    m=re.search(r'%s="(\\d+)"' % k, s)
    if m: f += int(m.group(1))
print(f)
PY
)"
  skipped="$(python3 - <<PY
import re
s=open("$JUNIT_FILE","r",encoding="utf-8",errors="ignore").read()
m=re.search(r'skipped="(\\d+)"', s); print(m.group(1) if m else "0")
PY
)"
fi

base_masked="$(printf '%s' "$BASE_URL" | sed 's#//[^/@]*:[^/@]*@#//[REDACTED]@#g')"

cat > "$RUN_DIR/run_meta.json" <<EOF
{
  "state_version": 1,
  "request_id": "$REQUEST_ID",
  "run_id": "$RUN_ID",
  "ts": "$ts",
  "app": {
    "package": "$APP_PACKAGE",
    "version_name": "$version_name",
    "version_code": $version_code,
    "apk_name": "$APK_NAME"
  },
  "target": {
    "base_url": "$base_masked",
    "env": "$TARGET_ENV"
  },
  "device": {
    "avd_name": "$AVD_NAME",
    "api_level": $AVD_API_LEVEL,
    "abi": "$AVD_ABI",
    "visible": $( [[ "$WATCH_MODE" == "1" ]] && echo true || echo false )
  },
  "suite": {
    "name": "E2E Watch",
    "framework": "appium",
    "runner": "wdio",
    "duration_ms": $duration_ms,
    "tests": $tests,
    "failed": $failed,
    "skipped": $skipped
  }
}
EOF

echo "PASS run_meta generated: $(mask_path "$RUN_DIR/run_meta.json")"
