#!/bin/sh
# Run all tools automation di NAS.
# Cron: 0 */6 * * * /volume4/web/ERP_RMI_SOFULL/tools/nas/run_all_tools_auto.sh
# Atau jalankan manual: ./tools/nas/run_all_tools_auto.sh

set -e
. "$(cd "$(dirname "$0")" && pwd)/assert_app_root.sh"
. "$(cd "$(dirname "$0")" && pwd)/ensure_app_root.sh"

# Cari PHP dengan pdo_mysql (sesuai PANDUAN_NAS_LENGKAP: PHP 8.4)
PHP_BIN=""
for p in /usr/local/bin/php84 /usr/local/bin/php82 /usr/local/bin/php81 /usr/local/bin/php80 /usr/bin/php; do
    [ -x "$p" ] || continue
    if "$p" -m 2>/dev/null | grep -q pdo_mysql; then
        PHP_BIN="$p"
        break
    fi
done
[ -n "$PHP_BIN" ] || { echo "FAIL: no PHP with pdo_mysql"; exit 1; }

export ERP_PHP_BIN="$PHP_BIN"
export TOOLS_BASE_URL_INTERNAL="${TOOLS_BASE_URL_INTERNAL:-http://10.10.60.20/ERP_RMI_SOFULL}"
export TOOLS_BASE_URL="${TOOLS_BASE_URL:-$TOOLS_BASE_URL_INTERNAL}"

"$PHP_BIN" tools/nas/assert_app_root.php || exit $?

echo "=== Run All Tools Auto @ $(date -Iseconds) ==="
echo "PHP: $PHP_BIN"

# 0) Volumes guard — CRITICAL: must pass before cutover (block /Volumes)
"$PHP_BIN" tools/qa/volumes_guard.php --write-last || exit 3

# 1) All checks (cutover + negative) — cutover sudah include smoke_http, contract, sales_tracking, preflight
"$PHP_BIN" tools/qa/run_all_checks.php --quick
ALL=$?

# 2) Tools doctor
"$PHP_BIN" tools/qa/tools_doctor.php --write-last
DOCTOR=$?

# 3) Data for Executive Summary — health, readiness, backup_last, smoke_core_flows, pipeline_last stub
"$PHP_BIN" tools/health_check_cli.php 2>/dev/null || true
"$PHP_BIN" tools/ops/generate_readiness_report.php 2>/dev/null || true
"$PHP_BIN" tools/ops/write_backup_last_stub.php 2>/dev/null || true
"$PHP_BIN" tools/qa/smoke_core_flows.php 2>/dev/null || true
"$PHP_BIN" tools/qa/write_pipeline_last_from_cutover.php 2>/dev/null || true

# 4) Control center refresh (non-blocking)
"$PHP_BIN" tools/qa/php_error_scan.php --write-last 2>/dev/null || true
"$PHP_BIN" tools/release/release_gate.php 2>/dev/null || true
"$PHP_BIN" tools/dev/verify_sop_links.php 2>/dev/null || true

# 5) Fix backlog + ops score + change control (for readiness)
"$PHP_BIN" tools/ops/generate_fix_backlog.php --env=staging --write-last 2>/dev/null || true
"$PHP_BIN" tools/ops/snapshot_fix_backlog.php --env=staging --write-last 2>/dev/null || true
"$PHP_BIN" tools/ops/generate_fix_backlog_trend.php --env=staging --window=both --write-last 2>/dev/null || true
"$PHP_BIN" tools/ops/snapshot_ops_score.php --env=staging --write-last 2>/dev/null || true
"$PHP_BIN" tools/ops/generate_ops_score_trend.php --env=staging --window=both --write-last 2>/dev/null || true

# 6) RFC + change control health
"$PHP_BIN" tools/rfc/rfc_index_scan.php --write-last 2>/dev/null || true
"$PHP_BIN" tools/rfc/collect_rfc_usage.php --env=staging --run-id=last --write-last 2>/dev/null || true
"$PHP_BIN" tools/rfc/rfc_quality_lint.php --env=staging --mode=quick --write-last 2>/dev/null || true
"$PHP_BIN" tools/ops/build_change_control_health.php --env=staging 2>/dev/null || true

# 7) Release — generate manifests for zips missing them, then verify + alerts
"$PHP_BIN" tools/release/generate_manifest_from_zip.php 2>/dev/null || true
"$PHP_BIN" tools/qa/arch_audit.php --run-id=auto --scope=repo --write-last 2>/dev/null || true
"$PHP_BIN" tools/release/scan_release_exports.php --write-last 2>/dev/null || true
"$PHP_BIN" tools/release/verify_all_release_packs.php --env=staging --mode=quick --write-last 2>/dev/null || true
"$PHP_BIN" tools/ops/alert_engine.php --env=staging --write-last 2>/dev/null || true

# 8) Regenerate Executive Ops Summary Ultimate
"$PHP_BIN" tools/ops/generate_executive_summary.php --env=staging --run-id=auto --write-last 2>/dev/null || true

echo "=== Done: all=$ALL doctor=$DOCTOR ==="
exit $ALL
