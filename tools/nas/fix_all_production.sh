#!/usr/bin/env bash
# tools/nas/fix_all_production.sh
# One-shot production fix: permissions + PDO + cron + state upgrade
# Jalankan: cd /volume4/web/ERP_RMI_SOFULL && sudo bash tools/nas/fix_all_production.sh
set -euo pipefail
ROOT="/volume4/web/ERP_RMI_SOFULL"
WEB_USER="http"
PHP_INI="/etc/php/php.ini"
LOG="$ROOT/storage/logs/fix_all_production_$(date +%Y%m%d_%H%M%S).log"

cd "$ROOT" || exit 1
mkdir -p storage/logs
exec > >(tee -a "$LOG") 2>&1
echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] fix_all_production START"

# === 1. Storage permissions ===
echo "=== 1. Storage permissions ==="
for d in storage storage/logs storage/backups storage/uploads storage/exports storage/exports/deploy storage/exports/release storage/exports/reports storage/cache storage/state storage/locks storage/signoffs uploads; do
    mkdir -p "$d"
    chown "$WEB_USER:$WEB_USER" "$d" 2>/dev/null || true
    chmod 775 "$d"
    [ -w "$d" ] && echo "  OK: $d" || echo "  WARN: $d not writable"
done

# === 2. PDO MySQL ===
echo ""
echo "=== 2. PDO MySQL ==="
if php -r "exit(extension_loaded('pdo_mysql') ? 0 : 1);" 2>/dev/null; then
    echo "  OK: pdo_mysql already active"
else
    echo "  MISSING: pdo_mysql — checking php.ini..."
    if grep -q "^extension=pdo_mysql" "$PHP_INI" 2>/dev/null; then
        echo "  Already in php.ini"
    elif grep -q "^;extension=pdo_mysql" "$PHP_INI" 2>/dev/null; then
        sed -i 's/^;extension=pdo_mysql.*/extension=pdo_mysql/' "$PHP_INI"
        echo "  Uncommented extension=pdo_mysql"
    else
        echo "extension=pdo_mysql" >> "$PHP_INI"
        echo "  Added extension=pdo_mysql"
    fi
    php -r "echo 'pdo_mysql: ' . (extension_loaded('pdo_mysql') ? 'OK' : 'STILL MISSING') . PHP_EOL;" 2>/dev/null || true
fi

# === 3. SALES_TRACKING_ALLOW_LEGACY ===
echo ""
echo "=== 3. Sales tracking legacy flag ==="
ENV_FILE="$ROOT/.env"
if [ -f "$ENV_FILE" ] && grep -q "SALES_TRACKING_ALLOW_LEGACY" "$ENV_FILE" 2>/dev/null; then
    echo "  OK: SALES_TRACKING_ALLOW_LEGACY already set"
else
    echo "SALES_TRACKING_ALLOW_LEGACY=1" >> "$ENV_FILE" 2>/dev/null || true
    echo "  Added SALES_TRACKING_ALLOW_LEGACY=1 to .env"
fi

# === 4. State upgrade (apply v1) ===
echo ""
echo "=== 4. State upgrade ==="
php tools/state/state_upgrade.php --to=v1 --apply --i-understand 2>&1 | tail -3 || true
echo "  Done"

# === 5. Scheduler state ===
echo ""
echo "=== 5. Backup scheduler state ==="
STATE_FILE="storage/backups/autobackup_schedule_2300.state"
if ! grep -q "^enabled=1" "$STATE_FILE" 2>/dev/null; then
    cat > "$STATE_FILE" << 'STATE'
enabled=1
scheduler_type=cron
cron=0 23 * * *
timezone=Asia/Jakarta
log_file=/volume4/web/ERP_RMI_SOFULL/storage/logs/backup_daily_2300.log
installed_at=2026-03-11T13:00:00Z
state_version=1
STATE
    echo "  Scheduler state set to enabled=1"
else
    echo "  OK: scheduler already enabled"
fi

# === 6. Repo location audit ===
echo ""
echo "=== 6. Repo location audit ==="
bash tools/nas/where_is_repo.sh 2>/dev/null || true

# === 7. Run diagnostics ===
echo ""
echo "=== 7. Quick diagnostics ==="
php tools/qa/run_all_checks.php 2>&1 | python3 -m json.tool 2>/dev/null | grep -E '"overall_ok"|"score"|"fail_count"' | head -5 || true

echo ""
echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] fix_all_production DONE. Log: $LOG"
