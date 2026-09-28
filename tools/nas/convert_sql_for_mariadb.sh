#!/bin/bash
# ============================================================
# Konversi ERP_RMI_SOFULL.sql untuk MariaDB 10.x
#
# MySQL 8.0 memakai collation utf8mb4_0900_ai_ci yang TIDAK
# didukung MariaDB 10.2/10.3. Script ini mengganti ke
# utf8mb4_unicode_ci yang kompatibel.
#
# CARA PAKAI:
#   ./convert_sql_for_mariadb.sh
#   # Hasil: sql/ERP_RMI_SOFULL_mariadb.sql
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
SQL_DIR="$PROJECT_ROOT/sql"
SRC="$SQL_DIR/ERP_RMI_SOFULL.sql"
OUT="$SQL_DIR/ERP_RMI_SOFULL_mariadb.sql"

if [ ! -f "$SRC" ]; then
    echo "ERROR: File tidak ditemukan: $SRC"
    exit 1
fi

echo "Konversi SQL untuk MariaDB..."
sed 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' "$SRC" > "$OUT"
echo "Selesai: $OUT"
echo ""
echo "Import di phpMyAdmin: $OUT"
