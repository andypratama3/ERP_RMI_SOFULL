# UI Test Spec — ERP_RMI_SOFULL

## Project & Environment

| Item | Value |
|------|-------|
| **Project** | ERP_RMI_SOFULL (Rizqullah Mediska Indonesia) |
| **Type** | Front-End (UI) Testing |
| **APP_ROOT** | /volume4/web/ERP_RMI_SOFULL (NAS) |

## Environment Rule

- **STAGING:** Prefer for mutation tests (create, update, workflow transitions).
- **Production:** SAFE MODE (read-only / navigation / RBAC only).

## Base URLs

| Label | URL |
|-------|-----|
| **PUBLIC** | https://erp.rizqullahmediska.com/ERP_RMI_SOFULL |
| **INTERNAL LAN** | http://10.10.60.20/ERP_RMI_SOFULL |

## Login

- **Login page:** `/master/login.php`

---

## Test Users

| Role | Username | Password | Dept | Source |
|------|----------|----------|------|--------|
| **QA_ADMIN** | smoke_admin | SmokeAdmin#123 | ITC | smoke_http.php default |
| **QA_STAFF** | smoke_staff | SmokeStaff#123 | ITC | smoke_http.php default |
| **QA_STAFF_WQS** | qa_wqs | QaStaff#123 | WQS | tools/seed/seed_qa_users.php |
| **QA_STAFF_PQP** | qa_pqp | QaStaff#123 | PQP | tools/seed/seed_qa_users.php |
| **QA_STAFF_CRM** | qa_crm | QaStaff#123 | CRM | tools/seed/seed_qa_users.php |
| **QA_STAFF_FIN** | qa_fin | QaStaff#123 | FIN | tools/seed/seed_qa_users.php |
| **QA_STAFF_ACT** | qa_act | QaStaff#123 | ACT | tools/seed/seed_qa_users.php |
| **QA_STAFF_HRL** | qa_hrl | QaStaff#123 | HRL | tools/seed/seed_qa_users.php |
| **QA_STAFF_MPR** | qa_mpr | QaStaff#123 | MPR | tools/seed/seed_qa_users.php |
| **QA_ITC** | smoke_staff | SmokeStaff#123 | ITC | smoke_http.php default |

**Override via env:** `TS_ADMIN_USER`, `TS_ADMIN_PASS`, `TS_STAFF_USER`, `TS_STAFF_PASS`, `SMOKE_ADMIN_USER`, `SMOKE_ADMIN_PASS`, etc.

---

## Data Constitution

| Type | Codes | Description |
|------|-------|-------------|
| **office_code** (branches) | BGR, BKS, TGR, BDG, SLO, SMG | Branch offices |
| **depo_code** (depots) | JGY, KAL | Depot offices |
| **Stock-impacting docs** | Must include `office_code` + `depo_code` | |

---

## Entry Pages (Must Open & Be Role-Correct)

### MAIN

| URL | Role | Expected |
|-----|------|----------|
| /dashboards/index.php | ALL | 200 |
| /chat/index.php | ALL (CHAT.VIEW) | 200 |
| /docs/help_center.php | ALL | 200 |
| /dashboards/owner/exec_summary.php | SYS, ADMIN, SUPERADMIN | 200 |
| /dashboards/quality/qc_complaint_dashboard.php | ALL (DASHBOARD.QUALITY_VIEW) | 200 |

### MODULES

| URL | Role | Expected |
|-----|------|----------|
| /master/index.php | ITC, ADMIN, SUPERADMIN | 200 |
| /sales/index.php | CRM, ADMIN | 200 |
| /purchases/index.php | PQP, SCM, ADMIN | 200 |
| /stock/index.php | WQS, ADMIN | 200 |
| /hrl/index.php | HRL, ADMIN | 200 |
| /hrl_process/index.php | Multi-dept | 200 |
| /hrl_reg_alkes/index.php | HRL, ADMIN | 200 |
| /absensi/index.php | ALL | 200 |
| /kpi/index.php | ALL | 200 |
| /payroll/index.php | FIN, HRL, ADMIN | 200 |
| /mpr/index.php | MPR, FIN, ADMIN | 200 |
| /Fixed_Asset/index.php | ACT, FIN, ADMIN | 200 |
| /rbac/index.php | ITC, ADMIN, SUPERADMIN | 200 |
| /tools/index.php | ITC, ADMIN, SUPERADMIN | 200 |

---

## Security / RBAC Expectations

### Guest Mode

| Test | Expected |
|------|----------|
| GET /master/login.php | 200 |
| GET /api/v1/health.php | 200 |
| GET /tools/* (guest) | 302 → login |
| GET /master/master_system_login.php (guest) | 302 → login |

### Staff Mode

| Test | Expected |
|------|----------|
| Staff GET /tools/health.php | 302 atau 403 |
| Staff GET /tools/backup_manager.php | 302 atau 403 |
| Staff GET /master/master_system_login.php | 302 atau 403 |

### Admin Mode

| Test | Expected |
|------|----------|
| Admin GET /dashboards/index.php | 200 |
| Admin GET /tools/health.php | 200 |
| Admin GET /tools/backup_manager.php | 200 |

---

## Navigation Rule

- **Do NOT** assume deep URLs. Navigate to sub-pages through UI links/cards/buttons found inside each entry page.

---

## No-Cyrillic / Link Consistency Rule (CRITICAL)

- Crawl all links (href/src) visible in each page.
- **CRITICAL FAIL** if any URL contains Cyrillic characters or mixed Unicode that causes inconsistent links.

---

## O2C (Sales DO) Workflow Validation (STAGING ONLY)

| flow_status | Stage |
|-------------|-------|
| DRAFT | CRM |
| crm_to_wqs | CRM → WQS |
| wqs_processing | WQS |
| ready_scm | SCM |
| on_delivery | SCM |
| delivered | ACT |
| wait_payment | FIN |
| paid | FIN |

Verify each department task page shows the DO in the correct stage (CRM/WQS/SCM/ACT/FIN).

---

## WQS Stock Pages Smoke (via /stock/index.php)

| Page | Role | Scope |
|------|------|-------|
| stock | WQS | office/depo filter |
| incoming | WQS | |
| allocation | WQS | |
| picking | WQS | |
| PR | WQS | |
| stock transfer | WQS | |
| stock adjustment | WQS | |
| stock opname | WQS | |
| stock audit | WQS | |

**Assert:** No stock leakage across office/depo filters.

---

## Purchases Import Pages Smoke (via /purchases/index.php)

| Page | Role |
|------|------|
| purchases dashboard | PQP, SCM |
| import control tower | PQP |
| PO (view/print) | PQP |
| GR | PQP |
| AP invoice | FIN |
| AP payment | FIN |
| forwarder quotes/tasks/invoice/payment | PQP, FIN |
| CEISA PIB | ACT |
| stock update from GR | WQS |
| bank recon | FIN |
| GL auto | ACT |

---

## CSRF Sanity (Admin-only)

| Test | Expected |
|------|----------|
| POST action without CSRF | 403 |
| POST action with valid CSRF | Success |

---

## Evidence Pack (On Failure)

On failure capture:

- Screenshot
- HTML dump
- Console logs
- Network log

---

## Final Report

Must include:

- Role used
- URL
- Exact repro steps
- Severity

---

## PASS/FAIL Gate

| Condition | Result |
|-----------|--------|
| 0 CRITICAL FAIL | PASS |
| RBAC behaves as expected | PASS |
| No Cyrillic URLs | PASS |
| All entry pages reachable with correct role | PASS |
| Unexpected 500/fatal error | **CRITICAL FAIL** |

---

## DO NOT

- Do not change business logic, schema, or workflows.
- Do not run destructive data creation on production.

---

## Related Files

| File | Purpose |
|------|---------|
| tools/qa/testsprite_regression.php | Regression suite (HTTP) |
| tools/qa/testsprite_gate.php | Gate parser |
| tools/smoke_http.php | Smoke HTTP CLI |
| tools/seed/seed_qa_users.php | Seed QA users per dept |
| docs/ERP_MENU_WORKFLOW_REFERENCE.md | Menu & workflow reference |
