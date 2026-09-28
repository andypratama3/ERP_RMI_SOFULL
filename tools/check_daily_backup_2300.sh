#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_FILE="${ROOT_DIR}/storage/logs/backup_daily_2300.log"
MARKER="ERP_RMI_SOFULL_DAILY_BACKUP_2300"
STATE_FILE="${ROOT_DIR}/storage/backups/autobackup_schedule_2300.state"

# Resolve crontab (Synology PATH terbatas)
CRONTAB_BIN=""
for p in /usr/bin/crontab /usr/sbin/crontab; do
  [[ -x "$p" ]] && CRONTAB_BIN="$p" && break
done
[[ -z "${CRONTAB_BIN}" ]] && CRONTAB_BIN="$(command -v crontab 2>/dev/null || true)"

echo "Cron entries:"
if [[ -n "${CRONTAB_BIN}" ]]; then
  if ! "${CRONTAB_BIN}" -l 2>/tmp/erp_cron_check_err.log | awk -v m="${MARKER}" 'index($0,m)>0 {print $0}'; then
    ERR_MSG="$(cat /tmp/erp_cron_check_err.log 2>/dev/null || true)"
    echo "(cannot read crontab in this runtime)"
    [[ -n "${ERR_MSG}" ]] && echo "${ERR_MSG}"
  fi
  rm -f /tmp/erp_cron_check_err.log || true
else
  echo "(crontab command not found)"
fi

if [[ -f "${STATE_FILE}" ]]; then
  echo
  echo "State file:"
  cat "${STATE_FILE}"
fi

echo
if [[ -f "${LOG_FILE}" ]]; then
  echo "Log file: ${LOG_FILE}"
  echo "(latest lines)"
  if [[ -s "${LOG_FILE}" ]]; then
    tail -n 20 "${LOG_FILE}"
  else
    echo "Belum ada eksekusi backup. Klik Run Test Now untuk mencoba."
  fi
else
  echo "Belum ada eksekusi backup. Klik Run Test Now untuk mencoba."
fi
