#!/bin/sh
# remote_run.sh — Jalankan command di NAS via SSH (dari Mac).
# Usage:
#   NAS_SSH_TARGET='user@RMI-2025' ./tools/nas/remote_run.sh php tools/qa/run_cutover_checks.php --write-last --strict
# Wajib: NAS_SSH_TARGET. Default NAS_APP_ROOT=/volume4/web/ERP_RMI_SOFULL.
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
NAS_APP_ROOT="${NAS_APP_ROOT:-/volume4/web/ERP_RMI_SOFULL}"

if [ -z "${NAS_SSH_TARGET:-}" ]; then
    LOGS="$PROJECT_ROOT/storage/logs"
    mkdir -p "$LOGS" 2>/dev/null || true
    if [ -d "$LOGS" ]; then
        printf '{"run_at":"%s","ok":false,"error":"NAS_SSH_TARGET_required","assumption":"Set NAS_SSH_TARGET e.g. user@RMI-2025.local"}\n' \
            "$(date -Iseconds 2>/dev/null || date '+%Y-%m-%dT%H:%M:%S%z')" > "$LOGS/remote_run_last.json"
    fi
    echo "FAIL: NAS_SSH_TARGET required. Example: NAS_SSH_TARGET='user@RMI-2025' $0 <cmd...>" >&2
    exit 2
fi

RUN_AT="$(date -Iseconds 2>/dev/null || date '+%Y-%m-%dT%H:%M:%S%z')"
CMD="$*"

REMOTE="cd $NAS_APP_ROOT && ./tools/nas/erp.sh $*"

OUTPUT=""
EXIT=0
OUTPUT=$(ssh -o BatchMode=yes -o ConnectTimeout=10 "${NAS_SSH_TARGET}" "$REMOTE" 2>&1) || EXIT=$?

# Mask output
echo "$OUTPUT" | sed "s|$NAS_APP_ROOT|[APP_ROOT]|g" | sed 's|/volume4/web/ERP_RMI_SOFULL|[APP_ROOT]|g'

# Write artifact
LOGS="$PROJECT_ROOT/storage/logs"
mkdir -p "$LOGS" 2>/dev/null || true
if [ -d "$LOGS" ]; then
    printf '{"run_at":"%s","cwd":"[APP_ROOT]","cmd":"%s","ok":%s,"via":"remote_run","target":"[REDACTED]"}\n' \
        "$RUN_AT" "$(echo "$CMD" | sed 's/"/\\"/g' | head -c 200)" \
        "$([ "$EXIT" -eq 0 ] && echo true || echo false)" > "$LOGS/remote_run_last.json"
fi

exit $EXIT
