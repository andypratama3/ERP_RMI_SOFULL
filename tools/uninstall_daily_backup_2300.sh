#!/usr/bin/env bash
set -euo pipefail

MARKER="# ERP_RMI_SOFULL_DAILY_BACKUP_2300"
TZ_MARKER="# ERP_RMI_SOFULL_DAILY_BACKUP_2300_TZ"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE_FILE="${ROOT_DIR}/storage/backups/autobackup_schedule_2300.state"

# Resolve crontab (Synology PATH terbatas)
CRONTAB_BIN=""
for p in /usr/bin/crontab /usr/sbin/crontab; do
  [[ -x "$p" ]] && CRONTAB_BIN="$p" && break
done
[[ -z "${CRONTAB_BIN}" ]] && CRONTAB_BIN="$(command -v crontab 2>/dev/null || true)"
if [[ -z "${CRONTAB_BIN}" ]]; then
  echo "ERROR: crontab not found. Uninstall via Task Scheduler (Control Panel) jika pakai Synology."
  exit 1
fi

CURRENT="$("${CRONTAB_BIN}" -l 2>/dev/null || true)"
CLEANED="$(printf "%s\n" "${CURRENT}" | awk -v m1="${MARKER}" -v m2="${TZ_MARKER}" 'index($0,m1)==0 && index($0,m2)==0')"

printf "%s\n" "${CLEANED}" | "${CRONTAB_BIN}" -
{
  echo "enabled=0"
  echo "cron=0 23 * * *"
  echo "timezone=Asia/Jakarta"
  echo "disabled_at=$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
  echo "last_action=disable"
  echo "last_action_by=${USER:-unknown}"
  echo "last_action_at=$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
} > "${STATE_FILE}"

echo "Removed daily backup cron (23:00) if it existed."
