#!/bin/sh
# assert_app_root.sh — Hard path lock. HARUS di NAS (Linux), HARUS di /volume4/web/ERP_RMI_SOFULL.
# Reject Darwin (macOS), reject /Volumes/, reject wrong pwd.
set -euo pipefail

APP_ROOT="/volume4/web/ERP_RMI_SOFULL"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPORT_DIR=""
[ -d "$APP_ROOT/storage/logs" ] && REPORT_DIR="$APP_ROOT/storage/logs" || REPORT_DIR="$SCRIPT_DIR/../../storage/logs"

write_report() {
    local ok="$1"
    local reason="$2"
    mkdir -p "$REPORT_DIR" 2>/dev/null || true
    if [ -d "$REPORT_DIR" ] && [ -w "$REPORT_DIR" ]; then
        (cat <<EOF > "$REPORT_DIR/path_lock_report.json"
{
  "state_version": "path_lock_v1",
  "ok": $ok,
  "expected_root": "[APP_ROOT]",
  "reason": "$reason",
  "checked_at": "$(date -Iseconds 2>/dev/null || date '+%Y-%m-%dT%H:%M:%S%z')",
  "uname": "[REDACTED]"
}
EOF
        ) 2>/dev/null || true
    fi
}

# Reject Darwin (macOS)
U="$(uname -a 2>/dev/null || echo '')"
case "$U" in
    *Darwin*) write_report "false" "uname_contains_Darwin"; echo "RUN_ON_NAS_REQUIRED: please SSH to NAS and run from /volume4/web/ERP_RMI_SOFULL" >&2; exit 2 ;;
esac

# Reject path containing /Volumes/
CUR="$(pwd -P 2>/dev/null || pwd)"
case "$CUR" in
    *"/Volumes/"*) write_report "false" "path_contains_Volumes"; echo "RUN_ON_NAS_REQUIRED: please SSH to NAS and run from /volume4/web/ERP_RMI_SOFULL" >&2; exit 2 ;;
esac

# Reject realpath of script dir containing /Volumes/
SCRIPT_ROOT="$(cd "$SCRIPT_DIR/../.." 2>/dev/null && pwd -P 2>/dev/null || true)"
case "$SCRIPT_ROOT" in
    *"/Volumes/"*) write_report "false" "script_root_Volumes"; echo "RUN_ON_NAS_REQUIRED: please SSH to NAS and run from /volume4/web/ERP_RMI_SOFULL" >&2; exit 2 ;;
esac

# Must be able to cd to APP_ROOT
if ! test -d "$APP_ROOT"; then
    write_report "false" "APP_ROOT_dir_missing"
    echo "FAIL: $APP_ROOT not found" >&2
    exit 1
fi
cd "$APP_ROOT" || { write_report "false" "cd_failed"; echo "FAIL: Cannot cd to [APP_ROOT]" >&2; exit 1; }

# pwd must match exactly
REAL="$(pwd -P 2>/dev/null || pwd)"
if [ "$REAL" != "$APP_ROOT" ]; then
    write_report "false" "pwd_mismatch"
    echo "FAIL: pwd mismatch. Expected [APP_ROOT]" >&2
    exit 1
fi

# Marker file must exist
if ! test -f "$APP_ROOT/.app_root.lock"; then
    write_report "false" "app_root_lock_missing"
    echo "FAIL: .app_root.lock not found" >&2
    exit 1
fi
LOCK_CONTENT="$(cat "$APP_ROOT/.app_root.lock" 2>/dev/null | head -1 | tr -d '\r\n')"
if [ "$LOCK_CONTENT" != "$APP_ROOT" ]; then
    write_report "false" "app_root_lock_content_mismatch"
    echo "FAIL: .app_root.lock content mismatch" >&2
    exit 1
fi

write_report "true" "ok"
echo "OK: APP_ROOT locked"
