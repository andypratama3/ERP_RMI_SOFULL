# Test Plan — Front-End (UI) Testing

**Project:** ERP_RMI_SOFULL (Rizqullah Mediska Indonesia) — Internal ERP Web UI  
**Type:** Front-End (UI) Testing  
**APP_ROOT:** /volume4/web/ERP_RMI_SOFULL

---

## 1. Environment Rules

| Environment | Mode | Allowed Actions |
|-------------|------|-----------------|
| **STAGING** | Mutation OK | Full testing, O2C workflow, data creation |
| **PRODUCTION** | SAFE MODE | Read-only, navigation, RBAC only. No destructive/mutation tests. |

---

## 2. Base URLs

| Label | URL |
|-------|-----|
| **PUBLIC** | https://erp.rizqullahmediska.com/ERP_RMI_SOFULL |
| **INTERNAL LAN** | http://10.10.60.20/ERP_RMI_SOFULL |

**Login page:** `/master/login.php`

---

## 3. Test Users

| Persona | Username | Password | Role |
|---------|----------|----------|------|
| QA_ADMIN | _fill_ | _fill_ | ADMIN / SUPERADMIN |
| QA_STAFF_WQS | _fill_ | _fill_ | STAFF (WQS) |
| QA_STAFF_PQP | _fill_ | _fill_ | STAFF (PQP) |
| QA_STAFF_CRM | _fill_ | _fill_ | STAFF (CRM) |
| QA_STAFF_FIN | _fill_ | _fill_ | STAFF (FIN) |
| QA_STAFF_ACT | _fill_ | _fill_ | STAFF (ACT) |
| QA_STAFF_HRL | _fill_ | _fill_ | STAFF (HRL) |
| QA_STAFF_MPR | _fill_ | _fill_ | STAFF (MPR) |
| QA_ITC | _fill_ | _fill_ | ITC (optional) |

> Credentials: `.env` (SMOKE_ADMIN_USER, SMOKE_ADMIN_PASS, SMOKE_STAFF_USER, SMOKE_STAFF_PASS) atau default `smoke_admin`/`SmokeAdmin#123`, `smoke_staff`/`SmokeStaff#123`. Seed otomatis saat `php tools/smoke_http.php`.

---

## 4. Data Constitution

- **office_code branches:** BGR, BKS, TGR, BDG, SLO, SMG
- **depo_code depots:** JGY, KAL
- Dokumen yang mempengaruhi stock **wajib** punya `office_code` + `depo_code`.

---

## 5. Entry Pages to Cover

### MAIN

| URL | Expected |
|-----|----------|
| /dashboards/index.php | 200, role-correct |
| /chat/index.php | 200, role-correct |
| /docs/help_center.php | 200 |
| /dashboards/owner/exec_summary.php | 200 (admin) / 403 (staff) |
| /dashboards/quality/qc_complaint_dashboard.php | 200, role-correct |

### MODULES

| URL | Expected |
|-----|----------|
| /master/index.php | 200 |
| /sales/index.php | 200 |
| /purchases/index.php | 200 |
| /stock/index.php | 200 |
| /hrl/index.php | 200 |
| /hrl_process/index.php | 200 |
| /hrl_reg_alkes/index.php | 200 |
| /absensi/index.php | 200 |
| /kpi/index.php | 200 |
| /payroll/index.php | 200 |
| /mpr/index.php | 200 |
| /Fixed_Asset/index.php | 200 |
| /rbac/index.php | 200 |
| /tools/index.php | 200 (admin) / 302 atau 403 (guest, staff) |

**Navigation rule:** Jangan asumsikan deep URLs. Navigasi ke sub-page melalui link/card/button di dalam entry page.

---

## 6. Security / RBAC Expectations

### 6.1 Guest Mode

| URL | Expected |
|-----|----------|
| /master/login.php | 200 |
| /api/v1/health.php | 200 |
| /tools/* (protected) | 302 redirect ke login |
| /master/master_system_login.php | 302 redirect ke login |

### 6.2 Staff Mode

| URL | Expected |
|-----|----------|
| /tools/health.php | 403 |
| /tools/backup_manager.php | 403 |
| /master/master_system_login.php | 403 |

### 6.3 Admin Mode

| URL | Expected |
|-----|----------|
| /dashboards/index.php | 200 |
| /tools/health.php | 200 |
| /tools/backup_manager.php | 200 |

---

## 7. No-Cyrillic / Link Consistency Rule (CRITICAL)

- **Crawl** semua link (href/src) yang terlihat di setiap halaman.
- **CRITICAL FAIL** jika ada URL mengandung karakter Cyrillic atau Unicode campuran yang menyebabkan link tidak konsisten.

---

## 8. O2C (Sales DO) Workflow Validation (STAGING ONLY)

**flow_status transitions:**

```
DRAFT → crm_to_wqs → wqs_processing → ready_scm → on_delivery → delivered → wait_payment → paid
```

**Verifikasi:**
- Setiap department task page menampilkan DO di stage yang benar:
  - CRM → crm_to_wqs
  - WQS → wqs_processing
  - SCM → ready_scm, on_delivery
  - ACT → delivered
  - FIN → wait_payment, paid

---

## 9. WQS Stock Pages Smoke

**Entry:** /stock/index.php

**Pages to load (role-scope correct):**
- stock
- incoming
- allocation
- picking
- PR
- stock transfer
- stock adjustment
- stock opname
- stock audit

**Assert:** Tidak ada stock leakage antar office/depo filter.

---

## 10. Purchases Import Pages Smoke

**Entry:** /purchases/index.php

**Pages to load (role-correct):**
- purchases dashboard
- import control tower
- PO (view/print)
- GR
- AP invoice
- AP payment
- forwarder quotes / tasks / invoice / payment
- CEISA PIB
- stock update from GR
- bank recon
- GL auto

---

## 11. CSRF Sanity (Admin-only)

- POST action **tanpa** CSRF → **403** (rejected)
- POST action **dengan** valid CSRF → dapat succeed

---

## 12. Evidence Pack

**On failure capture:**
- Screenshot
- HTML dump
- Console logs
- Network log

**Final report must include:**
- Role used
- URL
- Exact repro steps
- Severity

---

## 13. Pass/Fail Gate

| Condition | Result |
|-----------|--------|
| 0 CRITICAL FAIL | Required |
| RBAC behaves as expected | Required |
| No Cyrillic URLs | Required |
| All entry pages reachable with correct role | Required |
| Unexpected 500 / fatal error | = CRITICAL FAIL |

**PASS** hanya jika semua kondisi terpenuhi.

---

## 14. DO NOT

- Jangan ubah business logic, schema, atau workflows.
- Jangan jalankan destructive data creation di production.

---

## 15. Referensi

- `testsprite_tests/UAT_TEST_PLAN_ERP_RMI_SOFULL.md` — UAT persona & URL matrix
- `tools/smoke_http.php` — Smoke test CLI
- `docs/ERP_MENU_WORKFLOW_REFERENCE.md` — Menu & workflow reference
