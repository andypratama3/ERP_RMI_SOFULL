#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
SCRIPT="${ROOT_DIR}/tools/uninstall_daily_backup_2300.sh"

chmod +x "${SCRIPT}" || true

echo "Disabling ERP daily autobackup at 23:00..."
bash "${SCRIPT}"
echo
echo "Done. Press Enter to close."
read -r _
