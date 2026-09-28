#!/bin/sh
# Run cutover checks on Synology NAS.
# WAJIB: cd /volume4/web/ERP_RMI_SOFULL sebelum jalankan.
# Usage: ./tools/nas/run_cutover_on_nas.sh

set -e
. "$(cd "$(dirname "$0")" && pwd)/ensure_app_root.sh"
pwd

# Find PHP with pdo_mysql
PHP_BIN=""
for p in /usr/local/bin/php84 /usr/local/bin/php82 /usr/local/bin/php81 /usr/local/bin/php80 /usr/local/bin/php74 /usr/bin/php; do
    [ -x "$p" ] || continue
    if "$p" -m 2>/dev/null | grep -q pdo_mysql; then
        PHP_BIN="$p"
        break
    fi
done
if [ -z "$PHP_BIN" ]; then
    echo "FAIL: no PHP with pdo_mysql found"
    exit 1
fi
echo "Using PHP: $PHP_BIN"
"$PHP_BIN" -v

# Lock app root
"$PHP_BIN" tools/dev/lock_app_root.php || true

# Run cutover
export ERP_PHP_BIN="$PHP_BIN"
export TOOLS_BASE_URL_INTERNAL="http://10.10.60.20/ERP_RMI_SOFULL"
"$PHP_BIN" tools/qa/run_cutover_checks.php --env=staging --write-last --strict
