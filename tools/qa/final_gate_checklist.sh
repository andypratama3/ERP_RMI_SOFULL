#!/bin/sh
# ERP_RMI_SOFULL — FINAL GATE CHECKLIST (runnable)
# Base: /volume4/web/ERP_RMI_SOFULL
# Wajib: Jalankan dari NAS. Dari Mac mount => FAIL.
set -e

cd /volume4/web/ERP_RMI_SOFULL || { echo "FAIL: cd to APP_ROOT"; exit 1; }
echo "PWD: $(pwd)"

# Load .env for TOOLS_BASE_URL, SMOKE_BASE_URL
if [ -f .env ]; then
  export TOOLS_BASE_URL_INTERNAL="${TOOLS_BASE_URL_INTERNAL:-$(grep -E '^TOOLS_BASE_URL_INTERNAL=' .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")}"
  export SMOKE_BASE_URL="${SMOKE_BASE_URL:-$(grep -E '^SMOKE_BASE_URL=' .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")}"
fi
export TOOLS_BASE_URL_INTERNAL="${TOOLS_BASE_URL_INTERNAL:-https://localhost/ERP_RMI_SOFULL}"
export TOOLS_BASE_URL="${TOOLS_BASE_URL:-$TOOLS_BASE_URL_INTERNAL}"
export SMOKE_BASE_URL="${SMOKE_BASE_URL:-$TOOLS_BASE_URL_INTERNAL}"

PHP_BIN="${ERP_PHP_BIN:-php}"

echo ""
echo "[GATE-0] PATH POLICE"
$PHP_BIN tools/qa/path_police.php --strict --write-last
echo "  OK"

echo ""
echo "[GATE-1] PREFLIGHT"
$PHP_BIN tools/preflight_check.php
echo "  OK"

echo ""
echo "[GATE-2] HEALTH API"
curl -sS "${TOOLS_BASE_URL_INTERNAL}/api/v1/health.php" | head -c 500
echo ""
echo "  (cek success=true, data.db.ok)"

echo ""
echo "[GATE-3] SMOKE HTTP"
$PHP_BIN tools/smoke_http.php --strict --write-last
echo "  OK"

echo ""
echo "[GATE-4] CONTRACT CHECK"
$PHP_BIN tools/qa/contract_check.php --strict --write-last
echo "  OK"

echo ""
echo "[GATE-5] UNICODE GUARD"
$PHP_BIN tools/qa/unicode_guard.php --scan --strict --write-last
echo "  OK"

echo ""
echo "[GATE-6] RBAC MATRIX (GET + POST)"
$PHP_BIN tools/qa/rbac_matrix_http_check.php --strict --write-last
echo "  OK"

echo ""
echo "[GATE-7] AUDIT E2E — SKIP (script belum ada)"
echo "  Manual: cek master/audit_logs.php"

echo ""
echo "[GATE-8] BACKUP/RESTORE — SKIP (manual via Web UI)"
echo "  Manual: tools/backup_now.php, backup_verify.php, restore_now.php"

echo ""
echo "[GATE-9] EVALUATE ALERTS"
$PHP_BIN tools/ops/evaluate_alerts.php
echo "  OK"

echo ""
echo "[GATE-10] DOCS EXIST"
test -f docs/ops/RUNBOOK_INDEX.md && echo "  RUNBOOK_INDEX ok"
test -f docs/ops/CHECKLIST_MODUL_BARU.md && echo "  CHECKLIST_MODUL_BARU ok"
echo "  OK"

echo ""
echo "[GATE-11] CUTOVER FINAL"
$PHP_BIN tools/qa/run_cutover_checks.php --write-last --strict
echo "  OK"

echo ""
echo "=== FINAL GATE DONE ==="
