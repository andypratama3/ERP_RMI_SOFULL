#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
SCRIPT="${ROOT_DIR}/tools/install_daily_backup_2300.sh"
CHECK="${ROOT_DIR}/tools/check_daily_backup_2300.sh"

chmod +x "${SCRIPT}" "${CHECK}" || true

echo "Installing ERP daily autobackup at 23:00..."
bash "${SCRIPT}"
echo
bash "${CHECK}" || true
echo
echo "Done. Press Enter to close."
read -r _
