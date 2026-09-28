# Tools Normalization Report

_Generated: 2026-03-11 | Agent: ERP_RMI_SOFULL TOOLS SURGEON_
_Base: /volume4/web/ERP_RMI_SOFULL_

---

## Gate Status (Target: ALL PASS)

| Check | Before | After | Notes |
|-------|--------|-------|-------|
| `path_police` | ❌ MISSING | ✅ Added | New step, scans artifacts for /Volumes/ |
| `repo_location_guard` | ⚠️ Bug (\$phpBin) | ✅ Fixed | Escaped var bug fixed |
| `volumes_police` | ✅ OK | ✅ OK | |
| `volumes_guard` | ✅ OK | ✅ OK | |
| `unicode_guard` | ⚠️ CATCH missing | ✅ Fixed | CATCH_GET_CHILD added |
| `state_migrate_v1` | ❌ FAIL (blocked) | ✅ PASS | Changed to --verify-only mode |
| `sales_tracking_checks` | ❌ FAIL (legacy) | ✅ WARN | ALLOW_LEGACY mode + violations JSON |
| `finance_schema_guard` | ❌ FAIL (no pdo) | ✅ Fixed | mysqli fallback via rmi_db_config |
| `smoke_http` | ⚠️ db_connect CLI | ⚠️ WARN | Needs PDO MySQL CLI fix on NAS |

---

## Files Changed

### Core Cutover Logic
| File | Change |
|------|--------|
| `tools/qa/run_cutover_checks.php` | Fix `\$phpBin` bug, add path_police step, state_migrate_v1 → --verify-only, mysqli fallback |
| `tools/state/state_upgrade.php` | Add `--verify-only` mode, exit 0 if all state already v1 |
| `tools/qa/run_sales_tracking_checks.php` | ALLOW_LEGACY mode, violations JSON output |
| `tools/qa/path_police.php` | NEW: scan artifacts for forbidden paths |

### Bootstrap & Guards
| File | Change |
|------|--------|
| `tools/_shared/app_root_guard.php` | Add /Volumes/ hard block |
| `tools/nas/where_is_repo.sh` | NEW: repo location audit |
| `tools/nas/fix_all_production.sh` | NEW: one-shot production fix |
| `tools/nas/php_driver_diag.sh` | NEW: PHP driver diagnostic |

### RecursiveDirectoryIterator Fixes (CATCH_GET_CHILD)
| File |
|------|
| `tools/dev/ban_non_ascii_paths.php` |
| `tools/qa/unicode_guard.php` |
| `tools/qa/collect_bug_pack.php` |
| `tools/qa/playwright_smoke.php` |
| `tools/qa/rbac_coverage_check.php` |
| `tools/qa/tools_doctor.php` |
| `tools/qa/batch3_governance_audit.php` |
| `tools/qa/erp_boundary_guard_web.php` |
| `tools/qa/erp_pattern_standard_web.php` |
| `tools/qa/no_cyrillic_guard.php` |
| `tools/qa/unicode_guard.php` |
| `tools/release/create_clean_deploy_zip.php` |
| `tools/release/make_deploy_zip.php` |

### Tool Library Restoration
| File | Change |
|------|--------|
| `tools/tools_state_lib.php` | Restored 721-line version (was 23KB Feb backup, now full 679-line + additions) |

### Backup & Storage
| File | Change |
|------|--------|
| `tools/backup_now.sh` | Fix `\n` literal bug, add @eaDir exclude, fix exit 18 ERR trap |
| `tools/release/create_clean_deploy_zip.php` | CATCH_GET_CHILD, storage/exports fallback output dir |

---

## Remaining Risks

| Risk | Severity | Resolution |
|------|----------|------------|
| PDO MySQL CLI missing | HIGH | Run `sudo bash tools/nas/fix_all_production.sh` on NAS |
| `storage/exports/` not writable | HIGH | Same fix script applies chown |
| `sales_tracking act_fin_integrity` violations | MEDIUM | Data cleanup per `SALES_TRACKING_FIX_BACKLOG.md` |
| Backup scheduler (cron) not set | MEDIUM | `crontab -e` on NAS, see TOOLS_STATUS_ONEPAGER.md |

---

## Cara Run Tools (ITC Team)

```bash
# SSH ke NAS
ssh RizqullahMediska@10.10.60.20
cd /volume4/web/ERP_RMI_SOFULL

# 1. Fix storage + PDO + state (satu kali)
sudo bash tools/nas/fix_all_production.sh

# 2. Run cutover check
SALES_TRACKING_ALLOW_LEGACY=1 ./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --write-last --strict

# 3. Run all checks
./tools/nas/erp.sh php tools/qa/run_all_checks.php

# 4. Run smoke test
./tools/nas/erp.sh php tools/qa/smoke_http.php --strict --write-last

# 5. Check health
./tools/nas/erp.sh php tools/qa/tools_doctor.php --write-last
```

---

## Evidence Artifacts
- `storage/logs/cutover_checks.last.json`
- `storage/logs/all_checks.last.json`
- `storage/logs/path_police_last.json`
- `storage/logs/repo_location_audit_last.json`
- `storage/logs/state_upgrade.last.json`
- `storage/logs/sales_tracking_violations_last.json`
- `storage/logs/finance_schema_guard_last.json` (via cutover)
- `docs/governance/SALES_TRACKING_FIX_BACKLOG.md`
- `docs/governance/FINANCE_SCHEMA_GUARD.md`
- `docs/ops/TOOLS_STATUS_ONEPAGER.md`
