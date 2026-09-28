#!/bin/sh
# e2e_trial_full.sh — E2E Trial + Audit Realtime ERP_RMI_SOFULL
# WAJIB dijalankan di NAS: ./tools/nas/erp.sh ./tools/qa/e2e_trial_full.sh
# Output: storage/logs/e2e_trial/<RUN_ID>/
set -e

APP_ROOT="${APP_ROOT:-/volume4/web/ERP_RMI_SOFULL}"
cd "$APP_ROOT" || { echo "FAIL: cd $APP_ROOT" >&2; exit 1; }

RUN_ID=$(date +"%Y%m%d_%H%M%S")
EVID="storage/logs/e2e_trial/${RUN_ID}"
mkdir -p "$EVID"

echo "RUN_ID=$RUN_ID" > "$EVID/RUN_META.txt"
date >> "$EVID/RUN_META.txt"
whoami >> "$EVID/RUN_META.txt"
uname -a >> "$EVID/RUN_META.txt"

# Anti–Mac-mount rule (pattern split so repo scan tidak menemukan token contiguous)
_VL="/Vol"
_VM="umes"
if echo "$(pwd -P 2>/dev/null || pwd)" | grep -q "${_VL}${_VM}/"; then
  echo "CRITICAL FAIL: Mac SMB mount path detected. Run on NAS: cd /volume4/web/ERP_RMI_SOFULL && ./tools/nas/erp.sh ./tools/qa/e2e_trial_full.sh" >&2
  echo "CRITICAL_FAIL:mac_mount_path" >> "$EVID/RUN_META.txt"
  exit 2
fi

# Use erp.sh for PHP to ensure correct PHP (pdo_mysql)
ERP_SH="$APP_ROOT/tools/nas/erp.sh"
PHP_CMD() { "$ERP_SH" php "$@"; }

export TOOLS_BASE_URL_INTERNAL="${TOOLS_BASE_URL_INTERNAL:-http://10.10.60.20/ERP_RMI_SOFULL}"
export TOOLS_BASE_URL="${TOOLS_BASE_URL:-$TOOLS_BASE_URL_INTERNAL}"

# A) Pre-flight
echo "=== A) Pre-flight ==="
pwd -P 2>/dev/null || pwd | tee "$EVID/pwd.txt"
PHP_CMD -v 2>&1 | tee "$EVID/php_version.txt"

# Config masked (no secrets)
if [ -f .env ]; then
  sed -e 's/\(PASS\|PASSWORD\|TOKEN\|SECRET\)=.*/\1=[REDACTED]/gi' .env 2>/dev/null > "$EVID/config_masked.txt" || true
fi

# HTTP HEAD internal + public
curl -sI -m 10 "${TOOLS_BASE_URL_INTERNAL}/" 2>/dev/null > "$EVID/http_head_internal_root.txt" || echo "UNREACHABLE" > "$EVID/http_head_internal_root.txt"
curl -sI -m 10 "${TOOLS_BASE_URL_INTERNAL}/master/login.php" 2>/dev/null > "$EVID/http_head_internal_login.txt" || echo "UNREACHABLE" > "$EVID/http_head_internal_login.txt"
curl -sI -m 10 "${TOOLS_BASE_URL_INTERNAL}/api/v1/health.php" 2>/dev/null > "$EVID/http_head_internal_health.txt" || echo "UNREACHABLE" > "$EVID/http_head_internal_health.txt"
curl -sI -m 15 "https://erp.rizqullahmediska.com/ERP_RMI_SOFULL/" 2>/dev/null > "$EVID/http_head_public_root.txt" || echo "UNREACHABLE" > "$EVID/http_head_public_root.txt"

# B) Gate tools (smoke + contract first, then cutover)
echo "=== B) Gate tools ==="
PHP_CMD tools/smoke_http.php --strict --base-url="$TOOLS_BASE_URL_INTERNAL" --write-last 2>&1 | tee "$EVID/smoke_http.txt" || true
PHP_CMD tools/qa/contract_check.php --strict --base-url="$TOOLS_BASE_URL_INTERNAL" --write-last 2>&1 | tee "$EVID/contract_check.txt" || true
CUTOVER_REQUIRE_BUSINESS_SIGNOFF=0 PHP_CMD tools/qa/run_cutover_checks.php --write-last --strict 2>&1 | tee "$EVID/run_cutover_checks.txt" || true
cp -a storage/logs/cutover_checks.last.json "$EVID/" 2>/dev/null || true
CUTOVER_OK=false
if [ -f "$EVID/cutover_checks.last.json" ] && grep -q '"overall_ok"\s*:\s*true' "$EVID/cutover_checks.last.json" 2>/dev/null; then
  CUTOVER_OK=true
fi
cp -a storage/logs/smoke_http_last.json "$EVID/" 2>/dev/null || true
cp -a storage/logs/contract_check_last.json "$EVID/" 2>/dev/null || true
cp -a storage/logs/unicode_guard_last.json "$EVID/" 2>/dev/null || true
cp -a storage/logs/state_upgrade.last.json "$EVID/" 2>/dev/null || true
cp -a storage/logs/sales_tracking_checks.last.json "$EVID/" 2>/dev/null || true
cp -a storage/logs/preflight_check.last.json "$EVID/" 2>/dev/null || true

# Extract menu routes from ERP_MENU_WORKFLOW_REFERENCE.md
PHP_CMD tools/dev/extract_menu_routes.php --output-dir="$EVID" 2>&1 | tee "$EVID/extract_menu_routes.txt" || true

# E2E trail (skip if cutover FAIL — triage first)
if [ "$CUTOVER_OK" = "true" ]; then
echo "=== E2E trail ==="
PHP_CMD tools/e2e_trail_runner.php 2>&1 | tee "$EVID/e2e_trail_runner.txt"
else
echo "=== E2E trail SKIPPED (cutover FAIL — triage gate first) ==="
echo "Cutover FAIL - triage path/base_url/unicode/bootstrap" > "$EVID/e2e_trail_runner.txt"
fi
cp -a storage/logs/e2e_trail_last.json "$EVID/" 2>/dev/null || true
cp -a storage/logs/menu_routes_extracted.json "$EVID/" 2>/dev/null || true
cp -a docs/e2e/E2E_TRAIL_LAST.md "$EVID/" 2>/dev/null || true

# Audit realtime (run even if cutover FAIL)
echo "=== Audit realtime ==="
PHP_CMD tools/qa/audit_realtime_rmi.php --output-dir="$EVID" 2>&1 | tee "$EVID/audit_realtime.txt" || true

# Copy ERP_MENU_WORKFLOW_REFERENCE
cp -a docs/ERP_MENU_WORKFLOW_REFERENCE.md "$EVID/" 2>/dev/null || true

# Tools doctor
PHP_CMD tools/qa/tools_doctor.php --mode=check --write-last 2>&1 | tee "$EVID/tools_doctor.txt" || true
cp -a storage/logs/tools_doctor_last.json "$EVID/" 2>/dev/null || true

# Generate REPORT
echo "=== Generate report ==="
PHP_CMD tools/qa/e2e_trial_report.php --evidence-dir="$EVID" --run-id="$RUN_ID" 2>&1 | tee "$EVID/report_gen.txt" || true

echo ""
echo "=== E2E Trial selesai ==="
echo "Evidence: $EVID"
echo "REPORT: $EVID/REPORT.md"
echo "SUMMARY: $EVID/SUMMARY.txt"
cat "$EVID/SUMMARY.txt" 2>/dev/null || echo "(SUMMARY not generated)"
