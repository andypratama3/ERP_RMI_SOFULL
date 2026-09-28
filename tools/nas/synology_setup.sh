#!/bin/bash
# ============================================================
# ERP_RMI_SOFULL — Setup Script untuk Synology NAS
# Jalankan via SSH: ssh admin@[IP-NAS] lalu paste/run script ini
# ============================================================

set -e
. "$(cd "$(dirname "$0")" && pwd)/ensure_app_root.sh"

# --- KONFIGURASI (sesuaikan dengan NAS kamu) ---
WEB_ROOT="$APP_ROOT"
DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME="erp_rmi_sofull"
DB_USER="root"
DB_PASS="RmiHome@2025"            # WAJIB ganti!

echo "=============================================="
echo "  ERP_RMI_SOFULL — Synology Setup"
echo "=============================================="

# 1. Cek folder ada
if [ ! -d "$WEB_ROOT" ]; then
    echo "ERROR: Folder $WEB_ROOT tidak ditemukan!"
    echo "Pastikan sudah extract deploy ke $WEB_ROOT"
    exit 1
fi

cd "$WEB_ROOT"

# 2. Buat .env
echo ""
echo "[1/4] Membuat file .env..."
cat > .env << ENVFILE
APP_ENV=production
APP_DEBUG=false

ERP_DB_HOST=$DB_HOST
ERP_DB_PORT=$DB_PORT
ERP_DB_NAME=$DB_NAME
ERP_DB_USER=$DB_USER
ERP_DB_PASS=$DB_PASS
ENVFILE
chmod 600 .env
echo "  ✓ .env dibuat"

# 3. Set permission folder writable
echo ""
echo "[2/4] Set permission storage & uploads..."
chmod -R 775 storage/ 2>/dev/null || true
chmod -R 775 uploads/ 2>/dev/null || true
chmod -R 775 exports/ 2>/dev/null || true
echo "  ✓ Permission di-set"

# 4. Buat .htaccess di root jika belum ada
echo ""
echo "[3/4] Cek .htaccess..."
if [ ! -f .htaccess ]; then
    cat > .htaccess << 'HTACCESS'
Options -Indexes
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^\.env$ - [F,L]
</IfModule>
HTACCESS
    echo "  ✓ .htaccess dibuat"
else
    echo "  ✓ .htaccess sudah ada"
fi

# 5. Cek PHP & MySQL
echo ""
echo "[4/4] Verifikasi environment..."
which php >/dev/null 2>&1 && echo "  ✓ PHP: $(php -v | head -1)" || echo "  ⚠ PHP tidak ditemukan di PATH"
which mysql >/dev/null 2>&1 && echo "  ✓ MySQL client ada" || echo "  ⚠ MySQL client tidak ditemukan"

echo ""
echo "=============================================="
echo "  SELESAI!"
echo "=============================================="
echo ""
echo "LANGKAH MANUAL yang masih harus kamu lakukan:"
echo ""
echo "1. Buat database di phpMyAdmin:"
echo "   - Buka http://[IP-NAS]/phpMyAdmin"
echo "   - Login root, buat database: $DB_NAME"
echo "   - Buat user: $DB_USER dengan password yang kamu set"
echo "   - Grant ALL on $DB_NAME.* ke $DB_USER"
echo ""
echo "2. Import SQL:"
echo "   - Pilih database $DB_NAME"
echo "   - Import file: $WEB_ROOT/sql/ERP_RMI_SOFULL.sql"
echo ""
echo "3. Edit .env jika perlu (password DB, dll):"
echo "   vi $WEB_ROOT/.env"
echo ""
echo "4. Buka di browser:"
echo "   http://[IP-NAS]/ERP_RMI_SOFULL/"
echo "   Login: admin / 1234"
echo ""
echo "=============================================="
