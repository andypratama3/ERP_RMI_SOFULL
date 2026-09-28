#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_DIR="${ROOT_DIR}/storage/logs"
STATE_FILE="${LOG_DIR}/smoke_nightly_schedule.state"

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

existing="$(crontab -l 2>/dev/null || true)"
filtered="$(printf '%s\n' "${existing}" | awk -v m1="${MARKER_TZ}" -v m2="${MARKER_JOB}" 'index($0,m1)==0 && index($0,m2)==0')"

if [[ -n "${filtered}" ]]; then
  printf '%s\n' "${filtered}" | crontab -
else
  crontab -r 2>/dev/null || true
fi

launchctl bootout "gui/${UID_NUM}/${LAUNCHD_LABEL}" >/dev/null 2>&1 || true
launchctl disable "gui/${UID_NUM}/${LAUNCHD_LABEL}" >/dev/null 2>&1 || true
rm -f "${LAUNCHD_PLIST}" >/dev/null 2>&1 || true

mkdir -p "${LOG_DIR}"
cat > "${STATE_FILE}" <<EOF
enabled=0
timezone=Asia/Jakarta
schedule=23:30
scheduler_mode=none
entry_present=0
uninstalled_at=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
EOF

echo "Nightly smoke scheduler uninstalled."

