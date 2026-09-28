#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT_PATH="${ROOT_DIR}/tools/smoke_nightly.sh"
LOG_DIR="${ROOT_DIR}/storage/logs"
STATE_FILE="${LOG_DIR}/smoke_nightly_schedule.state"
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
LAUNCHD_DIR="${USER_HOME}/Library/LaunchAgents"
LAUNCHD_PLIST="${LAUNCHD_DIR}/${LAUNCHD_LABEL}.plist"
UID_NUM="$(id -u)"

mkdir -p "${LOG_DIR}"
touch "${LOG_FILE}"
chmod +x "${SCRIPT_PATH}" || true

CRON_TZ_LINE="CRON_TZ=Asia/Jakarta ${MARKER_TZ}"
CRON_JOB_LINE="30 23 * * * bash \"${SCRIPT_PATH}\" >> \"${LOG_FILE}\" 2>&1 ${MARKER_JOB}"

install_cron() {
  local existing filtered new_cron
  existing="$(crontab -l 2>/dev/null || true)"
  filtered="$(printf '%s\n' "${existing}" | awk -v m1="${MARKER_TZ}" -v m2="${MARKER_JOB}" 'index($0,m1)==0 && index($0,m2)==0')"
  new_cron="$(printf '%s\n%s\n%s\n' "${filtered}" "${CRON_TZ_LINE}" "${CRON_JOB_LINE}")"
  printf '%s\n' "${new_cron}" | crontab -
}

install_launchd() {
  mkdir -p "${LAUNCHD_DIR}"
  cat > "${LAUNCHD_PLIST}" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>${LAUNCHD_LABEL}</string>
  <key>ProgramArguments</key>
  <array>
    <string>/bin/bash</string>
    <string>${SCRIPT_PATH}</string>
  </array>
  <key>StartCalendarInterval</key>
  <dict>
    <key>Hour</key><integer>23</integer>
    <key>Minute</key><integer>30</integer>
  </dict>
  <key>StandardOutPath</key>
  <string>${LOG_FILE}</string>
  <key>StandardErrorPath</key>
  <string>${LOG_FILE}</string>
  <key>RunAtLoad</key>
  <false/>
</dict>
</plist>
EOF
  launchctl bootout "gui/${UID_NUM}/${LAUNCHD_LABEL}" >/dev/null 2>&1 || true
  launchctl bootstrap "gui/${UID_NUM}" "${LAUNCHD_PLIST}"
  launchctl enable "gui/${UID_NUM}/${LAUNCHD_LABEL}" >/dev/null 2>&1 || true
}

mode="manual"
entry_present=0
if install_cron >/dev/null 2>&1; then
  mode="cron"
  entry_present=1
else
  if install_launchd >/dev/null 2>&1; then
    mode="launchd"
    entry_present=1
  fi
fi

cat > "${STATE_FILE}" <<EOF
state_version=1
enabled=${entry_present}
timezone=Asia/Jakarta
schedule=23:30
script=${SCRIPT_PATH}
log_file=${LOG_FILE}
scheduler_mode=${mode}
entry_present=${entry_present}
installed_at=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
EOF

if [[ "${entry_present}" == "1" ]]; then
  echo "Nightly smoke scheduler installed (23:30 WIB) via ${mode}."
  exit 0
fi

echo "Nightly smoke scheduler install failed: no permission for crontab and launchd." >&2
exit 1

