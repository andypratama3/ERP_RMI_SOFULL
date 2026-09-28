#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

PHP_BIN="${ERP_PHP_BIN:-php}"
for p in /usr/local/bin/php84 /usr/local/bin/php82 /usr/local/bin/php81 /usr/local/bin/php80; do
    [ -x "$p" ] && "$p" -m 2>/dev/null | grep -q pdo_mysql && PHP_BIN="$p" && break
done

echo "[1/4] contract check"
$PHP_BIN tools/qa/mobile_api_contract_check.php

echo "[2/4] negative auth"
$PHP_BIN tools/qa/mobile_negative_auth_test.php

echo "[3/4] master policy smoke"
$PHP_BIN tools/qa/mobile_master_policy_smoke.php

echo "[4/4] basic mobile smoke"
$PHP_BIN tools/qa/mobile_api_smoke.php

echo "PASS mobile quality gate"
