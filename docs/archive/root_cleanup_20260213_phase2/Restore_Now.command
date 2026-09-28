#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
SCRIPT="${ROOT_DIR}/tools/restore_now.sh"
BACKUP_ROOT="${ROOT_DIR}/storage/backups"

if [[ ! -x "${SCRIPT}" ]]; then
  chmod +x "${SCRIPT}" || true
fi

echo "ERP Restore Assistant"
echo "Project: ${ROOT_DIR}"
echo

LATEST_FILE="${BACKUP_ROOT}/LATEST_BACKUP.txt"
PICKED=""

if [[ -f "${LATEST_FILE}" ]]; then
  PICKED="$(cat "${LATEST_FILE}" || true)"
fi

if [[ -z "${PICKED}" || ! -d "${PICKED}" ]]; then
  echo "Latest backup pointer not found. Available packages:"
  ls -1dt "${BACKUP_ROOT}"/ERP_RMI_SOFULL_backup_* 2>/dev/null || true
  echo
  read -r -p "Paste backup package path: " PICKED
fi

if [[ -z "${PICKED}" || ! -d "${PICKED}" ]]; then
  echo "Invalid backup package."
  echo "Press Enter to close."
  read -r _
  exit 1
fi

echo "Selected package:"
echo "  ${PICKED}"
echo
echo "Choose restore mode:"
echo "  1) DB only"
echo "  2) Files only"
echo "  3) DB + Files"
read -r -p "Pick [1/2/3]: " MODE

RESTORE_ARGS=()
case "${MODE}" in
  1) RESTORE_ARGS+=(--restore-db) ;;
  2) RESTORE_ARGS+=(--restore-files) ;;
  3) RESTORE_ARGS+=(--restore-db --restore-files) ;;
  *)
    echo "Invalid mode."
    echo "Press Enter to close."
    read -r _
    exit 1
    ;;
esac

echo
echo "Dry-run preview:"
"${SCRIPT}" --from "${PICKED}" "${RESTORE_ARGS[@]}" --dry-run
echo
read -r -p "Apply restore now? (type YES): " CONF
if [[ "${CONF}" != "YES" ]]; then
  echo "Cancelled."
  echo "Press Enter to close."
  read -r _
  exit 0
fi

echo
echo "Applying restore..."
"${SCRIPT}" --from "${PICKED}" "${RESTORE_ARGS[@]}" --apply

echo
echo "Restore finished. Press Enter to close."
read -r _
