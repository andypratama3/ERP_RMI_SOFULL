#!/bin/bash
# ============================================================
# Jalankan migration essential (WQS Incoming + Jobs Monitor)
# Untuk fix error: po_id/po_item_id belum tersedia, jobs table doesn't exist
#
# CARA PAKAI:
#   cd /volume4/web/ERP_RMI_SOFULL
#   chmod +x tools/nas/run_essential_migrations.sh
#   ./tools/nas/run_essential_migrations.sh
# ============================================================

set -e
. "$(cd "$(dirname "$0")" && pwd)/ensure_app_root.sh"

DB_NAME="${ERP_DB_NAME:-erp_rmi_sofull}"
DB_USER="${ERP_DB_USER:-root}"
DB_PASS="${ERP_DB_PASS:-RmiHome@2025}"
ROOT="$APP_ROOT"

echo "=== Run Essential Migrations ==="
echo "Database: $DB_NAME"
echo ""

for f in 102_wqs_incoming_po_columns.sql 103_jobs_table.sql; do
  path="$ROOT/sql/migrations/$f"
  if [ -f "$path" ]; then
    echo -n "  $f ... "
    if mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$path" 2>/dev/null; then
      echo "OK"
    else
      echo "SKIP (error atau sudah ada)"
    fi
  else
    echo "  $f ... NOT FOUND"
  fi
done

echo ""
echo "Selesai. Refresh browser untuk cek WQS Incoming & Jobs Monitor."
