#!/bin/sh
# erp.sh — Wrapper: path-lock + cd ke APP_ROOT + jalankan command.
# Usage: ./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --write-last --strict
#        ./tools/nas/erp.sh ./tools/nas/run_all_tools_auto.sh
# HARUS dijalankan di NAS (/volume4/web/ERP_RMI_SOFULL). Dari Mac mount => FAIL.
set -e

APP_ROOT="/volume4/web/ERP_RMI_SOFULL"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Fast fail: detect Mac mount (pwd or script location)
CUR="$(pwd -P 2>/dev/null || pwd)"
case "$CUR" in
    *"/Volumes/"*) echo "RUN_ON_NAS_REQUIRED: please SSH to NAS and run from /volume4/web/ERP_RMI_SOFULL" >&2; exit 2 ;;
esac
SCRIPT_ROOT="$(cd "$SCRIPT_DIR/../.." 2>/dev/null && pwd -P 2>/dev/null || true)"
case "$SCRIPT_ROOT" in
    *"/Volumes/"*) echo "RUN_ON_NAS_REQUIRED: please SSH to NAS and run from /volume4/web/ERP_RMI_SOFULL" >&2; exit 2 ;;
esac
case "$(uname -s 2>/dev/null)" in
    Darwin) echo "RUN_ON_NAS_REQUIRED: please SSH to NAS and run from /volume4/web/ERP_RMI_SOFULL" >&2; exit 2 ;;
esac

. "${SCRIPT_DIR}/assert_app_root.sh"

if [ $# -eq 0 ]; then
    echo "Usage: $0 <command> [args...]" >&2
    exit 1
fi

export APP_ROOT="$APP_ROOT"
# URL internal untuk CLI smoke/contract checks
# Pakai https://localhost (bypass Cloudflare, no 301 redirect, SSL_VERIFYPEER=false)
export TOOLS_BASE_URL_INTERNAL="${TOOLS_BASE_URL_INTERNAL:-https://localhost/ERP_RMI_SOFULL}"
export TOOLS_BASE_URL="${TOOLS_BASE_URL:-$TOOLS_BASE_URL_INTERNAL}"

# Load .env: ERP_PHP_BIN, TOOLS_BASE_URL_INTERNAL, SMOKE_BASE_URL (agar tidak pernah kosong di production)
if [ -f "$APP_ROOT/.env" ]; then
  val=$(grep -E '^ERP_PHP_BIN=' "$APP_ROOT/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'" | tr -d ' ')
  [ -n "$val" ] && export ERP_PHP_BIN="$val"
  val=$(grep -E '^TOOLS_BASE_URL_INTERNAL=' "$APP_ROOT/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'" | tr -d ' ')
  [ -n "$val" ] && export TOOLS_BASE_URL_INTERNAL="$val"
  val=$(grep -E '^SMOKE_BASE_URL=' "$APP_ROOT/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'" | tr -d ' ')
  [ -n "$val" ] && export SMOKE_BASE_URL="$val"
fi
PHP_BIN="${ERP_PHP_BIN:-}"
if [ -z "$PHP_BIN" ] || ! [ -x "$PHP_BIN" ]; then
  # Try to find PHP with pdo_mysql, fallback to default php
  for p in /usr/local/bin/php84 /usr/local/bin/php82 /usr/local/bin/php81 /usr/bin/php php; do
    _p_resolved="$(command -v "$p" 2>/dev/null || true)"
    if [ -n "$_p_resolved" ] && [ -x "$_p_resolved" ]; then
      # Test the binary works (basic sanity check)
      if "$_p_resolved" -r "echo 'ok';" 2>/dev/null | grep -q ok; then
        if "$_p_resolved" -m 2>/dev/null | grep -q pdo_mysql; then
          PHP_BIN="$_p_resolved"
          break
        elif [ -z "$PHP_BIN" ]; then
          # Save first working binary as fallback (even without pdo_mysql)
          PHP_BIN="$_p_resolved"
        fi
      fi
    fi
  done
fi
[ -z "$PHP_BIN" ] && PHP_BIN="php"

# Jika arg pertama "php", gunakan PHP_BIN yang punya pdo_mysql
if [ "$1" = "php" ]; then
  shift
  set -- "$PHP_BIN" "$@"
fi

CMD="$*"
RUN_AT="$(date -Iseconds 2>/dev/null || date '+%Y-%m-%dT%H:%M:%S%z')"
CWD="$(pwd -P 2>/dev/null || pwd)"

"$@"
EXIT=$?

# Write artifact (masked) — JANGAN pernah tulis /Volumes/ atau path Mac ke JSON
LOGS="$APP_ROOT/storage/logs"
mkdir -p "$LOGS" 2>/dev/null || true
if [ -d "$LOGS" ]; then
    CMD_ESCAPED=$(echo "$CMD" | sed 's/"/\\"/g' | head -c 500)
    # Mask path Mac/NAS agar path_police PASS
    CMD_MASKED=$(echo "$CMD_ESCAPED" | sed 's|/Volumes/[^ ]*|\[APP_ROOT\]|g' | sed 's|/volume4/web/ERP_RMI_SOFULL|[APP_ROOT]|g')
    printf '{"run_at":"%s","cwd":"[APP_ROOT]","cmd":"%s","ok":%s}\n' \
        "$RUN_AT" "$CMD_MASKED" \
        "$([ "$EXIT" -eq 0 ] && echo true || echo false)" > "$LOGS/erp_sh_last.json"
fi

exit $EXIT
