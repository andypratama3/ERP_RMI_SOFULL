# Finance Schema Guard

## Purpose
Verifies that the `gl_reversal_requests` table exists (required by `082_gl_dual_control_reversal.sql`).

## Driver Fallback Strategy
1. **PDO MySQL** (preferred): uses `rmi_db_pdo()` or `db_pdo()`
2. **mysqli** (fallback if PDO missing): uses `rmi_db_mysqli()` or direct connection via `rmi_db_config()`
3. **CRITICAL FAIL** only if neither driver available

## Fix PDO MySQL on Synology NAS
```bash
# Diagnose
bash tools/nas/php_driver_diag.sh

# Fix (edit /etc/php/php.ini)
echo "extension=pdo_mysql" | sudo tee -a /etc/php/php.ini
php -r "echo extension_loaded('pdo_mysql') ? 'OK' : 'FAIL';"
```

## Evidence
- `storage/logs/finance_schema_guard_last.json`
- Artifact: `sql/migrations/082_gl_dual_control_reversal.sql`
