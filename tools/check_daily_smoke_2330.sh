#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_DIR="${ROOT_DIR}/storage/logs"
STATE_FILE="${LOG_DIR}/smoke_nightly_schedule.state"
RUN_STATE_FILE="${LOG_DIR}/smoke_nightly.state"
LOG_FILE="${LOG_DIR}/smoke_nightly.log"

MARKER_TZ="# ERP_RMI_SOFULL_NIGHTLY_SMOKE_2330_TZ"
MARKER_JOB="# ERP_RMI_SOFULL_NIGHTLY_SMOKE_2330"
LAUNCHD_LABEL="com.erp_rmi_sofull.smoke_nightly_2330"
USER_HOME="${HOME:-}"
if [[ -z "${USER_HOME}" ]]; then
  USER_HOME="$(eval echo "~$(id -un)" 2>/dev/null || true)"
fi
if [[ -z "${USER_HOME}" || "${USER_HOME}" == "~"* ]]; then
  USER_HOME="/tmp"
fi
LAUNCHD_PLIST="${USER_HOME}/Library/LaunchAgents/${LAUNCHD_LABEL}.plist"
UID_NUM="$(id -u)"

echo "=== Nightly Smoke Scheduler Status ==="
echo "Script root : ${ROOT_DIR}"
echo

cron_now="$(crontab -l 2>/dev/null || true)"
cron_present=0
if printf '%s\n' "${cron_now}" | grep -Fq "${MARKER_JOB}"; then cron_present=1; fi

launchd_present=0
if [[ -f "${LAUNCHD_PLIST}" ]]; then
  if launchctl print "gui/${UID_NUM}/${LAUNCHD_LABEL}" >/dev/null 2>&1; then
    launchd_present=1
  fi
fi

mode="none"
entry_present=0
if [[ "${cron_present}" == "1" ]]; then
  mode="cron"
  entry_present=1
elif [[ "${launchd_present}" == "1" ]]; then
  mode="launchd"
  entry_present=1
fi

echo "Cron entry  : $([[ "${cron_present}" == "1" ]] && echo PRESENT || echo MISSING)"
echo "Launchd job : $([[ "${launchd_present}" == "1" ]] && echo PRESENT || echo MISSING)"
echo "Mode active : ${mode}"

echo
echo "Schedule state:"
if [[ -f "${STATE_FILE}" ]]; then
  sed 's#'"${ROOT_DIR}"'#[APP_ROOT]#g' "${STATE_FILE}"
else
  echo "(not found)"
fi

echo
echo "Run state:"
if [[ -f "${RUN_STATE_FILE}" ]]; then
  sed 's#'"${ROOT_DIR}"'#[APP_ROOT]#g' "${RUN_STATE_FILE}"
else
  echo "(not found)"
fi

echo
echo "Last logs (tail):"
if [[ -f "${LOG_FILE}" ]]; then
  tail -n 60 "${LOG_FILE}" | sed 's#'"${ROOT_DIR}"'#[APP_ROOT]#g'
else
  echo "(log not found)"
fi

mkdir -p "${LOG_DIR}"
cat > "${STATE_FILE}" <<EOF
state_version=1
enabled=${entry_present}
timezone=Asia/Jakarta
schedule=23:30
script=${ROOT_DIR}/tools/smoke_nightly.sh
log_file=${LOG_FILE}
scheduler_mode=${mode}
entry_present=${entry_present}
checked_at=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
EOF

