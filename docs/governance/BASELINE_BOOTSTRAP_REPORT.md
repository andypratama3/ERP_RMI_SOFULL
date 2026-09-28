# Baseline Bootstrap Report

**Generated:** 2026-03-01  
**Source:** `storage/logs/baseline_bootstrap_last.json`

## Summary

| Check | Status | Detail |
|-------|--------|--------|
| php_version | OK | 8.5.0 |
| php_modules | OK | 61 modules |
| php_lint_sample | OK | Key files linted |
| preflight_check | OK/WARN | DB connection may fail if DB not running |
| contract_check | OK | Warnings only (optional artifacts missing) |
| smoke_http | WARN | Requires web server + DB; pass/fail depends on env |

## Baseline Commands

```bash
php -v
php -m
php -l bootstrap.php index.php tools/preflight_check.php tools/qa/contract_check.php tools/smoke_http.php api/v1/health.php
php tools/preflight_check.php
php tools/qa/contract_check.php --strict
php tools/smoke_http.php --strict
```

## Artifacts

- `storage/logs/preflight_check.last.json`
- `storage/logs/contract_check_last.json` (or contract_check.last.json)
- `storage/logs/smoke_http_last.json`
- `storage/logs/baseline_bootstrap_last.json`

## Notes

- **smoke_http** and **preflight** require DB + web server for full pass.
- **contract_check** validates JSON schema of existing artifacts; missing optional files produce WARN only.
- Run with `APP_BASE_URL=http://127.0.0.1` (or set APP_URL) for HTTP smoke tests.
