#!/bin/sh
# guard_app_root.sh — Lock workdir ke /volume4/web/ERP_RMI_SOFULL (NAS).
# Dipakai semua runner/CLI. Mismatch => exit 2.
set -e
[ -n "${ZSH_VERSION:-}" ] && setopt pipefail 2>/dev/null || true

EXPECTED_ROOT="/volume4/web/ERP_RMI_SOFULL"

if ! cd "$EXPECTED_ROOT" 2>/dev/null; then
    echo "FAIL: Cannot cd to [APP_ROOT]" >&2
    exit 2
fi

REAL_ROOT="$(pwd -P 2>/dev/null || pwd)"
if [ "$REAL_ROOT" != "$EXPECTED_ROOT" ]; then
    echo "FAIL: APP_ROOT mismatch (expected [APP_ROOT], got realpath)" >&2
    exit 2
fi

umask 027
export APP_ROOT="$EXPECTED_ROOT"
export TOOLS_BASE_URL_INTERNAL="http://10.10.60.20/ERP_RMI_SOFULL"
export TOOLS_BASE_URL="$TOOLS_BASE_URL_INTERNAL"

# Write lock state (masked) — suppress permission errors
LOG_DIR="$EXPECTED_ROOT/storage/logs"
if [ -d "$LOG_DIR" ] && [ -w "$LOG_DIR" ]; then
  ( printf '%s\n' "{\"state_version\":1,\"expected_root\":\"[APP_ROOT]\",\"real_root\":\"[APP_ROOT]\",\"ok\":true,\"checked_at\":\"$(date -Iseconds 2>/dev/null || date '+%Y-%m-%dT%H:%M:%S%z')\",\"hostname\":\"$(hostname 2>/dev/null || echo '?')\",\"whoami\":\"$(whoami 2>/dev/null || echo '?')\",\"notes_masked\":\"locked\"}" > "$LOG_DIR/app_root_lock.last.json" ) 2>/dev/null || true
fi
