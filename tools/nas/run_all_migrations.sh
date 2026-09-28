#!/bin/bash
# ============================================================
# Jalankan SEMUA migrations (001-139+) untuk dapat tabel lengkap termasuk Customer Portal
#
# ERP_RMI_SOFULL.sql hanya punya 68 tabel (base).
# Migrations 073+ menambah: GL, bank, chat, CRM, KPI, mobile auth, API Partner Keys, dll.
#
# CARA PAKAI:
#   1. Import dulu: ERP_RMI_SOFULL_mariadb.sql (68 tabel)
#   2. Jalankan: ./run_all_migrations.sh
#   3. Cek: mysql -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='erp_rmi_sofull'"
# ============================================================

set -e
. "$(cd "$(dirname "$0")" && pwd)/ensure_app_root.sh"

DB_NAME="${ERP_DB_NAME:-erp_rmi_sofull}"
DB_USER="${ERP_DB_USER:-root}"
DB_PASS="${ERP_DB_PASS:-RmiHome@2025}"
MIGRATIONS_DIR="$APP_ROOT/sql/migrations"

echo "=== Run All Migrations ==="
echo "Database: $DB_NAME"
echo "Migrations: $MIGRATIONS_DIR"
echo ""

count=0
for f in $(ls -1 "$MIGRATIONS_DIR"/*.sql 2>/dev/null | sort -V); do
  [ -f "$f" ] || continue
  name=$(basename "$f")
  echo -n "  $name ... "
  if mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$f" 2>/dev/null; then
    echo "OK"
    ((count++)) || true
  else
    echo "SKIP (error atau sudah ada)"
  fi
done

echo ""
echo "Selesai. Migrations dijalankan: $count"
echo "Cek jumlah tabel: mysql -u $DB_USER -p'***' -e \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'\""
