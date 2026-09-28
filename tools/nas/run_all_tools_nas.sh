#!/bin/sh
# ============================================================
# Jalankan SEMUA Tools ERP_RMI_SOFULL di NAS secara berurutan
# Kompatibel dengan Synology ash (sh)
#
# CARA PAKAI:
#   cd /volume4/web/ERP_RMI_SOFULL
#   chmod +x tools/nas/run_all_tools_nas.sh
#   sh tools/nas/run_all_tools_nas.sh
#
# OPSI:
#   --skip-backup     Lewati backup (lebih cepat)
#   --skip-checks     Lewati Run All Checks (smoke HTTP butuh web server)
#   --run-migrations Jalankan migrations (hanya jika perlu upgrade schema)
#   --base-url URL   URL ERP untuk smoke test (default: baca dari .env APP_URL)
#
# CONTOH:
#   sh tools/nas/run_all_tools_nas.sh --base-url "http://192.168.1.100/ERP_RMI_SOFULL"
#   sh tools/nas/run_all_tools_nas.sh --skip-backup --skip-checks
# ============================================================

set -e
. "$(cd "$(dirname "$0")" && pwd)/ensure_app_root.sh"

ROOT_DIR="$APP_ROOT"
cd "$ROOT_DIR"

SKIP_BACKUP=false
SKIP_CHECKS=false
RUN_MIGRATIONS=false
BASE_URL=""

# Parse .env untuk APP_URL / SMOKE_BASE_URL / ERP_PHP_BIN (POSIX)
if [ -f "${ROOT_DIR}/.env" ]; then
  BASE_URL="$(grep -E '^APP_URL=' .env 2>/dev/null | cut -d= -f2- | sed "s/[\"']//g" | sed 's/#.*//' | tr -d ' \t\r\n' | head -1)"
  if [ -z "$BASE_URL" ]; then
    BASE_URL="$(grep -E '^SMOKE_BASE_URL=' .env 2>/dev/null | cut -d= -f2- | sed "s/[\"']//g" | sed 's/#.*//' | tr -d ' \t\r\n' | head -1)"
  fi
  if [ -z "${PHP_BINARY:-}" ]; then
    PHP_BINARY="$(grep -E '^ERP_PHP_BIN=' .env 2>/dev/null | cut -d= -f2- | sed "s/[\"']//g" | sed 's/#.*//' | tr -d ' \t\r\n' | head -1)"
  fi
fi

# Parse args
while [ $# -gt 0 ]; do
  case "$1" in
    --skip-backup)     SKIP_BACKUP=true; shift ;;
    --skip-checks)     SKIP_CHECKS=true; shift ;;
    --run-migrations) RUN_MIGRATIONS=true; shift ;;
    --base-url)
      BASE_URL="${2:-}"
      shift 2
      ;;
    *)
      echo "Unknown option: $1"
      echo "Usage: $0 [--skip-backup] [--skip-checks] [--run-migrations] [--base-url URL]"
      exit 1
      ;;
  esac
done

# PHP: prefer ERP_PHP_BIN dari .env, atau cari php84/82/81 (sesuai PANDUAN_NAS_LENGKAP: PHP 8.4)
if [ -n "${PHP_BINARY:-}" ] && [ -x "${PHP_BINARY}" ]; then
  PHP_BIN="${PHP_BINARY}"
else
  PHP_BIN=""
  for p in /usr/local/bin/php84 /usr/local/bin/php82 /usr/local/bin/php81 /usr/local/bin/php80 /usr/bin/php; do
    [ -x "$p" ] || continue
    if "$p" -m 2>/dev/null | grep -q pdo_mysql; then
      PHP_BIN="$p"
      break
    fi
  done
  PHP_BIN="${PHP_BIN:-php}"
fi
export ERP_DB_HOST="${ERP_DB_HOST:-127.0.0.1}"
export ERP_DB_PORT="${ERP_DB_PORT:-3306}"
export ERP_DB_NAME="${ERP_DB_NAME:-erp_rmi_sofull}"
export ERP_DB_USER="${ERP_DB_USER:-root}"
export ERP_DB_PASS="${ERP_DB_PASS:-RmiHome@2025}"

echo "=============================================="
echo "  ERP_RMI_SOFULL — Run All Tools (NAS)"
echo "=============================================="
echo "Root: $ROOT_DIR"
echo "Time: $(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo ""

FAIL_COUNT=0

# --- 1. Preflight ---
echo "[1/6] Preflight Check..."
if $PHP_BIN "${ROOT_DIR}/tools/preflight_check.php" 2>&1 | tail -20; then
  echo "  -> OK"
else
  echo "  -> FAIL"
  FAIL_COUNT=$((FAIL_COUNT + 1))
fi
echo ""

# --- 2. Doctor ---
echo "[2/6] Doctor..."
if $PHP_BIN "${ROOT_DIR}/tools/doctor/run_doctor.php" 2>&1 | tail -5; then
  echo "  -> OK"
else
  echo "  -> FAIL (atau lock aktif)"
  FAIL_COUNT=$((FAIL_COUNT + 1))
fi
echo ""

# --- 3. Run All Checks (quick) ---
if [ "$SKIP_CHECKS" = "true" ]; then
  echo "[3/6] Run All Checks — SKIP (--skip-checks)"
else
  echo "[3/6] Run All Checks (quick)..."
  export STAGING_BASE_URL="$BASE_URL"
  export SMOKE_BASE_URL="$BASE_URL"
  export APP_URL="$BASE_URL"
  if [ -z "$BASE_URL" ]; then
    echo "  ! SMOKE_BASE_URL kosong — smoke HTTP mungkin gagal. Set --base-url atau APP_URL di .env"
  fi
  if $PHP_BIN "${ROOT_DIR}/tools/qa/run_all_checks.php" --quick 2>&1 | tail -3; then
    echo "  -> OK"
  else
    echo "  -> FAIL (bisa karena web server tidak jalan atau base_url salah)"
    FAIL_COUNT=$((FAIL_COUNT + 1))
  fi
fi
echo ""

# --- 4. Backup ---
if [ "$SKIP_BACKUP" = "true" ]; then
  echo "[4/6] Backup — SKIP (--skip-backup)"
else
  echo "[4/6] Backup..."
  if "${ROOT_DIR}/tools/backup_now.sh" --label "nas_tools_run_$(date +%Y%m%d_%H%M%S)" 2>&1 | tail -10; then
    echo "  -> OK"
  else
    echo "  -> FAIL (jika error: backup_now.sh butuh bash, coba --skip-backup)"
    FAIL_COUNT=$((FAIL_COUNT + 1))
  fi
fi
echo ""

# --- 5. Migrations (opsional) ---
if [ "$RUN_MIGRATIONS" = "true" ]; then
  echo "[5/6] Run Migrations..."
  if [ -x "${ROOT_DIR}/tools/nas/run_all_migrations.sh" ]; then
    sh "${ROOT_DIR}/tools/nas/run_all_migrations.sh" 2>&1 | tail -15
    echo "  -> Done"
  else
    chmod +x "${ROOT_DIR}/tools/nas/run_all_migrations.sh"
    sh "${ROOT_DIR}/tools/nas/run_all_migrations.sh" 2>&1 | tail -15
    echo "  -> Done"
  fi
else
  echo "[5/6] Migrations — SKIP (pakai --run-migrations jika perlu)"
fi
echo ""

# --- 6. Summary ---
echo "[6/6] Summary"
echo "=============================================="
if [ $FAIL_COUNT -eq 0 ]; then
  echo "  Semua tools selesai. FAIL: 0"
else
  echo "  FAIL count: $FAIL_COUNT"
fi
echo ""
echo "Hasil detail tersimpan di:"
echo "  - storage/logs/preflight_check.last.json"
echo "  - storage/logs/doctor_last.json"
echo "  - storage/logs/all_checks.last.json"
echo "  - storage/backups/ (jika backup dijalankan)"
echo ""
echo "Tools yang HARUS dijalankan via Web (login dulu):"
echo "  - UAT Smoke Runner"
echo "  - Go-Live Health"
echo "  - Backup Manager / Verify"
echo "=============================================="

exit $FAIL_COUNT
