#!/bin/sh
# run_in_app_root.sh — Wrapper: selalu source ensure_app_root lalu jalankan command.
# Usage: ./tools/nas/run_in_app_root.sh php -v
#        ./tools/nas/run_in_app_root.sh php tools/qa/run_cutover_checks.php --write-last --strict
set -eu

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
. "${SCRIPT_DIR}/ensure_app_root.sh"

exec "$@"
