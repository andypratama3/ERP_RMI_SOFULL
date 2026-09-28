#!/usr/bin/env bash
set -euo pipefail

TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "${TOOLS_DIR}/nas/guard_app_root.sh"
ROOT_DIR="${APP_ROOT}"
LOG_DIR="${ROOT_DIR}/storage/logs"
mkdir -p "${LOG_DIR}"

LOG_FILE="${LOG_DIR}/smoke_nightly.log"
RUN_LOG="${LOG_DIR}/smoke_nightly_run.log"
STATE_FILE="${LOG_DIR}/smoke_nightly.state"
HISTORY_FILE="${LOG_DIR}/tools_run_history.jsonl"
LOCK_DIR="${LOG_DIR}/.smoke_nightly.lock"

# NAS defaults:
# - Set SMOKE_BASE_URL or APP_URL for smoke target. Default: http://127.0.0.1
# - Embedded php -S mode is optional and disabled unless SMOKE_USE_EMBEDDED=1.
PORT="${SMOKE_PORT:-18080}"
BASE_URL="${SMOKE_BASE_URL:-${APP_URL:-http://127.0.0.1}}"
USE_EMBEDDED="${SMOKE_USE_EMBEDDED:-0}"
SERVER_PID=""
PHP_CMD=""

ts() { date -u +"%Y-%m-%dT%H:%M:%SZ"; }

append_history_jsonl() {
  local status="$1"
  local actor="${USER:-SYSTEM}"
  printf '{"time":"%s","event":"smoke_run","status":"%s","actor_username":"%s","meta":{"source":"smoke_nightly.sh","base_url":"%s"},"state_version":1}\n' \
    "$(ts)" "${status}" "${actor}" "${BASE_URL}" >> "${HISTORY_FILE}" || true
}

resolve_php_cmd() {
  if [[ -n "${PHP_BINARY:-}" && -x "${PHP_BINARY}" ]]; then
    PHP_CMD="${PHP_BINARY}"
    return 0
  fi
  if command -v php >/dev/null 2>&1; then
    PHP_CMD="$(command -v php)"
    return 0
  fi
  for cand in /usr/local/bin/php84 /usr/local/bin/php82 /usr/bin/php /usr/local/bin/php; do
    if [[ -x "${cand}" ]]; then
      PHP_CMD="${cand}"
      return 0
    fi
  done
  return 1
}

acquire_lock() {
  if mkdir "${LOCK_DIR}" 2>/dev/null; then
    echo "$$" > "${LOCK_DIR}/pid"
    return 0
  fi
  # Auto-recover stale lock (pid no longer running).
  if [[ -f "${LOCK_DIR}/pid" ]]; then
    old_pid="$(cat "${LOCK_DIR}/pid" 2>/dev/null || true)"
    if [[ -n "${old_pid}" ]] && ! kill -0 "${old_pid}" >/dev/null 2>&1; then
      rm -rf "${LOCK_DIR}" >/dev/null 2>&1 || true
      if mkdir "${LOCK_DIR}" 2>/dev/null; then
        echo "$$" > "${LOCK_DIR}/pid"
        echo "[$(ts)] WARN stale smoke lock cleared (old_pid=${old_pid})" | tee -a "${LOG_FILE}"
        return 0
      fi
    fi
  fi
  echo "[$(ts)] FAIL smoke lock exists" | tee -a "${LOG_FILE}"
  append_history_jsonl "FAIL"
  exit 99
}

echo "[$(ts)] START nightly smoke base=${BASE_URL}" | tee -a "${LOG_FILE}"
if ! resolve_php_cmd; then
  echo "[$(ts)] FAIL php runtime not found (set PHP_BINARY)" | tee -a "${LOG_FILE}"
  cat > "${STATE_FILE}" <<EOF
state_version=1
last_run_at=$(ts)
last_status=FAIL
base_url=${BASE_URL}
reason=php_not_found
EOF
  append_history_jsonl "FAIL"
  exit 127
fi

if [[ "${USE_EMBEDDED}" == "1" ]]; then
  BASE_URL="${SMOKE_BASE_URL:-http://127.0.0.1:${PORT}}"
  "${PHP_CMD}" -S "127.0.0.1:${PORT}" -t "${ROOT_DIR}" >"${RUN_LOG}" 2>&1 &
  SERVER_PID=$!
fi

cleanup() {
  if [[ -n "${SERVER_PID}" ]] && kill -0 "${SERVER_PID}" >/dev/null 2>&1; then
    kill "${SERVER_PID}" >/dev/null 2>&1 || true
  fi
  rm -rf "${LOCK_DIR}" >/dev/null 2>&1 || true
}
trap cleanup EXIT
acquire_lock

ready=0
for _ in $(seq 1 25); do
  if curl -fs -o /dev/null "${BASE_URL}/master/login.php" 2>/dev/null; then
    ready=1
    break
  fi
  sleep 1
done

if [[ "${ready}" != "1" ]]; then
  echo "[$(ts)] FAIL server not ready" | tee -a "${LOG_FILE}"
  cat > "${STATE_FILE}" <<EOF
state_version=1
last_run_at=$(ts)
last_status=FAIL
reason=server_not_ready
EOF
  append_history_jsonl "FAIL"
  exit 1
fi

if SMOKE_BASE_URL="${BASE_URL}" "${PHP_CMD}" "${ROOT_DIR}/tools/smoke_http.php" >>"${LOG_FILE}" 2>&1; then
  echo "[$(ts)] PASS nightly smoke" | tee -a "${LOG_FILE}"
  cat > "${STATE_FILE}" <<EOF
state_version=1
last_run_at=$(ts)
last_status=PASS
base_url=${BASE_URL}
EOF
  append_history_jsonl "OK"
  exit 0
fi

echo "[$(ts)] FAIL nightly smoke" | tee -a "${LOG_FILE}"
cat > "${STATE_FILE}" <<EOF
state_version=1
last_run_at=$(ts)
last_status=FAIL
base_url=${BASE_URL}
EOF
append_history_jsonl "FAIL"
exit 1

