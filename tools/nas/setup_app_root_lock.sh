#!/bin/sh
# Buat .app_root.lock jika belum ada (diperlukan oleh assert_app_root.sh).
# Jalankan sekali di NAS: cd /volume4/web/ERP_RMI_SOFULL && sh tools/nas/setup_app_root_lock.sh
APP_ROOT="/volume4/web/ERP_RMI_SOFULL"
LOCK_FILE="$APP_ROOT/.app_root.lock"
if [ ! -f "$LOCK_FILE" ]; then
  echo "$APP_ROOT" > "$LOCK_FILE"
  echo "Created $LOCK_FILE"
else
  echo "OK: $LOCK_FILE exists"
fi
