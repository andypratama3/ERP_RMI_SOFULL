# RBAC Final Specification — ERP_RMI_SOFULL

> **Status**: Active  
> **Version**: 2.0  
> **Date**: 2026-03-11  
> **Enforced by**: `_shared/rbac.php`, `master/auth.php`, `_shared/rbac_policy.php`

---

## A. Konstitusi RBAC

### A1. Lokasi Operasional

| Jenis | Kode |
|---|---|
| Office | BGR, BKS, TGR, BDG, SLO, SMG |
| Depo | JGY, KAL |

- Semua dokumen transaksi **wajib** punya `office_code`.
- Dokumen yang menyentuh stok fisik **wajib** punya `depo_code`.
- Akses dibatasi sesuai scope user (office/depo). SYS akses global.

### A2. Departemen Resmi

```
ITC | MPR | CRM | SCM | ACT | FIN | HRL | WQS | PQP | BRANCH | SYS
```

**Tidak ada departemen lain.** Tidak boleh ditambah tanpa keputusan eksplisit.

### A3. Hierarki Role

| Level | Username pattern | Scope | Keterangan |
|---|---|---|---|
| SYS | superadmin, admin, RizqullahMediskaSYS, SmokeSYS_SYS | Global (semua) | Super power. Akses penuh. |
| MANAGER | MgrFIN_BGR, MgrCRM_BDG, dll. | Sesuai suffix office | Approve/reject di dept masing-masing |
| STAFF | StaffWQS_BGR, StaffCRM_BDG, dll. | Sesuai suffix office | Create/submit; tidak approve |
| BRANCH | StaffBRANCH_BGR, dll. | Sesuai office | Akses terbatas lintas modul |

---

## B. Prinsip Utama

### B1. Deny-by-Default

- Permission tidak terdaftar → **403**.
- Scope tidak cocok (office/depo mismatch) → **403**.
- RBAC policy strict mode: route tidak terdaftar → **403** (jika strict=true di config).

### B2. Maker-Checker

- User **tidak boleh** approve/post dokumen yang ia sendiri buat.
- Enforced via `auth_maker_checker_require($doc_created_by)` di `master/auth.php`.
- **SYS** dapat bypass maker-checker dengan catatan: wajib ada reason dan audit trail.

### B3. FIN Central Approver Rule ⚠️ KRITIS

```
HANYA user MgrFIN_BGR + SYS yang boleh approve/post payment AP dan GL Reversal.
SEMUA FIN Manager cabang lain (MgrFIN_BDG, MgrFIN_TGR, dst.) DILARANG.
```

Fungsi penegak: `auth_require_fin_central_approver()` di `master/auth.php`.  
Halaman yang dilindungi:
- `purchases/purchases_payment_ap.php` (action: `create_pay` POST)
- `purchases/gl_reversal_approvals.php` (action: `approve` POST)

Permissions khusus MgrFIN_BGR (via `rbac_user_permissions` — bukan dept role):
- `PURCHASES.AP_PAYMENT_APPROVE_POST`
- `PURCHASES.GL_REVERSAL_APPROVE`
- `FIN.PAYMENT_APPROVE`

---

## C. Permission Catalog

Format: `MODULE.RESOURCE_ACTION` (uppercase, backward-compatible)

### C1. SYSTEM (SYS only)

| Permission | Deskripsi |
|---|---|
| `SYSTEM.USER_MANAGE` | Kelola user login, dept, role, password |
| `SYSTEM.RBAC_MANAGE` | Kelola registry permission & matrix RBAC |
| `SYSTEM.CONFIG_MANAGE` | Konfigurasi global |
| `SYSTEM.MFA_POLICY_MANAGE` | Policy MFA per role/dept |
| `SYSTEM.MFA_BYPASS_MANAGE` | MFA bypass tickets |
| `SYSTEM.JOBS_MONITOR` | Worker queue monitoring |
| `SYSTEM.RATE_LIMIT_MANAGE` | API write threshold |

### C2. TOOLS (SYS only)

| Permission | Deskripsi |
|---|---|
| `TOOLS.VIEW` | Akses menu Tools |
| `TOOLS.BACKUP_MANAGE` | Backup/restore/schedule |
| `TOOLS.READINESS_AUDIT` | Audit kesiapan deploy |
| `TOOLS.SECURITY_AUDIT` | Security static scan |
| `TOOLS.PURCHASES_M2_APPLY` | Patch/repair purchases |
| `TOOLS.ITC_RESET_PASSWORD` | Reset password (ITC juga dapat ini) |

### C3. FIN Central (MgrFIN_BGR + SYS ONLY) — Cash Outflow

| Permission | Cash Out | Maker | Approver |
|---|---|---|---|
| `PURCHASES.AP_PAYMENT_APPROVE_POST` | **YES** | FIN staff/manager | **MgrFIN_BGR + SYS** |
| `PURCHASES.GL_REVERSAL_APPROVE` | **YES** | ACT/FIN staff | **MgrFIN_BGR + SYS** |
| `FIN.PAYMENT_APPROVE` | **YES** | FIN staff | **MgrFIN_BGR + SYS** |

### C4. Purchases — Cash Flow Classification

| Permission | Cash Out | Maker | Checker | Approver |
|---|---|---|---|---|
| `PURCHASES.PO_CREATE` | NO | PQP staff | PQP manager | PQP manager |
| `PURCHASES.PO_APPROVE` | NO | PQP staff | — | PQP manager |
| `PURCHASES.GR_PROCESS` | NO | WQS/PQP staff | WQS/PQP manager | — |
| `PURCHASES.AP_INVOICE_CREATE` | NO | ACT/FIN staff | ACT manager | — |
| `PURCHASES.AP_PAYMENT_CREATE` | NO (prepare) | FIN staff | FIN manager | — |
| `PURCHASES.AP_PAYMENT_APPROVE_POST` | **YES** | FIN staff | FIN manager | **MgrFIN_BGR + SYS** |
| `PURCHASES.ADMIN_GL_AUTO` | NO | ACT/FIN | — | FIN manager |
| `PURCHASES.GL_REVERSAL_APPROVE` | **YES** | ACT/FIN | — | **MgrFIN_BGR + SYS** |

### C5. Payroll — Cash Flow Classification

| Permission | Cash Out |
|---|---|
| `PAYROLL.CREATE` | NO (generate run) |
| `PAYROLL.EDIT` | NO |
| `PAYROLL.APPROVE` | **YES** (lock & finalize = siap bayar) |
| `PAYROLL.EXPORT` | NO |

> Payroll approve: FIN Manager + SYS. Eksekusi transfer bank via file export.

### C6. Dept × Permission Matrix (summary)

| Module | SYS | FIN.Mgr | FIN.Staff | ACT.Mgr | ACT.Staff | CRM | WQS | PQP | SCM | HRL | MPR | BRANCH |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| SYSTEM.* | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| TOOLS.* | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| RBAC.* | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| AP Payment Approve | ✅ | MgrBGR only | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| GL Reversal Approve | ✅ | MgrBGR only | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| PAYROLL.APPROVE | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |
| SALES.CREATE | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| SALES.VIEW | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ | ✅ |
| STOCK.VIEW | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ |
| WQS.PR_CREATE | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ |
| PURCHASES.PO_CREATE | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ✅ |
| HRL.REG_ALKES_EDIT | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |

---

## D. Scope Rules

### D1. Derivasi Scope dari Username

| Username suffix | office_code | depo_code |
|---|---|---|
| `_BGR` | BGR | — |
| `_BDG` | BDG | — |
| `_BKS` | BKS | — |
| `_TGR` | TGR | — |
| `_SLO` | SLO | — |
| `_SMG` | SMG | — |
| `_JGY` | — | JGY |
| `_KAL` | — | KAL |
| `_SYS`, `superadmin`, `admin` | ALL | ALL |
| `qa_*`, `uat_*` | kosong (DENY) | kosong (DENY) |

### D2. Scope Enforcement di Queries

- Setiap halaman list/detail dokumen: filter `WHERE office_code = ?` (dari session/scope user).
- Setiap POST mutasi: cek `auth_scope_check_office($doc_office_code)`.
- Fungsi: `auth_scope_check_office()`, `auth_require_office_scope()` di `master/auth.php`.

---

## E. Sales DO Transition Guards

| From | To | Dept yang Boleh |
|---|---|---|
| `draft` | `crm_to_wqs` | CRM |
| `crm_to_wqs` | `wqs_processing` | WQS |
| `wqs_processing` | `ready_scm` | WQS |
| `ready_scm` | `on_delivery` | SCM |
| `on_delivery` | `delivered` | SCM |
| `delivered` | `wait_payment` | ACT |
| `wait_payment` | `paid` | FIN |

SYS dapat override semua transisi (dengan reason wajib + audit).  
Fungsi: `auth_sales_do_require_transition()` di `master/auth.php`.

---

## F. File & Fungsi Utama

| File | Fungsi Utama |
|---|---|
| `_shared/rbac.php` | `rbac_can()`, `rbac_require()`, `rbac_seed_permissions()`, `rbac_apply_baseline()`, `rbac_seed_fin_central_approver()` |
| `master/auth.php` | `require_login()`, `auth_require_fin_central_approver()`, `auth_maker_checker_require()`, `auth_scope_check_office()`, `auth_audit_event()`, `auth_sales_do_require_transition()` |
| `_shared/rbac_policy.php` | `rmi_rbac_policy_config()` — routing rules, dept/level/method mapping |
| `tools/rbac/apply_rbac_policy.php` | CLI: apply & seed semua permission + scope ke DB |
| `tools/rbac/seed_rbac_all_modules.php` | CLI: seed permissions + FIN Central special perms |
| `tools/qa/rbac_completeness_check.php` | QA gate: verify guard coverage, permission registry, SYS-only access |

---

## G. SYS-Only Routes

Routes berikut wajib 403 untuk non-SYS:

- `tools/*`
- `rbac/*`
- `master/master_system_login.php`
- `master/mfa_policy.php`
- `master/mfa_bypass.php`
- `master/jobs_monitor.php`
- `master/rate_limit_policies.php`

---

## H. Verification Commands (jalankan di NAS)

```bash
cd /volume4/web/ERP_RMI_SOFULL

# Apply & seed RBAC permissions
php tools/rbac/seed_rbac_all_modules.php

# Apply scope (rbac_user_scope table)
php tools/rbac/apply_rbac_policy.php

# RBAC completeness check (strict)
php tools/qa/rbac_completeness_check.php --strict --write-last

# Full cutover gate
php tools/qa/run_cutover_checks.php --write-last --strict
# Expected: overall_ok=true, fail_count=0
```

---

## I. Perubahan dari Versi 1.x

| Perubahan | Detail |
|---|---|
| FIN Central Approver | Ditambahkan `auth_require_fin_central_approver()`. Sebelumnya semua FIN Manager bisa post payment. |
| Maker-Checker | Ditambahkan `auth_maker_checker_require()`. |
| Scope check | `auth_scope_check_office()`, `auth_require_office_scope()`. |
| `rbac_seed_fin_central_approver()` | Assign 3 permission critical hanya ke MgrFIN_BGR via rbac_user_permissions. |
| Audit event | `auth_audit_event()` — structured audit ke erp_audit_events atau log fallback. |
| Alias support | `rbac_alias_candidates()` — canonical alias untuk permission legacy. |
| Sales DO guards | `auth_sales_do_require_transition()` di sales, wqs, scm, act, fin DO task pages. |
