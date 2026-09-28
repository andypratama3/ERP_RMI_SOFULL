#!/usr/bin/env bash
# tools/nas/fix_pdo_and_cron.sh
# Fix: enable pdo_mysql di PHP CLI + aktifkan backup scheduler cron
# Jalankan di NAS: cd /volume4/web/ERP_RMI_SOFULL && bash tools/nas/fix_pdo_and_cron.sh
set -euo pipefail

ROOT="/volume4/web/ERP_RMI_SOFULL"
PHP_INI="/etc/php/php.ini"
CRON_CMD="0 23 * * * php ${ROOT}/tools/backup_schedule.php >> ${ROOT}/storage/logs/backup_daily_2300.log 2>&1"

echo "=== 1. Cek PHP CLI saat ini ==="
php --ini | grep "Loaded Configuration"
php -r "echo 'pdo_mysql: ' . (extension_loaded('pdo_mysql') ? 'OK' : 'MISSING') . PHP_EOL;"

echo ""
echo "=== 2. Enable pdo_mysql di ${PHP_INI} ==="
if ! grep -q "^extension=pdo_mysql" "${PHP_INI}" 2>/dev/null; then
    # Cek apakah ada baris yang di-comment
    if grep -q "^;extension=pdo_mysql\|^;extension=pdo_mysql.so" "${PHP_INI}" 2>/dev/null; then
        sudo sed -i 's/^;extension=pdo_mysql.*/extension=pdo_mysql/' "${PHP_INI}"
        echo "  OK: uncomment extension=pdo_mysql"
    else
        # Tambah setelah [PHP] section atau di akhir
        if grep -q "^extension=pdo_mysql" "${PHP_INI}"; then
            echo "  SKIP: extension=pdo_mysql sudah ada"
        else
            echo "extension=pdo_mysql" | sudo tee -a "${PHP_INI}" > /dev/null
            echo "  OK: ditambahkan extension=pdo_mysql"
        fi
    fi
else
    echo "  SKIP: extension=pdo_mysql sudah aktif"
fi

echo ""
echo "=== 3. Verifikasi pdo_mysql ==="
php -r "echo 'pdo_mysql: ' . (extension_loaded('pdo_mysql') ? 'OK ✓' : 'MASIH MISSING - cek php.ini manual') . PHP_EOL;"

echo ""
echo "=== 4. Cek backup_schedule.php ==="
if [[ -f "${ROOT}/tools/backup_schedule.php" ]]; then
    echo "  OK: backup_schedule.php ada"
else
    echo "  WARN: backup_schedule.php tidak ditemukan"
    CRON_CMD="0 23 * * * bash ${ROOT}/tools/backup_now.sh --label auto_daily >> ${ROOT}/storage/logs/backup_daily_2300.log 2>&1"
    echo "  Fallback ke: backup_now.sh"
fi

echo ""
echo "=== 5. Aktifkan cron backup scheduler ==="
# Cek apakah sudah ada
if crontab -l 2>/dev/null | grep -q "backup_schedule\|backup_now"; then
    echo "  SKIP: cron backup sudah ada:"
    crontab -l 2>/dev/null | grep "backup"
else
    # Tambah ke crontab
    (crontab -l 2>/dev/null; echo "${CRON_CMD}") | crontab -
    echo "  OK: cron backup ditambahkan: ${CRON_CMD}"
fi

echo ""
echo "=== 6. Verifikasi crontab ==="
crontab -l 2>/dev/null | grep "backup" || echo "  (tidak ada entry backup di crontab)"

echo ""
echo "=== 7. Update backup scheduler state ==="
STATE_FILE="${ROOT}/storage/backups/autobackup_schedule_2300.state"
mkdir -p "$(dirname "${STATE_FILE}")"
if [[ ! -f "${STATE_FILE}" ]] || ! grep -q "enabled=1" "${STATE_FILE}" 2>/dev/null; then
    cat >> "${STATE_FILE}" << 'STATE'
enabled=1
scheduler_type=cron
state_version=1
cron=0 23 * * *
timezone=Asia/Jakarta
scheduler_time=23:00
STATE
    echo "  OK: scheduler state diupdate"
else
    echo "  SKIP: scheduler state sudah enabled"
fi

echo ""
echo "=== 8. Test backup sekarang (opsional) ==="
echo "  Jalankan: bash ${ROOT}/tools/backup_now.sh --label test_after_fix"

echo ""
echo "=== SELESAI ==="
echo "Langkah selanjutnya:"
echo "  1. Jalankan: php tools/qa/run_all_checks.php"
echo "  2. Buka Health Summary di browser untuk verifikasi"
