#!/bin/bash
# ============================================================
# ERP_RMI_SOFULL — Setup Lengkap untuk Synology NAS
# 
# CARA PAKAI:
# 1. Upload file ini ke /volume4/web/ERP_RMI_SOFULL/
# 2. SSH ke NAS: ssh admin@[IP-NAS]
# 3. cd /volume4/web/ERP_RMI_SOFULL
# 4. chmod +x setup_erp.sh
# 5. ./setup_erp.sh
# ============================================================

set -e
. "$(cd "$(dirname "$0")" && pwd)/ensure_app_root.sh"

WEB_ROOT="$APP_ROOT"
DB_USER="root"
DB_PASS="RmiHome@2025"
DB_NAME="erp_rmi_sofull"

echo "=============================================="
echo "  ERP_RMI_SOFULL — Setup"
echo "=============================================="

cd "$WEB_ROOT" || { echo "ERROR: Folder $WEB_ROOT tidak ada!"; exit 1; }

# 1. Buat config-db.php (prioritas tertinggi, bypass .env)
echo ""
echo "[1/5] Membuat config-db.php..."
cat > config-db.php << 'CFGDB'
<?php
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'erp_rmi_sofull',
    'user' => 'root',
    'pass' => 'RmiHome@2025',
];
CFGDB
chmod 600 config-db.php
echo "  ✓ config-db.php dibuat"

# 2. Buat .env (backup)
echo ""
echo "[2/5] Membuat .env..."
cat > .env << 'ENVFILE'
APP_ENV=production
APP_DEBUG=true

TOOLS_BASE_URL=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL
APP_URL=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL

ERP_DB_HOST=127.0.0.1
ERP_DB_PORT=3306
ERP_DB_NAME=erp_rmi_sofull
ERP_DB_USER=root
ERP_DB_PASS=RmiHome@2025
ENVFILE
chmod 600 .env
echo "  ✓ .env dibuat"

# 3. Permission
echo ""
echo "[3/5] Set permission..."
chmod -R 775 storage/ uploads/ exports/ 2>/dev/null || true
echo "  ✓ Permission OK"

# 4. .htaccess
echo ""
echo "[4/5] Cek .htaccess..."
if [ ! -f .htaccess ]; then
    echo 'Options -Indexes' > .htaccess
    echo "  ✓ .htaccess dibuat"
else
    echo "  ✓ .htaccess sudah ada"
fi

# 5. Verifikasi
echo ""
echo "[5/5] Verifikasi..."
echo "  - index.php: $([ -f index.php ] && echo OK || echo MISSING)"
echo "  - config.php: $([ -f config.php ] && echo OK || echo MISSING)"
echo "  - config-db.php: $([ -f config-db.php ] && echo OK || echo MISSING)"
echo "  - sql/ERP_RMI_SOFULL.sql: $([ -f sql/ERP_RMI_SOFULL.sql ] && echo OK || echo MISSING)"

echo ""
echo "=============================================="
echo "  SELESAI!"
echo "=============================================="
echo ""
echo "LANGKAH MANUAL (jika belum):"
echo "1. Buat database: CREATE DATABASE $DB_NAME;"
echo "2. Import sql/ERP_RMI_SOFULL_mariadb.sql (atau konversi dulu)"
echo "3. Buka: http://[IP-NAS]/ERP_RMI_SOFULL/"
echo "4. Login: admin / 1234"
echo ""
echo "Setelah jalan, ubah APP_DEBUG=false di .env"
echo "=============================================="
