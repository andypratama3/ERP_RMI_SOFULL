#!/bin/bash
# ============================================================
# Synology — Nonaktifkan Peringatan Drive "Unverified"
# Hanya untuk drive third-party (Samsung, Seagate, Toshiba, dll)
#
# PERINGATAN: Edit file system Synology. Backup dulu jika ragu.
# Jalankan via SSH: ssh admin@[IP-NAS]
# ============================================================

CONF="/etc.defaults/synoinfo.conf"

echo "Cek file $CONF..."
if [ ! -f "$CONF" ]; then
    echo "ERROR: File tidak ditemukan. Pastikan kamu di Synology DSM."
    exit 1
fi

# Cek apakah sudah no
if grep -q 'support_disk_compatibility="no"' "$CONF" 2>/dev/null; then
    echo "Sudah dinonaktifkan. Tidak perlu ubah."
    exit 0
fi

echo "Ubah support_disk_compatibility dari yes ke no..."
sudo sed -i 's/support_disk_compatibility="yes"/support_disk_compatibility="no"/' "$CONF"

if grep -q 'support_disk_compatibility="no"' "$CONF"; then
    echo "✓ Berhasil. Reboot NAS untuk apply: sudo reboot"
else
    echo "Gagal. Coba manual: sudo vi $CONF"
fi
