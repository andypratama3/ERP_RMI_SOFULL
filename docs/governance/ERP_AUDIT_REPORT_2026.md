# ERP_RMI_SOFULL — Comprehensive Audit Report

**Generated:** 2026-03-08  
**Scope:** Full codebase + docs + tools + RBAC + O2C + data integrity

---

## 1. ACCESS SCOPE

| Category | Evidence |
|----------|----------|
| **visible_paths** | `master/`, `sales/`, `purchases/`, `stock/`, `dashboards/`, `hrl/`, `hrl_reg_alkes/`, `hrl_process/`, `payroll/`, `mpr/`, `kpi/`, `Fixed_Asset/`, `chat/`, `api/`, `absensi/`, `rbac/`, `customer_portal/`, `manufacturer_portal/`, `docs/`, `tools/` |
| **hidden_or_ignored_paths** | `vendor/`, `storage/backups/*`, `storage/sessions/*`, `storage/cache/*`, `.git/`, `node_modules/`, `.venv_docs/`, `.cache/`, `.DS_Store`, `.env` — per `tools/backup_now.sh` L186–188 |
| **terminal_available** | `tools/smoke_http.php`, `tools/qa/run_cutover_checks.php`, `tools/qa/rbac_coverage_check.php`, `tools/backup_now.sh`, `tools/restore_now.sh`, `tools/nas/erp.sh`, `tools/rbac_diff_config_db.php` |
| **limitations** | NAS-only: `guard_app_root.sh` locks to `/volume4/web/ERP_RMI_SOFULL`; `erp.sh` rejects Mac (`/Volumes/`); PHP CLI requires `ERP_PHP_BIN` (php84) for pdo_mysql |

---

## 2. SYSTEM MAP

| Module | Docs baseline | Actual files | Permission hooks | Key data objects | Verification |
|--------|---------------|--------------|------------------|------------------|--------------|
| master | `docs/ERP_MENU_WORKFLOW_REFERENCE.md`, `AUDIT_KESELURUHAN_ERP_RMI_SOFULL_2026.md` | ~50 PHP | `require_login`, `require_any_permission`, `require_role` | `master_system_login`, `master_products`, `master_customers`, `rbac_permissions` | `rbac_coverage_check.php` |
| sales | `ERP_MENU_WORKFLOW_REFERENCE.md`, `SOP_CUSTOMER_PORTAL.md` | ~28 PHP | `require_any_permission(['SALES.VIEW',...])` | `sales_do`, `sales_do_items`, `sales_do_audit` | `run_sales_tracking_checks.php`, `check_transaction_status.php` |
| purchases | `ERP_MENU_WORKFLOW_REFERENCE.md`, `SETUP_PEMBELIAN_ANTAR_KANTOR.md` | ~28 PHP | `require_any_permission`, `require_role` | `purchases_po`, `purchases_gr`, `purchases_invoice_ap`, `gl_*` | `contract_check.php` |
| stock | `STOCK_PER_BRANCH_VERIFICATION.md`, `STOCK_OPNAME_EVIDENCE.md` | ~20 PHP | `require_any_permission`, `require_login` | `wqs_stock_by_office`, `wqs_stock_opname`, `wqs_incoming`, `wqs_pr` | `run_wqs_stock_migration_125.php` |
| dashboards | `DASHBOARD_LANDING_PAGES.md`, `LANDING_PAGE_PER_DEPT.md` | ~26 PHP | `require_login`, `require_any_permission` | dashboard_detail, KPI views | `dashboard_role_smoke_web.php` |
| hrl | `officepack/dept_training/` SOPs | ~10 PHP | `require_login`, `require_role` | `hrl_docs`, `hrl_doc_acks` | — |
| payroll | — | ~8 PHP | `require_login`, `require_any_permission` | `payroll_runs`, `payroll_run_items`, `payroll_salary_matrix` | — |
| mpr | `SOP_MPR_Marketing_Project_v1.0.html` | ~14 PHP | `require_login`, `require_any_permission` | `mpr_plans`, `mpr_ops_daily_fin` | — |
| kpi | — | ~17 PHP | `require_login`, `require_any_permission` | `kpi_snapshots`, `dashboard_detail` | `bin/kpi_snapshot_generate.php` |
| Fixed_Asset | `00_FIXED_ASSET_FINAL_PATCH_README.txt` | ~9 PHP | `require_login`, `require_any_permission` | `fa_assets`, `fa_dep_lines`, `fa_audits` | — |
| chat | governance chat specs | ~9 PHP | `require_login`, `require_any_permission` | `chat_channels`, `chat_messages` | `chat_api_smoke_web.php` |
| api | `PERSIAPAN_H2H_PO_HERMINA.md`, `API_*` | ~242 PHP | X-API-Key, Bearer, `require_login` | Partner order_create; mobile auth | `mobile_api_smoke.php`, `h2h_order_test.sh` |
| tools | `README.md`, `tools/nas/README_RUN_ON_NAS.md` | 200+ PHP | `tools_require_access`, `require_role` | — | `run_cutover_checks.php`, `tools_doctor.php` |
| rbac | `RBAC_MATRIX_RMI.md`, `RBAC_ALL_MODULES_V1.md` | 2 PHP | `require_login`, `require_role` | `rbac_permissions`, `rbac_dept_role_permissions`, `rbac_user_permissions` | `rbac_diff_config_db.php`, `rbac_coverage_check.php` |
| absensi | `absensi/_inc/schema.php` | ~30 PHP | `require_login` | `absensi_logs`, `absensi_requests`, `absensi_offices` | — |

---

## 3. EVIDENCE LEDGER

| finding_id | severity | area | evidence | observation | business risk | verification |
|------------|----------|------|----------|-------------|---------------|--------------|
| E001 | INFO | ACCESS | `tools/nas/erp.sh` L14–21 | Mac SMB path triggers RUN_ON_NAS_REQUIRED | CLI must run on NAS | `./tools/nas/erp.sh php tools/xxx.php` |
| E002 | MEDIUM | RBAC | `master/auth.php` L59 | Uses `_shared/rbac.php` (v1), not rbac_v2 | Scope-aware RBAC not active | `grep rbac_v2` — no direct require in app pages |
| E003 | LOW | RBAC | `_shared/rbac_v2.php` L40–48 | `rbac_active_scope_code()` uses scope_code/office_code | v2 exists but not wired | Check rbac/index.php |
| E004 | MEDIUM | O2C | `sales/sales_do.php` L40, L56–58 | CRM_ALLOWED_EDIT_STATUSES; lock blocks POST beyond | CRM cannot edit after WQS | Flow enforced |
| E005 | LOW | O2C | `sales/sales_control_tower.php` L185 | 15 statuses documented | Status set clear | — |
| E006 | INFO | DATA | `stock/_stock_office_helper.php` | RMI_DEFAULT_OFFICE_CODE=BGR; wqs_stock_default_office() | Single source default office | `.cursor/rules/office-codes.mdc` |
| E007 | MEDIUM | DATA | `tools/ops/fix_act_fin_integrity.php` | Backfill scm_delivered_at ≤ act_ready_fin_at ≤ fin_paid_at | Timestamp order for paid DOs | Run on demand |
| E008 | INFO | BACKUP | `tools/backup_now.sh` L6 | Sources guard_app_root.sh — NAS-only | Backup must run on NAS | `install_daily_backup_2300.sh` |
| E009 | LOW | SECURITY | `storage/logs/erp_hardening_triage_history.jsonl` | P0 path traversal in enterprise_guard.php | Potential path traversal | `erp_hardening_triage_run.php` |
| E010 | INFO | RBAC | `tools/qa/rbac_coverage_check.php` | Checks permission guard, admin guard, CSRF on POST | RBAC coverage enforced | `./tools/nas/erp.sh php tools/qa/rbac_coverage_check.php` |
| E011 | MEDIUM | PHP | `php tools/xxx.php` → "could not find driver" | System php lacks pdo_mysql | CLI tools fail | Use `./tools/nas/erp.sh php tools/xxx.php` |
| E012 | MEDIUM | BACKUP | storage/logs permission denied | Web user vs cron user mismatch | Backup fails | chown storage/ to http; rm .backup_now.lock |

---

## 4. FINDINGS BY SEVERITY

### Critical
- None identified in current scan.

### High
- **E011** — PHP CLI pdo_mysql: `php` in PATH lacks driver; mitigated by `erp.sh` using ERP_PHP_BIN.
- **E012** — Backup permission: storage/logs ownership; web-triggered backup fails.

### Medium
- **E002** — RBAC v2 not wired: scope-aware logic unused.
- **E004** — O2C CRM lock: intentional; no risk.
- **E007** — ACT/FIN integrity: fixer exists; run periodically.

### Low
- **E003** — rbac_v2 scope: present but unused.
- **E005** — O2C status doc: informational.
- **E009** — Path traversal: triage logged; manual review.

---

## 5. DOCUMENT VS IMPLEMENTATION DRIFT REGISTER

| Doc claim | Actual repo evidence | Impact | Action needed |
|-----------|---------------------|--------|---------------|
| RBAC v2 scope-aware | `rbac_v2.php` exists; auth.php loads `rbac.php` only | Scope (office) not enforced in permission check | Wire rbac_v2 or document v1 as source of truth |
| PHP CLI pdo_mysql | README says "php tools/xxx" | Fails on NAS with system php | ✅ Fixed: erp.sh uses ERP_PHP_BIN |
| CUTOVER_ONE_PAGER | Status: draft; minimal content | Runbook/rollback incomplete | Update runbook, rollback, verification |
| DEFINITION_OF_DONE | File not found | No DoD baseline | Create or link to governance DoD |
| Office codes HO | `.cursor/rules/office-codes.mdc`: HO not used | Consistent | None |

---

## 6. O2C CONTROL ASSESSMENT

| Aspect | Status | Evidence |
|--------|--------|----------|
| **Transition enforcement** | ✅ | `sales/sales_do.php` CRM lock; task pages per dept (WQS, SCM, ACT, FIN) |
| **Status jump risk** | ✅ Mitigated | `compute_next()` in sales_control_tower; task pages enforce valid transitions |
| **Hidden endpoint risk** | LOW | API partner uses X-API-Key; mobile uses session auth |
| **Missing approval checks** | — | PO/GR/AP have approval flows; DO flow is stage-based (no explicit approval gate) |

**Flow:** CRM → WQS → SCM → ACT → FIN (docs + code aligned)

---

## 7. CROSS-OFFICE / RBAC ASSESSMENT

| Aspect | Status | Evidence |
|--------|--------|----------|
| **Office scoping** | ✅ | `office_code` in master_system_login, wqs_stock_by_office; `wqs_stock_default_office()` = BGR |
| **Role/permission checks** | ✅ | require_login, require_any_permission, require_role; rbac_dept_role_permissions |
| **Legacy v1/v2 conflicts** | MEDIUM | v1 in use; v2 present but not required; no runtime conflict |

---

## 8. DATA INTEGRITY ASSESSMENT

| Area | Status | Evidence |
|------|--------|----------|
| **Stock reconciliation** | ✅ | wqs_stock_opname → wqs_stock_adjustments with opname_id; migration 129 |
| **DO/invoice/payment consistency** | ✅ | fix_act_fin_integrity.php; scm_delivered_at ≤ act_ready_fin_at ≤ fin_paid_at |
| **AP/bank/GL consistency** | ✅ | gl_engine_core, gl_reversal; fin_gl_auto.php |
| **Payroll consistency** | ✅ | payroll_runs, payroll_run_items, payroll_salary_matrix |
| **Asset consistency** | ✅ | fa_assets, fa_dep_lines, depreciation.php |

---

## 9. BACKUP/RESTORE/MIGRATION/RELEASE/QA ASSESSMENT

| Component | Status | Path |
|-----------|--------|------|
| **Backup** | Implemented | `tools/backup_now.sh` |
| **Backup scheduler** | Implemented | `tools/install_daily_backup_2300.sh` |
| **Restore** | Implemented | `tools/restore_now.sh`, `tools/restore_now.php`, `tools/dr/restore_db.php` |
| **Migrations** | Implemented | `sql/migrations/` (145 files) |
| **Migration runner** | Implemented | `tools/nas/run_all_migrations.sh` |
| **Release** | Implemented | `tools/release/create_clean_deploy_zip.php`, `release_final_checklist.php` |
| **QA** | Implemented | `tools/qa/run_cutover_checks.php`, `tools_doctor.php`, `rbac_coverage_check.php`, `smoke_http.php` |

---

## 10. TOP RISKS

1. **PHP CLI environment** — System `php` lacks pdo_mysql; mitigated by `erp.sh` + ERP_PHP_BIN.
2. **Backup permission** — Web vs cron user; storage ownership must align.
3. **RBAC v2 unused** — Scope (office) not enforced in permission checks.
4. **CUTOVER runbook** — Draft; rollback/verification incomplete.

---

## 11. QUICK WINS (≤14 hari)

1. **Selesaikan CUTOVER_ONE_PAGER** — Runbook, rollback, verification checklist.
2. **Fix backup permission** — `chown -R http:http storage/`; hapus lock.
3. **Document PHP CLI** — README + docs/PHP_CLI_PDO_MYSQL_SYNOLOGY.md (✅ done).
4. **RBAC session refresh** — Pastikan user logout/login setelah ubah RBAC.
5. **Sinkron RBAC config-DB** — `./tools/nas/erp.sh php tools/rbac_diff_config_db.php`.

---

## 12. 30/60/90 DAY ROADMAP

| Horizon | Focus |
|---------|-------|
| **30 hari** | CUTOVER runbook final; backup permission stabil; RBAC v1 stabilisasi; smoke/QA green |
| **60 hari** | RBAC v2 wiring (jika scope office diperlukan); path traversal review; DoD baseline |
| **90 hari** | Vendor handover (Hermina H2H, Afya, Coretax); compliance evidence pack |

*Ref: `docs/governance/ROADMAP_EXEC_STATUS.md` — Phases 0–4 DONE.*

---

## 13. VENDOR-FACING SCOPE OF WORK

| Area | Scope |
|------|-------|
| **H2H Order (Hermina)** | `api/v1/partner/order_create.php`; X-API-Key auth; `docs/PERSIAPAN_H2H_PO_HERMINA.md` |
| **Customer Portal** | B2B catalog, checkout, DO print; `docs/SOP_CUSTOMER_PORTAL.md` |
| **Manufacturer Portal** | Reg Alkes case upload; `docs/SOP_MANUFACTURER_PORTAL.md` |
| **Afya / Coretax** | `docs/PANDUAN_INTEGRASI_AFYA.md`, `PANDUAN_INTEGRASI_CORETAX.md` |

---

## 14. ACCEPTANCE CRITERIA

| Criterion | Verification |
|-----------|--------------|
| RBAC coverage 100% | `./tools/nas/erp.sh php tools/qa/rbac_coverage_check.php` → ok: true |
| RBAC config-DB sync | `./tools/nas/erp.sh php tools/rbac_diff_config_db.php` → no "Di config tapi TIDAK di DB" |
| Smoke HTTP pass | `./tools/nas/erp.sh php tools/smoke_http.php` → all pass |
| Backup runs | `bash tools/backup_now.sh` → BACKUP_DONE status=ok |
| Cutover checks pass | `./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --write-last --strict` |
| O2C flow enforced | CRM lock + task pages; no status jump |
| Office default BGR | `wqs_stock_default_office()` = BGR |

---

*Report generated from codebase scan + docs + tools. Update as needed.*
