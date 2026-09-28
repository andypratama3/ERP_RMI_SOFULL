#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
SCRIPT="${ROOT_DIR}/tools/backup_now.sh"

if [[ ! -x "${SCRIPT}" ]]; then
  chmod +x "${SCRIPT}" || true
fi

echo "Running ERP backup..."
echo "Project: ${ROOT_DIR}"
echo

"${SCRIPT}"

echo
echo "Backup finished. Press Enter to close this window."
read -r _
