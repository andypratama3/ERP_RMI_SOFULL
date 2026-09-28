#!/usr/bin/env bash
# tools/nas/fix_storage_permissions.sh
# Fix semua storage permissions agar web server (http) bisa baca/tulis
# Jalankan: cd /volume4/web/ERP_RMI_SOFULL && sudo bash tools/nas/fix_storage_permissions.sh

set -euo pipefail
ROOT="/volume4/web/ERP_RMI_SOFULL"
WEB_USER="http"

echo "=== Fix Storage Permissions for $WEB_USER ==="
cd "$ROOT" || exit 1

# Buat semua direktori yang dibutuhkan
DIRS=(
  "storage"
  "storage/logs"
  "storage/backups"
  "storage/uploads"
  "storage/exports"
  "storage/exports/deploy"
  "storage/exports/release"
  "storage/exports/reports"
  "storage/cache"
  "storage/sessions"
  "storage/signoffs"
  "storage/state"
  "storage/locks"
  "uploads"
)

for d in "${DIRS[@]}"; do
  mkdir -p "$d"
  chown "$WEB_USER:$WEB_USER" "$d" 2>/dev/null || true
  chmod 775 "$d"
  echo "  OK: $d"
done

echo ""
echo "=== Verifikasi ==="
for d in storage/logs storage/backups storage/uploads storage/exports storage/exports/deploy; do
  if [ -w "$d" ]; then
    echo "  ✅ $d (writable)"
  else
    echo "  ❌ $d (NOT writable)"
  fi
done

echo ""
echo "=== Selesai. Coba jalankan backup atau deploy zip dari Tools. ==="
