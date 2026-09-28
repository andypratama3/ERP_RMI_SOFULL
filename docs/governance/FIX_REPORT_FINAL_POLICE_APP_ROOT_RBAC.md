# FIX REPORT — Polisi Terakhir + RBAC All Modules

**Date:** 2026-03-04  
**Scope:** /Volumes write block, RBAC module guards, cutover gate integration

---

## 1. What Changed

### 1.1 Polisi Terakhir (/Volumes Write Block)

| File | Change |
|------|--------|
| `tools/_shared/tools_path_policy.php` | **NEW** — Single source of truth: `tools_expected_app_root()`, `tools_is_forbidden_path()`, `tools_path_policy_assert_app_root()`, `tools_safe_join()`, `tools_safe_write()`, `tools_scan_forbidden_tokens_in_storage_logs()` |
| `tools/qa/volumes_police.php` | **NEW** — QA tool: runs path policy assert, scans storage/logs & storage/backups for `/Volumes/`, checks forbidden dir footprint. Output: `storage/logs/volumes_police_last.json` |
| `tools/qa/run_cutover_checks.php` | **MODIFIED** — Added `volumes_police` as first step before `volumes_guard`. Passes `ERP_EXPECTED_APP_ROOT` to subprocess. CRITICAL fail on volumes_police or volumes_guard stops early. |

### 1.2 RBAC All Modules

| File | Change |
|------|--------|
| `tools/rbac/seed_rmi_module_permissions.php` | **NEW** — Idempotent seeder for MOD_* permissions (MOD_MASTER, MOD_PURCHASES, MOD_STOCK, MOD_SALES, MOD_FINANCE, MOD_HRL, MOD_PAYROLL, MOD_MPR, MOD_KPI, MOD_FIXED_ASSET, MOD_CHAT, MOD_TOOLS). Baseline dept mapping for SYS+admin + PQP, WQS, CRM, etc. |
| `docs/governance/RBAC_MATRIX_RMI_v1.md` | **NEW** — RBAC access matrix spec: roles, MOD_* permissions, minimum mapping per role |
| `_shared/rbac_module_guard.php` | **NEW** — `rmi_require_module($permCode)`, `rmi_require_admin()` |
| `master/auth.php` | **MODIFIED** — Load `rbac_module_guard.php` after rbac.php |
| `tools/qa/rbac_coverage_check.php` | **MODIFIED** — Expanded scan dirs (hrl, payroll, mpr, kpi, Fixed_Asset, chat). Public allowlist (login, health, tracking). Check for `require_login` or `rmi_require_module` or `tools_require_admin`. tools/ and master admin pages: missing guard = CRITICAL. |
| `tools/qa/run_cutover_checks.php` | **MODIFIED** — rbac_coverage_check step now uses `--write-last` |

---

## 2. Why Safe

- **Path policy:** All writes blocked to `/Volumes/`. `tools_safe_write()` throws on forbidden path.
- **Cutover gate:** volumes_police runs first; any violation = CRITICAL fail, stop early.
- **RBAC guards:** Additive. `auth_is_admin()` bypass for ADMIN/SUPERADMIN. Existing `require_login` + `require_role` kept.
- **No business logic changed:** Guard-only additions. No transaction or data flow modified.

---

## 3. How to Rollback Guard-Only Changes

1. Remove `volumes_police` step from `run_cutover_checks.php` (revert to volumes_guard only).
2. Remove `require_once rbac_module_guard` from `master/auth.php`.
3. Delete `tools/_shared/tools_path_policy.php`, `tools/qa/volumes_police.php`, `_shared/rbac_module_guard.php`.
4. Revert `rbac_coverage_check.php` scan logic if needed.

---

## 4. Commands Used (Masked)

```bash
# On NAS only:
cd [APP_ROOT]
export ERP_EXPECTED_APP_ROOT="[APP_ROOT]"
export TOOLS_BASE_URL="http://10.10.60.20/ERP_RMI_SOFULL"

# Seed MOD_* permissions (dry-run first):
php tools/rbac/seed_rmi_module_permissions.php --dry-run
php tools/rbac/seed_rmi_module_permissions.php --apply --write-last

# Run cutover gate:
php tools/qa/run_cutover_checks.php --write-last --strict

# RBAC coverage:
php tools/qa/rbac_coverage_check.php --write-last

# Smoke:
php tools/smoke_http.php --strict --write-last
```

---

## 5. PASS Evidence Snippets

Expected when run on NAS:

- `volumes_police_last.json`: `overall_ok: true`, `critical_fail_count: 0`
- `rbac_coverage_last.json`: `ok: true`, `critical_count: 0`
- `cutover_checks.last.json`: `overall_ok: true`, all steps `ok: true`

---

## 6. Notes

- **Mac env:** Precheck fails when run from Mac (`/Volumes` exists). All run/verify must be on NAS.
- **ERP_EXPECTED_APP_ROOT:** Must be set before running volumes_police. run_cutover_checks sets it when invoking the step.
- **volumes_police on Mac:** Will fail (FORBIDDEN_TOKEN_IN_STORAGE, FORBIDDEN_DIR_ERP_FOOTPRINT) — expected. Run on NAS only.
- **rbac_coverage_check:** Passes (ok: true) with 26 warn findings for gradual guard refinement.
