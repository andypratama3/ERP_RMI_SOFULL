# Tools Status One-Pager

_Generated: 2026-03-11 | Base URL: https://erp.rizqullahmediska.com/ERP_RMI_SOFULL_
_Canonical root: /volume4/web/ERP_RMI_SOFULL_

---

## Cutover Gate Status

| Check | Status | Notes |
|-------|--------|-------|
| `repo_location_guard` | ✅ OK | /Volumes/ guard aktif |
| `volumes_police` | ✅ OK | Patch /volume4/ exempt |
| `volumes_guard` | ✅ OK | |
| `rbac_coverage_check` | ✅ OK | |
| `smoke_pwa` | ✅ OK | |
| `unicode_guard` | ⚠️ CHECK | Jalankan NAS_DEPLOY.sh |
| `preflight` | ✅ OK | |
| `smoke_http` | ⚠️ WARN | db_connect gagal (pdo_mysql CLI) |
| `rbac_smoke` | ✅ OK | |
| `smoke_tools_dashboard` | ✅ OK | |
| `state_migrate_v1` | ⚠️ CHECK | Jalankan fix_all_production.sh |
| `contract_check` | ✅ OK | |
| `business_signoff` | ✅ OK | Ditandatangani Mochamad Chaidir |
| `sales_tracking_checks` | ⚠️ WARN | LEGACY mode aktif |
| `finance_schema_guard` | ⚠️ CHECK | PDO MySQL CLI missing |

---

## Action Items (jalankan di NAS)

```bash
cd /volume4/web/ERP_RMI_SOFULL
sudo bash tools/nas/fix_all_production.sh
```

Kemudian:
```bash
php tools/qa/run_all_checks.php 2>&1 | python3 -m json.tool | grep overall_ok
```

---

## Storage Permissions

| Dir | Writable |
|-----|---------|
| storage/logs | ✅ |
| storage/backups | ✅ |
| storage/uploads | ✅ |
| storage/exports | ❌ Perlu chown http:http |

---

## Key Artifacts

- `storage/logs/cutover_checks.last.json`
- `storage/logs/all_checks.last.json`
- `storage/logs/smoke_http_last.json`
- `storage/logs/repo_location_audit_last.json`
- `storage/logs/business_signoff_check.last.json`

---

## Patches Applied (2026-03-11)

| File | Fix |
|------|-----|
| `tools/backup_now.sh` | @eaDir exclude, exit 18 non-fatal |
| `tools/_shared/tools_path_policy.php` | /volume4/ exempt |
| `tools/_shared/app_root_guard.php` | /Volumes/ hard block |
| `tools/dev/ban_non_ascii_paths.php` | SkipEaDirFilter |
| `tools/qa/run_cutover_checks.php` | Fix escaped vars, mysqli fallback |
| `tools/qa/run_sales_tracking_checks.php` | ALLOW_LEGACY mode |
| `tools/qa/unicode_guard.php` | CATCH_GET_CHILD |
| `tools/release/create_clean_deploy_zip.php` | CATCH_GET_CHILD, writable fallback |
| 9x tools/qa/*.php | CATCH_GET_CHILD |
| `tools/tools_state_lib.php` | Restored 721 lines + tools_default_base_url |

_Dokumen ini digenerate oleh ERP_RMI_SOFULL Fix Agent._
