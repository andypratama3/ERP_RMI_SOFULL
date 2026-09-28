#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_DIR="${ROOT_DIR}/storage/logs"
LOG_FILE="${LOG_DIR}/backup_daily_2300.log"
MARKER="# ERP_RMI_SOFULL_DAILY_BACKUP_2300"
TZ_MARKER="# ERP_RMI_SOFULL_DAILY_BACKUP_2300_TZ"
STATE_FILE="${ROOT_DIR}/storage/backups/autobackup_schedule_2300.state"

mkdir -p "${LOG_DIR}"
touch "${LOG_FILE}" || true

TZ_LINE="CRON_TZ=Asia/Jakarta ${TZ_MARKER}"
CRON_LINE="0 23 * * * cd \"${ROOT_DIR}\" && /bin/bash \"${ROOT_DIR}/tools/backup_now.sh\" --label auto_2300 >> \"${LOG_FILE}\" 2>&1 ${MARKER}"

# Resolve crontab binary (Synology/web PATH sering terbatas)
CRONTAB_BIN=""
for p in /usr/bin/crontab /usr/sbin/crontab; do
  if [[ -x "$p" ]]; then
    CRONTAB_BIN="$p"
    break
  fi
done
[[ -z "${CRONTAB_BIN}" ]] && CRONTAB_BIN="$(command -v crontab 2>/dev/null || true)"
if [[ -z "${CRONTAB_BIN}" ]]; then
  echo "ERROR: crontab command not found."
  echo "HINT: Di Synology DSM, gunakan Task Scheduler (Control Panel → Task Scheduler) untuk jadwalkan backup."
  echo "HINT: Atau jalankan script ini via SSH sebagai user login: bash \"${ROOT_DIR}/tools/install_daily_backup_2300.sh\""
  exit 1
fi

CURRENT="$("${CRONTAB_BIN}" -l 2>/dev/null || true)"
CLEANED="$(printf "%s\n" "${CURRENT}" | awk -v m1="${MARKER}" -v m2="${TZ_MARKER}" 'index($0,m1)==0 && index($0,m2)==0')"

TMP_CRON="$(mktemp)"
{
  printf "%s\n" "${CLEANED}" | sed '/^[[:space:]]*$/d'
  echo "${TZ_LINE}"
  echo "${CRON_LINE}"
} > "${TMP_CRON}"

if ! "${CRONTAB_BIN}" "${TMP_CRON}" 2>/tmp/erp_cron_err.log; then
  ERR_MSG="$(cat /tmp/erp_cron_err.log 2>/dev/null || true)"
  rm -f "${TMP_CRON}" /tmp/erp_cron_err.log
  echo "ERROR: failed to install crontab."
  if [[ -n "${ERR_MSG}" ]]; then
    echo "${ERR_MSG}"
  fi
  echo "HINT: run this installer from terminal login user (not web server user)."
  echo "HINT_CMD: bash \"${ROOT_DIR}/tools/install_daily_backup_2300.sh\""
  exit 1
fi

rm -f "${TMP_CRON}" /tmp/erp_cron_err.log

{
  echo "state_version=1"
  echo "enabled=1"
  echo "cron=0 23 * * *"
  echo "timezone=Asia/Jakarta"
  echo "installed_at=$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
  echo "log_file=${LOG_FILE}"
  echo "last_action=install"
  echo "last_action_by=${USER:-unknown}"
  echo "last_action_at=$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
} > "${STATE_FILE}"

echo "Installed daily backup cron at 23:00 WIB (Asia/Jakarta)."
echo "Log file: ${LOG_FILE}"
echo "Check status: bash tools/check_daily_backup_2300.sh"
