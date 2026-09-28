# RBAC Final — ERP_RMI_SOFULL

Dokumen ini merangkum finalisasi RBAC berbasis policy tanpa mengubah flow bisnis transaksi.

## 1) Canonical Rules

- Dept resmi: `ITC`, `MPR`, `CRM`, `SCM`, `ACT`, `FIN`, `HRL`, `WQS`, `PQP`, `BRANCH`, `SYS`
- Level: `STAFF`, `MANAGER`, `SYS`
- Permission canonical runtime: `DOT.UPPERCASE` (contoh `PURCHASES.VIEW`, `SYSTEM.RBAC_MANAGE`)
- Legacy underscore tetap didukung via alias normalization di runtime.

## 2) Enforcement Model

- Semua halaman internal mengikuti `require_login()` lalu `require_rbac()` (terintegrasi di `master/auth.php`).
- Mode strict tersedia via env `RMI_RBAC_POLICY_STRICT=1`:
  - route yang tidak terdaftar policy -> `403`.
- SYS override:
  - `department=SYS` + level SYS = bypass policy.
- Illegal access di-audit ke `storage/logs/rbac_apply.log`.

## 3) Single Source of Truth Policy

File: `_shared/rbac_policy.php`

Mapping utama:

- `master/*` -> dept owner + SYS
- `sales/*` -> CRM/WQS/SCM/ACT/FIN/BRANCH/SYS
- `purchases/*` -> PQP/SCM/WQS/ACT/FIN/BRANCH/SYS
- `stock/*` -> WQS/SCM/PQP/BRANCH/SYS
- `hrl*`, `absensi/*`, `kpi/*`, `payroll/*`, `mpr/*`, `Fixed_Asset/*` -> sesuai ownership + SYS
- `rbac/*`:
  - `GET`: ITC+SYS
  - `POST`: SYS only
- `tools/*`:
  - `GET`: ITC+SYS (index)
  - admin write: SYS only

## 4) Office/Depo Scope

Script: `tools/rbac/apply_rbac_policy.php`

- Tabel:
  - `rbac_user_scope(user_id, allowed_office_codes_json, allowed_depo_codes_json)`
- Derive scope:
  - suffix `_BGR/_BKS/_TGR/_BDG/_SLO/_SMG` -> office scope
  - suffix `_JGY/_KAL` -> depo scope
  - SYS -> `ALL`
  - user tanpa suffix -> scope kosong + warning log

## 5) Sales DO Transition Guard

Guard ditambahkan untuk mencegah transisi lintas-dept ilegal:

- CRM: `draft/crm_to_wqs -> crm_to_wqs`
- WQS: `crm_to_wqs -> wqs_processing -> ready_scm`
- SCM: `ready_scm -> on_delivery -> delivered`
- ACT: `delivered -> wait_payment`
- FIN: `wait_payment -> paid`

Implementasi:

- helper: `auth_sales_do_require_transition()` di `master/auth.php`
- enforced di:
  - `sales/sales_do.php`
  - `sales/wqs_do_tasks.php`
  - `sales/scm_do_tasks.php`
  - `sales/act_do_tasks.php`
  - `sales/fin_do_tasks.php`

## 6) Artifacts

- Inventory pages:
  - `storage/logs/rbac_pages_inventory.json`
- Apply log:
  - `storage/logs/rbac_apply.log`
- Completeness check:
  - `storage/logs/rbac_completeness_check_last.json`

## 7) QA Commands

Jalankan dari NAS:

```bash
cd /volume4/web/ERP_RMI_SOFULL
php tools/rbac/build_pages_inventory.php
php tools/rbac/apply_rbac_policy.php
php tools/qa/rbac_completeness_check.php --strict --write-last
php tools/qa/smoke_http.php --strict
php tools/qa/contract_check.php --strict
php tools/qa/run_cutover_checks.php --write-last --strict
```

## 8) Catatan Penting

- Perubahan ini fokus RBAC guard/policy/audit.
- Tidak mengubah alur bisnis transaksi inti.
