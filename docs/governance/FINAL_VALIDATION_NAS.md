# Final Validation (NAS Only)

Smoke, contract, dan cutover checks **wajib dijalankan di NAS** karena:
- Base URL: http://10.10.60.20/ERP_RMI_SOFULL
- DB connect ke host internal
- Dari Mac (mount /Volumes) tidak bisa reach 10.10.60.20

## Perintah di NAS

```bash
cd /volume4/web/ERP_RMI_SOFULL
php -v
find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l  # 0 error
php tools/qa/smoke_http.php --strict --write-last
php tools/qa/contract_check.php --strict --write-last
php tools/qa/run_cutover_checks.php --write-last --strict
```

## Gate PASS

- smoke_http: fail=0
- contract_check: ok=true
- cutover_checks: overall_ok=true
- company website: php tools/qa/check_company_sitemap.php (exit 0)
