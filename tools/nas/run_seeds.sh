#!/bin/bash
# ============================================================
# Jalankan seeds (data awal opsional)
# Prasyarat: migrations sudah dijalankan
#
# CARA PAKAI:
#   ./tools/nas/run_seeds.sh
# ============================================================

set -e
. "$(cd "$(dirname "$0")" && pwd)/ensure_app_root.sh"

DB_NAME="${ERP_DB_NAME:-erp_rmi_sofull}"
DB_USER="${ERP_DB_USER:-root}"
DB_PASS="${ERP_DB_PASS:-RmiHome@2025}"
SEEDS_DIR="$APP_ROOT/sql/seeds"

echo "=== Run Seeds ==="
echo "Database: $DB_NAME"
echo "Seeds: $SEEDS_DIR"
echo ""

count=0
for f in $(ls -1 "$SEEDS_DIR"/*.sql 2>/dev/null | sort -V); do
  [ -f "$f" ] || continue
  [[ "$(basename "$f")" == README* ]] && continue
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
echo "Selesai. Seeds dijalankan: $count"
