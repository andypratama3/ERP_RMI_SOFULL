#!/usr/bin/env bash
set -euo pipefail

# Restore utility for ERP_RMI_SOFULL backup packages.
# Safe defaults:
# - dry-run by default (no changes)
# - requires --apply for execution
# - validates checksums before restore
#
# Usage examples:
#   tools/restore_now.sh --from storage/backups/ERP_RMI_SOFULL_backup_20260211_220000 --dry-run
#   tools/restore_now.sh --from storage/backups/ERP_RMI_SOFULL_backup_20260211_220000 --apply --restore-files --restore-db

TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${TOOLS_DIR}/.." && pwd -P)"
_RMI_ROOT="${ROOT_DIR}"
BACKUP_ROOT="${ROOT_DIR}/storage/backups"
RUNTIME_ENV_FILE="${BACKUP_ROOT}/backup_runtime.env"

FROM=""
APPLY="0"
RESTORE_DB="0"
RESTORE_FILES="0"
AUTO_SNAPSHOT="1"
I_UNDERSTAND="0"

# Optional runtime overrides (saved from web tools UI): MYSQL_BIN, ERP_DB_*, etc.
# Jangan biarkan APP_ROOT/ROOT_DIR dari .env menimpa — placeholder [APP_ROOT] merusak path.
if [[ -f "${RUNTIME_ENV_FILE}" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "${RUNTIME_ENV_FILE}" || true
  set +a
fi
ROOT_DIR="${_RMI_ROOT}"
BACKUP_ROOT="${ROOT_DIR}/storage/backups"
RUNTIME_ENV_FILE="${BACKUP_ROOT}/backup_runtime.env"

resolve_mysql_bin() {
  if [[ -n "${MYSQL_BIN:-}" && -x "${MYSQL_BIN}" ]]; then
    echo "${MYSQL_BIN}"
    return 0
  fi
  if command -v mysql >/dev/null 2>&1; then
    command -v mysql
    return 0
  fi
  local candidates=(
    "/usr/bin/mysql"
    "/usr/local/bin/mysql"
    "/usr/local/mariadb10/bin/mysql"
    "/volume1/@appstore/MariaDB10/usr/bin/mysql"
    "/volume4/@appstore/MariaDB10/usr/bin/mysql"
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

resolve_unzip_bin() {
  if [[ -n "${UNZIP_BIN:-}" && -x "${UNZIP_BIN}" ]]; then
    echo "${UNZIP_BIN}"
    return 0
  fi
  if command -v unzip >/dev/null 2>&1; then
    command -v unzip
    return 0
  fi
  local candidates=(
    "/usr/bin/unzip"
    "/bin/unzip"
    "/usr/local/bin/unzip"
    "/opt/bin/unzip"
    "/usr/syno/bin/unzip"
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

resolve_python3_cli() {
  if [[ -n "${PYTHON3_BIN:-}" && -x "${PYTHON3_BIN}" ]]; then
    echo "${PYTHON3_BIN}"
    return 0
  fi
  if command -v python3 >/dev/null 2>&1; then
    command -v python3
    return 0
  fi
  local candidates=(
    "/usr/bin/python3"
    "/bin/python3"
    "/usr/local/bin/python3"
    "/opt/bin/python3"
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

resolve_php_cli() {
  if [[ -n "${PHP_BINARY:-}" && -x "${PHP_BINARY}" ]]; then
    echo "${PHP_BINARY}"
    return 0
  fi
  if command -v php >/dev/null 2>&1; then
    command -v php
    return 0
  fi
  local candidates=(
    "/usr/local/bin/php84"
    "/usr/local/bin/php82"
    "/usr/bin/php"
    "/usr/local/bin/php"
    "/bin/php"
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

# PHP Web Station Synology biasanya punya ext-zip; /usr/bin/php sering minimal (tanpa zip).
_php_candidates_for_zip() {
  local p
  for p in \
    "${PHP_BINARY:-}" \
    "/volume1/@appstore/PHP8.4/usr/local/bin/php84" \
    "/volume1/@appstore/PHP8.3/usr/local/bin/php83" \
    "/volume1/@appstore/PHP8.2/usr/local/bin/php82" \
    "/volume1/@appstore/PHP8.1/usr/local/bin/php81" \
    "/volume1/@appstore/PHP8.0/usr/local/bin/php80" \
    "/volume4/@appstore/PHP8.4/usr/local/bin/php84" \
    "/volume4/@appstore/PHP8.2/usr/local/bin/php82" \
    "/usr/local/bin/php84" \
    "/usr/local/bin/php82" \
    "$(command -v php 2>/dev/null || true)" \
    "/usr/bin/php" \
    "/bin/php"
  do
    [[ -n "${p}" && -x "${p}" ]] && echo "${p}"
  done | awk '!seen[$0]++'
}

_extract_zip_php_ziparchive() {
  local php_exec="$1" zipf="$2" destdir="$3"
  ZIP_FILE="${zipf}" DEST="${destdir}" "${php_exec}" -d display_errors=0 -r '
if (!class_exists("ZipArchive")) { exit(1); }
$zipPath = (string)(getenv("ZIP_FILE") ?: "");
$dest = (string)(getenv("DEST") ?: "");
if ($zipPath === "" || $dest === "" || !is_file($zipPath) || !is_dir($dest)) exit(1);
$z = new ZipArchive();
if ($z->open($zipPath) !== true) exit(1);
if (!$z->extractTo($dest)) exit(1);
$z->close();
'
}

resolve_7z_bin() {
  if [[ -n "${SEVENZ_BIN:-}" && -x "${SEVENZ_BIN}" ]]; then
    echo "${SEVENZ_BIN}"
    return 0
  fi
  if command -v 7z >/dev/null 2>&1; then
    command -v 7z
    return 0
  fi
  local candidates=(
    "/usr/bin/7z"
    "/bin/7z"
    "/usr/local/bin/7z"
    "/opt/bin/7z"
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

# Free space on the filesystem hosting `path` (kilobytes, Linux df -Pk).
df_avail_kb() {
  local path="$1"
  local out
  out="$(df -Pk "${path}" 2>/dev/null | awk 'NR==2 {print $4}')"
  if [[ -z "${out}" || ! "${out}" =~ ^[0-9]+$ ]]; then
    echo "0"
    return 1
  fi
  echo "${out}"
  return 0
}

# Device id for same-fs check (Linux st_dev).
path_stat_dev() {
  local p="$1"
  if [[ -z "${p}" || ! -e "${p}" ]]; then
    echo ""
    return 1
  fi
  stat -c '%d' "${p}" 2>/dev/null || stat -f '%d' "${p}" 2>/dev/null || echo ""
}

# Uncompressed total size of zip entries in KB (best effort; fallback 2× zip file size).
zip_uncompressed_kb() {
  local zipf="$1"
  local zip_bytes="$2"
  local py u
  if py="$(resolve_python3_cli 2>/dev/null)" && [[ -n "${py}" ]]; then
    u="$("${py}" -c "import zipfile,sys; z=zipfile.ZipFile(sys.argv[1]); print(sum(i.file_size for i in z.infolist()))" "${zipf}" 2>/dev/null || true)"
    if [[ "${u}" =~ ^[0-9]+$ ]] && [[ "${u}" -gt 0 ]]; then
      echo "$(( (u + 1023) / 1024 ))"
      return 0
    fi
  fi
  echo "$(( (zip_bytes * 2 + 1023) / 1024 ))"
  return 0
}

# Abort early if extract + rsync likely exceeds free space (conservative heuristic).
restore_assert_disk_space() {
  local zipf="$1"
  local tmp_dir="$2"
  local root_dir="$3"
  local zip_bytes zip_kb unc_kb avail_tmp avail_root dev_tmp dev_root
  local need_tmp need_root need_combined buffer_kb

  zip_bytes="$(stat -c '%s' "${zipf}" 2>/dev/null || stat -f '%z' "${zipf}" 2>/dev/null || echo "0")"
  if [[ ! "${zip_bytes}" =~ ^[0-9]+$ ]] || [[ "${zip_bytes}" -le 0 ]]; then
    echo "WARNING: tidak bisa membaca ukuran files.zip; skip cek ruang disk." >&2
    return 0
  fi

  zip_kb="$(( (zip_bytes + 1023) / 1024 ))"
  unc_kb="$(zip_uncompressed_kb "${zipf}" "${zip_bytes}")"
  buffer_kb="${RESTORE_DISK_BUFFER_KB:-524288}" # default 512 MiB slack

  need_tmp="$((unc_kb + zip_kb + buffer_kb / 2))"
  need_root="$((unc_kb + buffer_kb / 2))"
  dev_tmp="$(path_stat_dev "${tmp_dir}")"
  dev_root="$(path_stat_dev "${root_dir}")"

  avail_tmp="$(df_avail_kb "${tmp_dir}" || echo "0")"
  avail_root="$(df_avail_kb "${root_dir}" || echo "0")"

  if [[ -n "${dev_tmp}" && -n "${dev_root}" && "${dev_tmp}" == "${dev_root}" ]]; then
    need_combined="$((unc_kb * 2 + zip_kb + buffer_kb))"
    if [[ "${avail_tmp}" -lt "${need_combined}" ]]; then
      echo "ERROR: ruang disk tidak cukup pada filesystem yang sama untuk ekstrak + rsync." >&2
      echo "  Perkiraan butuh ~ ${need_combined} KiB bebas di $(df -Pk "${tmp_dir}" 2>/dev/null | awk 'NR==2 {print $6}'); tersedia ~ ${avail_tmp} KiB." >&2
      echo "  Hapus file besar / kosongkan recycle bin / pindahkan backup; lalu ulangi." >&2
      echo "  Jika /tmp kecil, set RESTORE_TMP_PARENT ke folder di volume besar, contoh:" >&2
      echo "    export RESTORE_TMP_PARENT=\"${root_dir}/storage/tmp\" && mkdir -p \"\${RESTORE_TMP_PARENT}\"" >&2
      return 1
    fi
  else
    if [[ "${avail_tmp}" -lt "${need_tmp}" ]]; then
      echo "ERROR: ruang disk tidak cukup untuk ekstrak ZIP ke folder sementara." >&2
      echo "  Perkiraan butuh ~ ${need_tmp} KiB di $(df -Pk "${tmp_dir}" 2>/dev/null | awk 'NR==2 {print $6}'); tersedia ~ ${avail_tmp} KiB." >&2
      echo "  Set RESTORE_TMP_PARENT ke path di volume dengan ruang kosong." >&2
      return 1
    fi
    if [[ "${avail_root}" -lt "${need_root}" ]]; then
      echo "ERROR: ruang disk tidak cukup di folder aplikasi untuk rsync." >&2
      echo "  Perkiraan butuh ~ ${need_root} KiB di $(df -Pk "${root_dir}" 2>/dev/null | awk 'NR==2 {print $6}'); tersedia ~ ${avail_root} KiB." >&2
      return 1
    fi
  fi
  return 0
}

# Ekstrak ZIP: unzip → busybox unzip → python3 zipfile → 7z → PHP ZipArchive.
# PHP CLI di NAS sering minimal (tanpa ext-zip) dan memunculkan warning extension.
extract_zip_to_dir() {
  local zipf="$1"
  local destdir="$2"
  local uz bb py z7 php_try

  if uz="$(resolve_unzip_bin 2>/dev/null)" && [[ -n "${uz}" ]]; then
    "${uz}" -q -o "${zipf}" -d "${destdir}"
    return 0
  fi

  if command -v busybox >/dev/null 2>&1; then
    if busybox unzip -q -o "${zipf}" -d "${destdir}" 2>/dev/null; then
      return 0
    fi
  fi
  for bb in /bin/busybox /usr/bin/busybox; do
    if [[ -x "${bb}" ]] && "${bb}" unzip -q -o "${zipf}" -d "${destdir}" 2>/dev/null; then
      return 0
    fi
  done

  if py="$(resolve_python3_cli 2>/dev/null)" && [[ -n "${py}" ]]; then
    if "${py}" -c "import zipfile,sys; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])" "${zipf}" "${destdir}"; then
      return 0
    fi
  fi

  if z7="$(resolve_7z_bin 2>/dev/null)" && [[ -n "${z7}" ]]; then
    if "${z7}" x -y "-o${destdir}" "${zipf}" >/dev/null 2>&1; then
      return 0
    fi
  fi

  while IFS= read -r php_try; do
    [[ -z "${php_try}" ]] && continue
    if _extract_zip_php_ziparchive "${php_try}" "${zipf}" "${destdir}"; then
      return 0
    fi
  done < <(_php_candidates_for_zip)

  echo "ERROR: tidak ada alat ekstraksi ZIP yang berfungsi (unzip, busybox unzip, python3, 7z, PHP+zip)." >&2
  echo "HINT: Di Synology: Package Center → pasang utilitas yang menyediakan unzip, atau Python 3." >&2
  echo "HINT: Atau set UNZIP_BIN=/path/ke/unzip di storage/backups/backup_runtime.env" >&2
  return 1
}

usage() {
  cat <<'EOF'
Usage: tools/restore_now.sh --from <backup_package_dir> [options]

Options:
  --from <dir>       Backup package directory (required)
  --apply            Execute restore (default: dry-run only)
  --restore-db       Restore database from db.sql.gz
  --restore-files    Restore application files from files.zip
  --no-snapshot      Skip automatic pre-restore backup snapshot
  --dry-run          Explicit dry-run (default)
  -h, --help         Show this help

Environment (file restore):
  RESTORE_TMP_PARENT       Parent dir for extract temp. Default: <app>/storage/tmp
                           (Synology /tmp is often nearly full — avoid system tmp).
  RESTORE_USE_SYSTEM_TMP=1 Force classic mktemp (usually /tmp); not recommended on NAS.
  RMI_RSYNC_RESTORE_OPTS   Extra rsync flags (default: -a --no-owner --no-group).
  RESTORE_DISK_BUFFER_KB   Extra headroom for space check (default: 524288 = 512 MiB).
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --from)
      FROM="${2:-}"
      shift 2
      ;;
    --apply)
      APPLY="1"
      shift
      ;;
    --restore-db)
      RESTORE_DB="1"
      shift
      ;;
    --restore-files)
      RESTORE_FILES="1"
      shift
      ;;
    --no-snapshot)
      AUTO_SNAPSHOT="0"
      shift
      ;;
    --dry-run)
      APPLY="0"
      shift
      ;;
    --i-understand)
      I_UNDERSTAND="1"
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown arg: $1"
      usage
      exit 1
      ;;
  esac
done

APP_ENV_NOW="${APP_ENV:-local}"
APP_ENV_NOW="$(echo "${APP_ENV_NOW}" | tr '[:upper:]' '[:lower:]')"
if [[ "${APPLY}" == "1" && ( "${APP_ENV_NOW}" == "prod" || "${APP_ENV_NOW}" == "production" ) && "${I_UNDERSTAND}" != "1" ]]; then
  echo "ERROR: production safeguard active. Add --i-understand for apply mode."
  exit 2
fi

if [[ -z "${FROM}" ]]; then
  echo "ERROR: --from is required"
  usage
  exit 1
fi

if [[ "${RESTORE_DB}" != "1" && "${RESTORE_FILES}" != "1" ]]; then
  echo "ERROR: pilih minimal satu: --restore-db atau --restore-files"
  exit 1
fi

if [[ ! -d "${FROM}" ]]; then
  if [[ -d "${ROOT_DIR}/${FROM}" ]]; then
    FROM="${ROOT_DIR}/${FROM}"
  else
    echo "ERROR: backup package not found: ${FROM}"
    exit 1
  fi
fi

# -d bisa true tanpa hak "search" (execute) pada folder — cd baru pastikan.
FROM_CANON=""
if FROM_CANON="$(cd "${FROM}" 2>/dev/null && pwd -P)"; then
  FROM="${FROM_CANON}"
else
  echo "ERROR: tidak bisa masuk ke folder paket backup (permission denied): ${FROM}" >&2
  echo "" >&2
  echo "Penyebab umum: backup dibuat user lain (cron/root vs SSH, atau PHP http vs admin)." >&2
  echo "umask 027 pada guard NAS membuat folder sering 750 → hanya owner yang bisa cd." >&2
  echo "Perbaikan di server (contoh):" >&2
  echo "  sudo chmod 755 \"${FROM}\"" >&2
  echo "  # atau samakan owner dengan user yang menjalankan restore:" >&2
  echo "  sudo chown -R \"\$(whoami)\" \"${FROM}\"" >&2
  exit 1
fi
MANIFEST="${FROM}/manifest.json"
CHECKSUMS="${FROM}/checksums.sha256"
FILES_ZIP="${FROM}/files.zip"
DB_GZ="${FROM}/db.sql.gz"

if [[ ! -f "${MANIFEST}" || ! -f "${CHECKSUMS}" ]]; then
  echo "ERROR: manifest/checksums not found in package: ${FROM}"
  exit 1
fi

if [[ "${RESTORE_FILES}" == "1" && ! -f "${FILES_ZIP}" ]]; then
  echo "ERROR: files.zip not found in package"
  exit 1
fi

if [[ "${RESTORE_DB}" == "1" && ! -f "${DB_GZ}" ]]; then
  echo "ERROR: db.sql.gz not found in package"
  exit 1
fi

echo "Validating checksums..."
(
  cd "${FROM}"
  # shasum = macOS, sha256sum = Linux (Synology NAS). Coba keduanya.
  if command -v sha256sum &>/dev/null; then
    sha256sum -c "${CHECKSUMS}"
  elif command -v shasum &>/dev/null; then
    shasum -a 256 -c "${CHECKSUMS}"
  else
    echo "  WARNING: shasum/sha256sum not found, skipping checksum verification"
  fi
)
echo "  OK"

if [[ "${APPLY}" != "1" ]]; then
  echo
  echo "DRY-RUN MODE (no changes applied)."
  echo "Package        : ${FROM}"
  echo "Restore DB     : ${RESTORE_DB}"
  echo "Restore Files  : ${RESTORE_FILES}"
  echo
  echo "Run again with --apply to execute."
  exit 0
fi

if [[ "${AUTO_SNAPSHOT}" == "1" ]]; then
  echo "Creating pre-restore safety snapshot..."
  "${ROOT_DIR}/tools/backup_now.sh" --label "pre_restore" >/dev/null
  echo "  OK (snapshot created)"
fi

if [[ "${RESTORE_FILES}" == "1" ]]; then
  echo "Restoring files..."
  if [[ -n "${RESTORE_TMP_PARENT:-}" ]]; then
    mkdir -p "${RESTORE_TMP_PARENT}" || {
      echo "ERROR: RESTORE_TMP_PARENT tidak bisa dibuat: ${RESTORE_TMP_PARENT}" >&2
      exit 1
    }
    TMP_DIR="$(mktemp -d "${RESTORE_TMP_PARENT}/restore_now.XXXXXX")"
  elif [[ "${RESTORE_USE_SYSTEM_TMP:-}" == "1" ]]; then
    TMP_DIR="$(mktemp -d)"
  else
    # Default: same volume as app — /tmp on DSM is often ~empty (few KiB free).
    _RESTORE_TMP_DEFAULT="${ROOT_DIR}/storage/tmp"
    mkdir -p "${_RESTORE_TMP_DEFAULT}" || {
      echo "ERROR: tidak bisa membuat folder ekstrak: ${_RESTORE_TMP_DEFAULT}" >&2
      exit 1
    }
    TMP_DIR="$(mktemp -d "${_RESTORE_TMP_DEFAULT}/restore_now.XXXXXX")"
  fi

  if ! restore_assert_disk_space "${FILES_ZIP}" "${TMP_DIR}" "${ROOT_DIR}"; then
    rm -rf "${TMP_DIR}"
    exit 1
  fi

  if ! extract_zip_to_dir "${FILES_ZIP}" "${TMP_DIR}"; then
    rm -rf "${TMP_DIR}"
    exit 1
  fi

  if command -v rsync >/dev/null 2>&1; then
    # -a tanpa owner/group: non-root di NAS sering gagal chown/chgrp (Operation not permitted).
    : "${RMI_RSYNC_RESTORE_OPTS:=-a --no-owner --no-group}"
    # shellcheck disable=SC2086
    rsync ${RMI_RSYNC_RESTORE_OPTS} --delete \
      --exclude "storage/backups/" \
      --exclude ".git/" \
      "${TMP_DIR}/" "${ROOT_DIR}/"
  else
    echo "ERROR: rsync is required for file restore"
    rm -rf "${TMP_DIR}"
    exit 1
  fi
  rm -rf "${TMP_DIR}"
  echo "  OK (files restored)"
fi

if [[ "${RESTORE_DB}" == "1" ]]; then
  echo "Restoring database..."
  MYSQL_CMD="$(resolve_mysql_bin || true)"
  if [[ -z "${MYSQL_CMD}" ]]; then
    echo "ERROR: mysql client not found (set MYSQL_BIN or install mysql client)"
    exit 1
  fi

  DB_HOST="${ERP_DB_HOST:-${DB_HOST:-}}"
  DB_PORT="${ERP_DB_PORT:-${DB_PORT:-}}"
  DB_NAME="${ERP_DB_NAME:-${DB_DATABASE:-${DB_NAME:-}}}"
  DB_USER="${ERP_DB_USER:-${DB_USERNAME:-}}"
  DB_PASS="${ERP_DB_PASS:-${DB_PASSWORD:-}}"
  if [[ -z "${DB_HOST}" || -z "${DB_NAME}" || -z "${DB_USER}" ]]; then
    echo "ERROR: DB config missing (host/name/user)."
    exit 1
  fi

  if [[ -n "${DB_PASS}" ]]; then
    gunzip -c "${DB_GZ}" | DB_PWD="${DB_PASS}" "${MYSQL_CMD}" \
      --host="${DB_HOST}" --port="${DB_PORT}" --user="${DB_USER}" "${DB_NAME}"
  else
    gunzip -c "${DB_GZ}" | "${MYSQL_CMD}" \
      --host="${DB_HOST}" --port="${DB_PORT}" --user="${DB_USER}" "${DB_NAME}"
  fi
  echo "  OK (database restored)"
fi

echo
echo "Restore completed from package:"
echo "  ${FROM}"
