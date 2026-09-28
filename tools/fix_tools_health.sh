#!/bin/bash
set -e
ROOT="/volume4/web/ERP_RMI_SOFULL"
cd "$ROOT" || exit 1

echo "=== 1. Storage: buat direktori & set permission ==="
mkdir -p storage/backups storage/logs storage/uploads
chmod 775 storage storage/logs storage/backups storage/uploads

if id www-data &>/dev/null; then
  chown -R www-data:www-data storage/
elif id nginx &>/dev/null; then
  chown -R nginx:nginx storage/
fi

echo "=== 2. Verifikasi storage ==="
for d in storage/logs storage/backups storage/uploads; do
  if [ -d "$d" ] && [ -w "$d" ]; then
    echo "  OK: $d (exists, writable)"
  else
    echo "  FAIL: $d"
  fi
done

echo "=== 3. Refresh Control Center ==="
php tools/ops/refresh_control_center.php 2>/dev/null || true

echo "=== Done. Cek Health Summary di Tools. ==="
