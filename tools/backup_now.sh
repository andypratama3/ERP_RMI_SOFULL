#!/usr/bin/env bash
set -euo pipefail

# One-click backup for ERP_RMI_SOFULL.
TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "${TOOLS_DIR}/nas/guard_app_root.sh"
# Real app root from guard only — never trust values from backup_runtime.env for paths.
_RMI_ROOT="${APP_ROOT}"
ROOT_DIR="${_RMI_ROOT}"
# Package output:
#   storage/backups/ERP_RMI_SOFULL_backup_<STAMP>[_label]/
#     - files.zip
#     - db.sql.gz (optional)
#     - manifest.json
#     - checksums.sha256

BACKUP_ROOT="${ROOT_DIR}/storage/backups"
RUNTIME_ENV_FILE="${BACKUP_ROOT}/backup_runtime.env"
STATE_FILE="${BACKUP_ROOT}/autobackup_schedule_2300.state"
LOG_FILE="${ROOT_DIR}/storage/logs/backup_daily_2300.log"
HISTORY_FILE="${ROOT_DIR}/storage/logs/tools_run_history.jsonl"
LOCK_DIR="${ROOT_DIR}/storage/logs/.backup_now.lock"
STAMP="$(date +%Y%m%d_%H%M%S)"
APP_NAME="ERP_RMI_SOFULL"

LABEL=""
SKIP_DB="0"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-0}"

# Optional runtime overrides (saved from web tools UI): MYSQLDUMP_BIN, ERP_DB_*, etc.
# Must NOT override LOG_FILE / APP_ROOT / ROOT_DIR — dokumentasi memakai placeholder [APP_ROOT]
# yang kalau ter-copy ke .env akan membuat touch gagal dan path tidak valid.
if [[ -f "${RUNTIME_ENV_FILE}" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "${RUNTIME_ENV_FILE}" || true
  set +a
fi

export APP_ROOT="${_RMI_ROOT}"
ROOT_DIR="${_RMI_ROOT}"
BACKUP_ROOT="${ROOT_DIR}/storage/backups"
RUNTIME_ENV_FILE="${BACKUP_ROOT}/backup_runtime.env"
STATE_FILE="${BACKUP_ROOT}/autobackup_schedule_2300.state"
LOG_FILE="${ROOT_DIR}/storage/logs/backup_daily_2300.log"
HISTORY_FILE="${ROOT_DIR}/storage/logs/tools_run_history.jsonl"
LOCK_DIR="${ROOT_DIR}/storage/logs/.backup_now.lock"

# Synology DSM: /tmp sering hampir 0 byte bebas. Bash memakai TMPDIR untuk here-document
# (mis. read <<EOF) dan mktemp — tanpa ini backup gagal di tengah skrip.
_BACKUP_TMP="${ROOT_DIR}/storage/tmp"
mkdir -p "${_BACKUP_TMP}" || true
if [[ -d "${_BACKUP_TMP}" && -w "${_BACKUP_TMP}" ]]; then
  export TMPDIR="${TMPDIR:-${_BACKUP_TMP}}"
fi

mkdir -p "$(dirname "${LOG_FILE}")"
touch "${LOG_FILE}" || true

acquire_lock() {
  if mkdir "${LOCK_DIR}" 2>/dev/null; then
    echo "$$" > "${LOCK_DIR}/pid"
    return 0
  fi
  # Stale lock: proses pid tidak hidup lagi.
  if [[ -f "${LOCK_DIR}/pid" ]]; then
    old_pid="$(cat "${LOCK_DIR}/pid" 2>/dev/null || true)"
    if [[ -n "${old_pid}" ]] && ! kill -0 "${old_pid}" >/dev/null 2>&1; then
      rm -rf "${LOCK_DIR}" >/dev/null 2>&1 || true
      if mkdir "${LOCK_DIR}" 2>/dev/null; then
        echo "$$" > "${LOCK_DIR}/pid"
        log_line "BACKUP_LOCK stale cleared old_pid=${old_pid}"
        return 0
      fi
    fi
  fi
  # Stale lock: tidak ada pid, folder lock sangat lama (mis. kill -9 / crash).
  if [[ -d "${LOCK_DIR}" ]] && [[ ! -f "${LOCK_DIR}/pid" ]]; then
    local mt age
    mt="$(stat -c%Y "${LOCK_DIR}" 2>/dev/null || stat -f%m "${LOCK_DIR}" 2>/dev/null || true)"
    if [[ -n "${mt}" ]] && [[ "${mt}" =~ ^[0-9]+$ ]]; then
      age="$(($(date +%s) - mt))"
      if [[ "${age}" -gt 7200 ]]; then
        rm -rf "${LOCK_DIR}" >/dev/null 2>&1 || true
        if mkdir "${LOCK_DIR}" 2>/dev/null; then
          echo "$$" > "${LOCK_DIR}/pid"
          log_line "BACKUP_LOCK stale dir cleared age_sec=${age}"
          return 0
        fi
      fi
    fi
  fi
  log_line "BACKUP_DONE status=failed reason=lock_exists"
  echo "Backup already running (lock exists)." >&2
  exit 99
}

release_lock() {
  rm -rf "${LOCK_DIR}" >/dev/null 2>&1 || true
}

append_history_jsonl() {
  local status="$1"
  local pkg="$2"
  local actor="${USER:-SYSTEM}"
  printf '{"time":"%s","event":"backup_run","status":"%s","actor_username":"%s","meta":{"source":"backup_now.sh","package":"%s"},"state_version":1}\n' \
    "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "${status}" "${actor}" "${pkg}" >> "${HISTORY_FILE}" || true
}

log_line() {
  local msg="$1"
  echo "[$(date -u +"%Y-%m-%dT%H:%M:%SZ")] ${msg}"
}

mark_state_last_run() {
  local status="$1"
  local package="$2"
  local dbStatus="$3"
  if [[ -f "${STATE_FILE}" ]]; then
    # Remove old last_* keys then append fresh values.
    local tmp
    tmp="$(mktemp -p "${ROOT_DIR}/storage/tmp" "backup_state.XXXXXX" 2>/dev/null || mktemp)"
    awk -F= 'BEGIN{OFS="="} $1!="last_run_at" && $1!="last_status" && $1!="last_package" && $1!="last_db_status"{print $0}' "${STATE_FILE}" > "${tmp}" || true
    mv "${tmp}" "${STATE_FILE}"
  fi
  {
    echo "last_run_at=$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    echo "last_status=${status}"
    echo "last_package=${package}"
    echo "last_db_status=${dbStatus}"
  } >> "${STATE_FILE}"
}

on_error() {
  local ec=$?
  log_line "BACKUP_DONE status=failed exit_code=${ec}"
  append_history_jsonl "FAIL" ""
  mark_state_last_run "failed" "" "failed"
  release_lock
  exit "${ec}"
}
trap on_error ERR
trap release_lock EXIT

acquire_lock

while [[ $# -gt 0 ]]; do
  case "$1" in
    --label)
      LABEL="${2:-}"
      shift 2
      ;;
    --skip-db)
      SKIP_DB="1"
      shift
      ;;
    --retention-days)
      RETENTION_DAYS="${2:-0}"
      shift 2
      ;;
    *)
      echo "Unknown arg: $1"
      echo "Usage: tools/backup_now.sh [--label <name>] [--skip-db]"
      exit 1
      ;;
  esac
done

if [[ -n "${LABEL}" ]]; then
  LABEL="$(echo "${LABEL}" | tr '[:space:]' '_' | tr -cd '[:alnum:]_.-')"
  LABEL="${LABEL##_}"
  LABEL="${LABEL%%_}"
  LABEL="${LABEL##[-.]}"
  LABEL="${LABEL%%[-.]}"
fi

if ! [[ "${RETENTION_DAYS}" =~ ^[0-9]+$ ]]; then
  RETENTION_DAYS="0"
fi

PACKAGE_NAME="${APP_NAME}_backup_${STAMP}"
if [[ -n "${LABEL}" ]]; then
  PACKAGE_NAME="${PACKAGE_NAME}_${LABEL}"
fi
PACKAGE_DIR="${BACKUP_ROOT}/${PACKAGE_NAME}"

mkdir -p "${PACKAGE_DIR}"

sha256_file() {
  local f="$1"
  # Prioritas: openssl (umum di Synology/Linux), sha256sum, shasum (macOS). shasum sering tidak ada di cron PATH.
  if [[ -x /usr/bin/openssl ]]; then
    /usr/bin/openssl dgst -sha256 -r "$f" 2>/dev/null | awk '{print $1}'
  elif command -v openssl >/dev/null 2>&1; then
    openssl dgst -sha256 -r "$f" 2>/dev/null | awk '{print $1}'
  elif command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$f" 2>/dev/null | awk '{print $1}'
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$f" 2>/dev/null | awk '{print $1}'
  else
    echo "FATAL: sha256sum, shasum, or openssl not found" >&2
    exit 127
  fi
}

resolve_mysqldump_bin() {
  if [[ -n "${MYSQLDUMP_BIN:-}" && -x "${MYSQLDUMP_BIN}" ]]; then
    echo "${MYSQLDUMP_BIN}"
    return 0
  fi
  if command -v mysqldump >/dev/null 2>&1; then
    command -v mysqldump
    return 0
  fi
  local candidates=(
    "/usr/bin/mysqldump"
    "/usr/local/bin/mysqldump"
    "/usr/local/mariadb10/bin/mysqldump"
    "/volume1/@appstore/MariaDB10/usr/bin/mysqldump"
    "/volume4/@appstore/MariaDB10/usr/bin/mysqldump"
  )
  local p
  for p in "${candidates[@]}"; do
    if [[ -x "${p}" ]]; then
      echo "${p}"
      return 0
    fi
  done
  return 1
}

echo "[1/3] Creating files backup zip..."
log_line "BACKUP_START label=${LABEL:-none}"
FILES_ZIP="${PACKAGE_DIR}/files.zip"
set +e
trap - ERR
( cd "${ROOT_DIR}"
  zip -rq "${FILES_ZIP}" . \
    -x "storage/backups/*" "storage/sessions/*" "storage/cache/*" \
    -x ".git/*" "node_modules/*" ".venv_docs/*" ".cache/*" ".DS_Store" ".env" "*.env" \
    -x "*@eaDir*" "*.Symlink" "*.SYNOPHOTO*" "*.SYNOINDEX*" \
    -x "*/_backup/*" "*/.git/*"
)
zip_ec=$?
# zip exit 0=ok, 1=warnings, 2=some errors non-fatal, 18=permission denied on some files (non-fatal)
if [[ $zip_ec -eq 0 || $zip_ec -eq 1 || $zip_ec -eq 2 || $zip_ec -eq 18 ]]; then
  echo "  zip completed (exit=${zip_ec}, warnings only — continuing)"
else
  echo "zip failed with exit code ${zip_ec}" >&2
  trap on_error ERR
  set -e
  exit $zip_ec
fi
trap on_error ERR
set -e
if [[ ! -f "${FILES_ZIP}" ]]; then
  echo "zip did not produce output file" >&2
  exit 1
fi
FILES_SHA="$(sha256_file "${FILES_ZIP}")"
echo "  OK: ${FILES_ZIP}"

echo "[2/3] Creating database backup (optional)..."
DB_GZ=""
DB_SHA=""
DB_STATUS="skipped"
DB_REASON=""

DB_HOST="${ERP_DB_HOST:-${DB_HOST:-}}"
DB_PORT="${ERP_DB_PORT:-${DB_PORT:-}}"
DB_NAME="${ERP_DB_NAME:-${DB_DATABASE:-${DB_NAME:-}}}"
DB_USER="${ERP_DB_USER:-${DB_USERNAME:-}}"
DB_PASS="${ERP_DB_PASS:-${DB_PASSWORD:-}}"

# Fallback from project DB config (helps cron jobs with empty env).
if command -v php >/dev/null 2>&1 && [[ -f "${ROOT_DIR}/_shared/db.php" ]]; then
  DB_CFG_RAW="$(
    php -r '
      require $argv[1];
      if (function_exists("rmi_db_config")) {
        $c = rmi_db_config();
        echo (string)($c["host"] ?? ""), PHP_EOL;
        echo (string)($c["port"] ?? ""), PHP_EOL;
        echo (string)($c["name"] ?? ""), PHP_EOL;
        echo (string)($c["user"] ?? ""), PHP_EOL;
        echo (string)($c["pass"] ?? ""), PHP_EOL;
      }
    ' "${ROOT_DIR}/_shared/db.php" 2>/dev/null || true
  )"
  IFS='
' read -r CFG_HOST CFG_PORT CFG_NAME CFG_USER CFG_PASS <<EOF
${DB_CFG_RAW}
EOF
  [[ -z "${DB_HOST}" && -n "${CFG_HOST:-}" ]] && DB_HOST="${CFG_HOST}"
  [[ -z "${DB_PORT}" && -n "${CFG_PORT:-}" ]] && DB_PORT="${CFG_PORT}"
  [[ -z "${DB_NAME}" && -n "${CFG_NAME:-}" ]] && DB_NAME="${CFG_NAME}"
  [[ -z "${DB_USER}" && -n "${CFG_USER:-}" ]] && DB_USER="${CFG_USER}"
  [[ -z "${DB_PASS}" && -n "${CFG_PASS:-}" ]] && DB_PASS="${CFG_PASS}"
fi

if [[ "${SKIP_DB}" == "1" ]]; then
  DB_REASON="skip requested (--skip-db)"
else
  MYSQLDUMP_CMD="$(resolve_mysqldump_bin || true)"
  if [[ -z "${MYSQLDUMP_CMD}" ]]; then
    DB_REASON="mysqldump not installed (set MYSQLDUMP_BIN or install mysql client)"
  elif [[ -z "${DB_HOST}" || -z "${DB_NAME}" || -z "${DB_USER}" ]]; then
    DB_REASON="db config missing (host/name/user)"
  else
  DB_SQL="${PACKAGE_DIR}/db.sql"
  DB_GZ="${PACKAGE_DIR}/db.sql.gz"
  DB_ERR="${PACKAGE_DIR}/db_dump.err.log"
  if [[ -n "${DB_PASS}" ]]; then
    MYSQL_PWD="${DB_PASS}" "${MYSQLDUMP_CMD}" \
      --host="${DB_HOST}" --port="${DB_PORT}" --user="${DB_USER}" \
      --single-transaction --routines --triggers --events \
      "${DB_NAME}" > "${DB_SQL}" 2>"${DB_ERR}" || true
  else
    "${MYSQLDUMP_CMD}" \
      --host="${DB_HOST}" --port="${DB_PORT}" --user="${DB_USER}" \
      --single-transaction --routines --triggers --events \
      "${DB_NAME}" > "${DB_SQL}" 2>"${DB_ERR}" || true
  fi

  if [[ -s "${DB_SQL}" ]]; then
    gzip -f "${DB_SQL}"
    DB_SHA="$(sha256_file "${DB_GZ}")"
    DB_STATUS="ok"
    DB_REASON=""
    rm -f "${DB_ERR}" || true
    echo "  OK: ${DB_GZ}"
  else
    rm -f "${DB_SQL}" "${DB_GZ}"
    DB_GZ=""
    if [[ -s "${DB_ERR}" ]]; then
      DB_ERR_MSG="$(head -n 1 "${DB_ERR}" | tr -d '\r' || true)"
      DB_REASON="dump failed: ${DB_ERR_MSG}"
    else
      DB_REASON="dump failed (credential/env not ready)"
    fi
    rm -f "${DB_ERR}" || true
  fi
  fi
fi

if [[ "${DB_STATUS}" != "ok" ]]; then
  echo "  SKIP: ${DB_REASON}"
fi

echo "[3/3] Writing manifest + checksums..."
CHECKSUMS_FILE="${PACKAGE_DIR}/checksums.sha256"
{
  echo "${FILES_SHA}  files.zip"
  if [[ -n "${DB_GZ}" && -n "${DB_SHA}" ]]; then
    echo "${DB_SHA}  db.sql.gz"
  fi
} > "${CHECKSUMS_FILE}"

MANIFEST_FILE="${PACKAGE_DIR}/manifest.json"
cat > "${MANIFEST_FILE}" <<EOF
{
  "app": "${APP_NAME}",
  "created_at": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
  "package": "${PACKAGE_NAME}",
  "root_dir": "[APP_ROOT]",
  "files_zip": "files.zip",
  "files_sha256": "${FILES_SHA}",
  "db_dump": "$( [[ -n "${DB_GZ}" ]] && echo "db.sql.gz" || echo "" )",
  "db_sha256": "${DB_SHA}",
  "db_status": "${DB_STATUS}",
  "db_reason": "${DB_REASON}",
  "db_config": {
    "host": "${DB_HOST:+[REDACTED]}",
    "port": "${DB_PORT}",
    "name": "${DB_NAME:+[REDACTED]}",
    "user": "${DB_USER:+[REDACTED]}"
  }
}
EOF

echo "${PACKAGE_DIR}" > "${BACKUP_ROOT}/LATEST_BACKUP.txt" 2>/dev/null || \
  echo "${PACKAGE_DIR}" > "${PACKAGE_DIR}/../LATEST_BACKUP.txt" 2>/dev/null || true

echo
echo "Backup completed:"
echo "  Package : ${PACKAGE_DIR}"
echo "  Manifest: ${MANIFEST_FILE}"
echo "  Checksum: ${CHECKSUMS_FILE}"
log_line "BACKUP_DONE status=ok package=${PACKAGE_DIR} db_status=${DB_STATUS}"
append_history_jsonl "OK" "${PACKAGE_DIR}"
mark_state_last_run "ok" "${PACKAGE_DIR}" "${DB_STATUS}"

if [[ "${RETENTION_DAYS}" -gt 0 ]]; then
  PRUNED_COUNT=0
  while IFS= read -r d; do
    [[ -z "${d}" ]] && continue
    if [[ "${d}" == "${PACKAGE_DIR}" ]]; then
      continue
    fi
    rm -rf "${d}" || true
    PRUNED_COUNT=$((PRUNED_COUNT + 1))
  done < <(find "${BACKUP_ROOT}" -maxdepth 1 -type d -name "${APP_NAME}_backup_*" -mtime "+${RETENTION_DAYS}" 2>/dev/null || true)
  if [[ "${PRUNED_COUNT}" -gt 0 ]]; then
    log_line "BACKUP_RETENTION pruned=${PRUNED_COUNT} days=${RETENTION_DAYS}"
    echo "Retention cleanup: removed ${PRUNED_COUNT} backup package(s) older than ${RETENTION_DAYS} day(s)."
  fi
fi
