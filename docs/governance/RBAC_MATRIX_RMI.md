# RBAC Matrix — ERP_RMI_SOFULL

> **Version**: 3.0 (Final)  
> **Date**: 2026-03-15  
> **Source of truth**: `_shared/rbac_policy.php` (82 rules) + `master/auth.php` + `_shared/rbac.php`  
> **Test evidence**: `tools/qa/_artifacts/smoke_http_last.json` (55 test cases)

---

## 1. Hierarki Otorisasi

```
SYS (super) > MgrFIN_BGR (FIN Central Approver) > Manager Dept > Staff Dept > Guest
```

| Level | Deskripsi | Akses Tools/RBAC | Akses Admin Pages |
|---|---|---|---|
| **SYS** | Privileged penuh | ✅ FULL | ✅ FULL |
| **MgrFIN_BGR** | FIN Pusat — outflow approver | ❌ | ❌ |
| **MANAGER (FIN)** | Monitor & approve non-outflow | ❌ | ❌ |
| **MANAGER (other)** | Approve di dept masing-masing | ❌ | ❌ |
| **STAFF** | Create/submit saja | ❌ | ❌ |
| **BRANCH** | Terbatas per landing page | ❌ | ❌ |

---

## 2. Endpoint 200/403 Matrix (Critical Routes)

### 2A. Index Redirects

| URL | Behavior | Guard |
|---|---|---|
| `/sales/index.php` | 302 → `sales_dashboard.php` | `require_login()` |
| `/purchases/index.php` | 302 → `purchases_dashboard.php` | `require_login()` |
| `/stock/index.php` | 302 → `wqs_stock.php` | `require_login()` + `require_any_permission(WQS perms)` |
| `/hrl/index.php` | 302 → `hrl_docs.php` | `require_login()` |
| `/mpr/index.php` | MPR→`mpr_dashboard`, FIN→`mpr_budget_fin`, else 403 | `require_login()` + dept check |

### 2B. Dashboard Access

| Dashboard | Allowed Depts | Guard Type |
|---|---|---|
| `/dashboards/index.php` | ALL | require_login |
| `/dashboards/branch/branch_dashboard.php` | BRANCH, SYS | policy + auth_allow_depts |
| `/sales/sales_dashboard.php` | CRM, BRANCH, SYS | policy + auth_allow_depts |
| `/dashboards/warehouse/wqs_dashboard.php` | WQS, SCM, BRANCH, SYS | policy + auth_allow_depts |
| `/dashboards/finance/ar_ap_cash_dashboard.php` | FIN (MANAGER+SYS only) | policy (level:MANAGER) + auth_is_fin_manager |
| `/dashboards/act/act_dashboard.php` | ACT, SYS | policy + auth_allow_depts |
| `/dashboards/hrl/hrl_dashboard.php` | HRL, SYS | policy + auth_allow_depts |
| `/dashboards/scm/scm_dashboard.php` | SCM, SYS | policy + auth_allow_depts |
| `/dashboards/quality/qc_complaint_dashboard.php` | PQP, WQS, SCM, ACT, BRANCH, SYS | policy + auth_allow_depts |
| `/dashboards/regulatory/license_docs_dashboard.php` | PQP, HRL, FIN, ACT, BRANCH, SYS | policy + auth_allow_depts |

### 2C. Locked Routes — Dept × Expected HTTP Code

| Route | SYS | FIN | ACT | CRM | WQS | PQP | SCM | HRL | ITC | MPR | BRANCH |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| `/stock/wqs_pr.php` | 200 | 403 | 403 | 403 | **200** | 403 | 403 | 403 | 403 | 403 | **200** |
| `/purchases/purchases_po.php` | 200 | 403 | 403 | 403 | **200** | **200** | 403 | 403 | 403 | 403 | **200** |
| `/stock/wqs_incoming.php` | 200 | 403 | 403 | 403 | **200** | 403 | **200** | 403 | 403 | 403 | **200** |
| `/purchases/purchases_invoice_ap.php` | 200 | **200** | **200** | 403 | 403 | 403 | 403 | 403 | 403 | 403 | 403 |
| `/purchases/purchases_payment_ap.php` (GET) | 200 | **200** | 403 | 403 | 403 | 403 | 403 | 403 | 403 | 403 | 403 |
| `/purchases/gl_reversal_approvals.php` (GET) | 200 | **200** | **200** | 403 | 403 | 403 | 403 | 403 | 403 | 403 | 403 |
| `/sales/sales_do.php` | 200 | **200** | **200** | **200** | **200** | 403 | **200** | 403 | 403 | 403 | **200** |
| `/stock/wqs_stock.php` | 200 | 403 | 403 | 403 | **200** | 403 | **200** | 403 | 403 | 403 | **200** |
| `/stock/wqs_stock_adjustment.php` (MANAGER only) | 200 | 403 | 403 | 403 | **Mgr:200** | 403 | 403 | 403 | 403 | 403 | 403 |
| `/stock/wqs_picking.php` | 200 | 403 | 403 | 403 | **200** | 403 | **200** | 403 | 403 | 403 | **200** |
| `/stock/wqs_allocation.php` | 200 | 403 | 403 | 403 | **200** | 403 | **200** | 403 | 403 | 403 | **200** |

### 2D. Admin / SYS-Only Pages

| Route | SYS | ITC | BRANCH | Any other |
|---|:---:|:---:|:---:|:---:|
| `/master/master_system_login.php` | **200** | 403 | 403 | 403 |
| `/master/master_system_config.php` | **200** | 403 | 403 | 403 |
| `/master/nav_manager.php` | **200** | 403 | 403 | 403 |
| `/master/mfa_policy.php` | **200** | 403 | 403 | 403 |
| `/rbac/index.php` | **200** | 403 | 403 | 403 |
| `/rbac/*` | **200** | 403 | 403 | 403 |
| `/tools/index.php` | **200** | **200** | 403 | 403 |
| `/tools/health.php` | **200** | 403 | 403 | 403 |
| `/tools/backup_manager.php` | **200** | 403 | 403 | 403 |
| `/tools/backup_schedule.php` | **200** | 403 | 403 | 403 |

---

## 3. Special Rules

### 3A. FIN Central Approver (2-Layer Enforcement)

```
For actions: POST create_pay | POST approve GL Reversal | POST approve forwarder payment

Layer A (RBAC permission): PURCHASES.AP_PAYMENT_APPROVE_POST | PURCHASES.GL_REVERSAL_APPROVE
         → Only assigned to: MgrFIN_BGR (via rbac_user_permissions) + SYS dept (via rbac_dept_role_permissions)

Layer B (hard username check in code): auth_require_fin_central_approver()
         → if (username != 'MgrFIN_BGR' && dept != 'SYS') => 403

Result:
  MgrFIN_BGR + valid CSRF → 200
  MgrFIN_BDG (any non-BGR FIN manager) → 403
  StaffFIN_* → 403
  SYS → 200
```

### 3B. Maker-Checker

```
auth_maker_checker_require($doc_created_by)
  → if (session.username == doc_created_by && !auth_is_admin()) => 403

Applied in: gl_reversal_approvals.php (approve/reject actions)
Helper available for: any module needing approve-not-self rule
SYS bypass: allowed (but requires reason + audit trail)
```

### 3C. CSRF Enforcement

```
require_login() calls verify_csrf() for all POST/PUT/PATCH/DELETE.
Result: any mutating request without valid CSRF token => 403 (Forbidden CSRF)
```

### 3D. Office Scope Enforcement

```
auth_scope_check_office($doc_office_code) → bool
auth_require_office_scope($doc_office_code) → 403 if mismatch

Username suffix → office_code mapping:
  _BGR → BGR | _BDG → BDG | _BKS → BKS | _TGR → TGR
  _SLO → SLO | _SMG → SMG | _JGY → JGY (depo) | _KAL → KAL (depo)
  SYS accounts → GLOBAL (no filter)
  qa_* / uat_* → empty scope (DENY unless explicitly mapped)
```

---

## 4. Permission Codes

### 4A. Permission Format (Dual: legacy underscore + canonical dot)

| Legacy (RBAC_ALL_MODULES_V1) | Canonical (rbac.php) | Category |
|---|---|---|
| `PURCHASES_READ` | `PURCHASES.VIEW` | View |
| `AP_PAYMENT_CREATE` | `PURCHASES.AP_PAYMENT_CREATE` | Create |
| `AP_PAYMENT_APPROVE` | `PURCHASES.AP_PAYMENT_APPROVE_POST` | Approve (FIN Central) |
| `STOCK_READ` | `STOCK.VIEW` | View |
| `SALES_READ` | `SALES.VIEW` | View |
| `HRL_READ` | `HRL.VIEW` | View |
| `TOOLS_READ` | `TOOLS.VIEW` | View |

Alias resolution via `rbac_alias_candidates()` in `_shared/rbac.php`.

### 4B. Critical Cash-Out Permissions (FIN Central Only)

| Permission | Assigned To | Scope |
|---|---|---|
| `PURCHASES.AP_PAYMENT_APPROVE_POST` | MgrFIN_BGR (user) + SYS (dept) | Global |
| `PURCHASES.GL_REVERSAL_APPROVE` | MgrFIN_BGR (user) + SYS (dept) | Global |
| `FIN.PAYMENT_APPROVE` | MgrFIN_BGR (user) + SYS (dept) | Global |

---

## 5. Enforcement Files

| File | Purpose |
|---|---|
| `_shared/rbac_policy.php` | 82-rule routing policy (primary enforcement) |
| `_shared/rbac.php` | Permission registry, seed, `rbac_can()`, `rbac_require()`, `rbac_seed_fin_central_approver()` |
| `master/auth.php` | `require_login()`, `auth_require_fin_central_approver()`, `auth_maker_checker_require()`, `auth_scope_check_office()`, `auth_audit_event()` |
| `tools/qa/_artifacts/smoke_http_last.json` | 55-scenario smoke test matrix |
| `storage/logs/rbac_pages_inventory.json` | 82-rule inventory with risk classification |

---

## 6. Definition of Done

- [x] Deny-by-default: unlisted routes 403 (strict mode via `RMI_RBAC_POLICY_STRICT=1`)
- [x] Guest → 302 (redirect login) for all internal pages
- [x] Auth + no permission → 403 (not redirect)
- [x] SYS can access Tools/RBAC/System Login
- [x] BRANCH staff blocked (403) for Tools/RBAC/System Login
- [x] FIN outflow approvals ONLY MgrFIN_BGR or SYS (2-layer: permission + username check)
- [x] Maker-checker enforced in GL Reversal (cannot self-approve)
- [x] CSRF mandatory for all POST
- [x] 82 routing rules covering all critical paths
- [ ] `smoke_http.php --strict` PASS — **run on NAS** (`/volume4/web/ERP_RMI_SOFULL`)
- [ ] `run_cutover_checks.php --write-last --strict` PASS — **run on NAS**

---

## 7. NAS Validation Commands

```bash
cd /volume4/web/ERP_RMI_SOFULL

# Apply permissions to DB (seeds FIN Central + all dept/role mappings)
php tools/rbac/seed_rbac_all_modules.php --apply --i-understand --write-last

# Verify routes + guard coverage
php tools/qa/rbac_completeness_check.php --strict --write-last

# Full smoke test
php tools/qa/smoke_http.php --strict

# Cutover gate
php tools/qa/run_cutover_checks.php --write-last --strict
# Expected: overall_ok=true, fail_count=0
```
