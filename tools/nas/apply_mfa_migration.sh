#!/bin/bash
# Apply migration 072 (MFA columns) — idempotent
# Jalankan: ./tools/nas/apply_mfa_migration.sh

set -e
. "$(cd "$(dirname "$0")" && pwd)/ensure_app_root.sh"

DB_NAME="${ERP_DB_NAME:-${DB_NAME:-erp_rmi_sofull}}"
DB_USER="${ERP_DB_USER:-${DB_USER:-root}}"
DB_PASS="${ERP_DB_PASS:-${DB_PASS:-}}"
ROOT="$APP_ROOT"

echo "=== Apply MFA Migration (072) ==="
echo "Database: $DB_NAME"
echo ""

MYSQL_CMD="mysql -u $DB_USER"
[ -n "$DB_PASS" ] && MYSQL_CMD="$MYSQL_CMD -p$DB_PASS"
MYSQL_CMD="$MYSQL_CMD $DB_NAME"

# auth_login_attempts
echo -n "  auth_login_attempts ... "
$MYSQL_CMD -e "CREATE TABLE IF NOT EXISTS auth_login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_address VARCHAR(45) NOT NULL,
  username VARCHAR(120) NOT NULL,
  failed_count INT NOT NULL DEFAULT 0,
  last_attempt_at DATETIME NULL,
  locked_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auth_attempt_ip_user (ip_address, username),
  KEY idx_auth_attempt_locked (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" 2>/dev/null && echo "OK" || echo "OK (exists)"

# MFA columns (ignore error if exists)
for col in "mfa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status" \
           "mfa_secret VARCHAR(128) NULL AFTER mfa_enabled" \
           "mfa_confirmed_at DATETIME NULL AFTER mfa_secret" \
           "mfa_backup_codes_hash LONGTEXT NULL AFTER mfa_confirmed_at"; do
  name=$(echo "$col" | cut -d' ' -f1)
  echo -n "  master_system_login.$name ... "
  $MYSQL_CMD -e "ALTER TABLE master_system_login ADD COLUMN $col" 2>/dev/null && echo "OK" || echo "OK (exists)"
done

echo ""
echo "Selesai. MFA columns siap."
