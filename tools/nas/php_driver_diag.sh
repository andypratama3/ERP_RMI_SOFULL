#!/usr/bin/env bash
# tools/nas/php_driver_diag.sh — Diagnose PHP DB driver availability
# Usage: bash tools/nas/php_driver_diag.sh
ROOT="/volume4/web/ERP_RMI_SOFULL"
OUT="$ROOT/storage/logs/php_driver_check_last.txt"
mkdir -p "$ROOT/storage/logs"

echo "=== PHP DB Driver Diagnostic ===" | tee "$OUT"
echo "Date: $(date)" | tee -a "$OUT"
echo "" | tee -a "$OUT"

PHP_BIN="${ERP_PHP_BIN:-php}"

echo "PHP binary: $(which $PHP_BIN) — version: $($PHP_BIN --version 2>/dev/null | head -1)" | tee -a "$OUT"
echo "php.ini: $($PHP_BIN --ini 2>/dev/null | grep 'Loaded Configuration' | cut -d: -f2 | xargs)" | tee -a "$OUT"
echo "" | tee -a "$OUT"

for ext in pdo pdo_mysql mysqli; do
    status=$($PHP_BIN -r "echo extension_loaded('$ext') ? 'LOADED' : 'MISSING';" 2>/dev/null || echo "ERROR")
    echo "$ext: $status" | tee -a "$OUT"
done

echo "" | tee -a "$OUT"
echo "extension_dir: $($PHP_BIN -i 2>/dev/null | grep extension_dir | head -1)" | tee -a "$OUT"

# Find pdo_mysql.so
echo "" | tee -a "$OUT"
echo "Looking for pdo_mysql.so..." | tee -a "$OUT"
find /usr /var/packages -name "pdo_mysql*" 2>/dev/null | head -5 | tee -a "$OUT" || true

echo "" | tee -a "$OUT"
echo "Diagnosis saved to: $OUT"
