# PQP & Purchases — Verifikasi Hak Akses per Departemen

**Tujuan:** Dokumen ini memastikan dan mendetailkan hak akses tiap departemen untuk modul PQP/Purchases di ERP_RMI_SOFULL. Hasil pengecekan langsung dari codebase.

**Tanggal verifikasi:** 2026-03

---

## 1. Mekanisme Akses (Sumber: `master/auth.php`)

### 1.1 Alur Cek

```
1. require_login() → redirect ke login jika belum login
2. require_any_permission([...]) ATAU require_role([...])
   - require_any_permission → can_any() → can()
   - can(): auth_is_admin() → rbac_can2() (jika RBAC ready) → auth_legacy_perm_allow_depts()
   - require_role: auth_allow_depts() → cek auth_dept() vs allowed list
```

### 1.2 Kapan Fallback Dipakai

| Kondisi | Sumber Akses |
|---------|--------------|
| RBAC tabel ready + user punya permission di DB | `rbac_can2()` |
| RBAC belum ready / permission tidak ada di DB | `auth_legacy_perm_allow_depts()` |
| `require_any_permission` tidak ada (legacy) | `require_role()` |

### 1.3 `can_dept()` dan Session

- **auth_dept()** = `$_SESSION['department']` (uppercase)
- **auth_role()** = `$_SESSION['role']` (uppercase)
- **can_dept('PQP')** = true jika `department === 'PQP'` ATAU `role === 'PQP'`

---

## 2. auth_legacy_perm_allow_depts (master/auth.php)

**File:** `master/auth.php` baris 285–306

| Permission | Departemen/Role yang Diizinkan |
|------------|--------------------------------|
| PURCHASES.VIEW | ADMIN, SUPERADMIN, SYS, PQP, FIN, ACT, WQS, SCM, BRANCH, MANAGER, STAFF |
| PURCHASES.PO_CRUD | ADMIN, SUPERADMIN, SYS, PQP, SCM, BRANCH, MANAGER, STAFF |
| PURCHASES.AP_INVOICE_CRUD | FIN, ADMIN, SUPERADMIN, SYS, ACT, PQP, SCM, BRANCH, MANAGER, STAFF |
| PURCHASES.AP_PAYMENT_CRUD | FIN, ADMIN, SUPERADMIN, SYS, ACT, PQP, SCM, BRANCH, MANAGER, STAFF |
| PURCHASES.REPORTS_VIEW | ADMIN, SUPERADMIN, SYS, PQP, FIN, ACT, BRANCH, MANAGER, STAFF |
| PURCHASES.FORWARDING_CRUD | SCM, ADMIN, SUPERADMIN, SYS, MANAGER, FIN, PQP, WQS, BRANCH, STAFF |
| PURCHASES.IMPORT_CONTROL | SCM, ADMIN, SUPERADMIN, SYS, PQP, FIN, ACT, WQS, BRANCH, MANAGER, STAFF |
| PURCHASES.CEISA_PIB | ACT, FIN, ADMIN, SUPERADMIN, SYS, PQP, SCM, MANAGER, STAFF |
| PURCHASES.GR_PROCESS | ADMIN, SUPERADMIN, SYS, PQP, FIN, ACT, WQS, SCM, BRANCH, MANAGER, STAFF |
| WQS.PR_CRUD | ADMIN, SUPERADMIN, SYS, WQS, PQP, MANAGER, STAFF |
| WQS.VIEW | ADMIN, SUPERADMIN, SYS, WQS, PQP, SCM, FIN, ACT, BRANCH, MANAGER, STAFF |
| WQS.INCOMING_CRUD | ADMIN, SUPERADMIN, SYS, WQS, PQP, SCM, FIN, ACT, BRANCH, MANAGER, STAFF |
| PQP.VIEW | ADMIN, SUPERADMIN, SYS, PQP, SCM, FIN, ACT, WQS, BRANCH, MANAGER, STAFF |
| DASHBOARD.PROCUREMENT_VIEW | ADMIN, SUPERADMIN, SYS, PQP, SCM, FIN, ACT, WQS, BRANCH, MANAGER, STAFF |

**Tidak ada di auth_legacy (hanya via RBAC DB):** MASTER.MANUFACTURE_CRUD, MASTER.VIEW, MASTER.VENDOR_CRUD, MASTER.VENDOR_VIEW, PURCHASES.ADMIN_STOCK_UPDATE, SALES.VIEW.

**Catatan:** Import Control Tower & Forwarding Tasks memakai can_any([..., 'SALES.VIEW']). Karena SALES.VIEW tidak di auth_legacy, akses mengandalkan permission lain (PURCHASES.IMPORT_CONTROL, PURCHASES.VIEW, PURCHASES.FORWARDING_CRUD).

---

## 3. Matrix Akses per Halaman (Hasil Pengecekan)

### 3.1 Purchases (purchases/*.php)

| File | Permission (require_any_permission) | require_role Fallback | auth_legacy | Status |
|------|-------------------------------------|------------------------|-------------|--------|
| **purchases_dashboard.php** | PURCHASES.VIEW, DASHBOARD.PROCUREMENT_VIEW | ADMIN, SYS, PQP, FIN, ACT, WQS, SCM, BRANCH, MANAGER, STAFF | ✅ | OK |
| **pqp_rfq.php** | PURCHASES.VIEW, PQP.VIEW | ADMIN, SYS, PQP, SCM, FIN, ACT, WQS, BRANCH, MANAGER, STAFF | ✅ | OK |
| **pqp_rfq_download.php** | PURCHASES.VIEW, PQP.VIEW | (sama) | ✅ | OK |
| **pqp_rfq_export.php** | PURCHASES.VIEW, PQP.VIEW | (sama) | ✅ | OK |
| **purchases_po.php** | PURCHASES.PO_CRUD, PURCHASES.VIEW | ADMIN, SYS, PQP, FIN, ACT, WQS, SCM, BRANCH, MANAGER, STAFF | ✅ | OK |
| **purchases_po_view.php** | (sama) | (sama) | ✅ | OK |
| **purchases_po_print.php** | (sama) | (sama) | ✅ | OK |
| **purchases_invoice_ap.php** | AP_INVOICE_CRUD, AP_PAYMENT_CRUD, PURCHASES.VIEW | FIN, ADMIN, SYS, PQP, ACT, WQS, SCM, BRANCH, MANAGER, STAFF | ✅ | OK |
| **purchases_invoice_ap_edit.php** | (sama) | (sama) | ✅ | OK |
| **purchases_payment_ap.php** | (sama) | (sama) | ✅ | OK |
| **purchases_import_control_tower.php** | PURCHASES.IMPORT_CONTROL, PURCHASES.VIEW, SALES.VIEW | SCM, ADMIN, SYS, PQP, FIN, ACT, WQS, BRANCH, MANAGER, STAFF | ✅ | OK |
| **purchases_import_control_view.php** | PURCHASES.IMPORT_CONTROL, PURCHASES.VIEW | ADMIN, SYS, MANAGER, PQP, FIN, SCM, ACT, WQS, STAFF | ✅ | OK |
| **purchases_forwarding_tasks.php** | PURCHASES.FORWARDING_CRUD, PURCHASES.VIEW, SALES.VIEW | SCM, ADMIN, SYS, PQP, FIN, WQS, BRANCH, MANAGER, STAFF | ✅ | ACT tidak diizinkan (sengaja) |
| **purchases_forwarder_quotes.php** | PURCHASES.FORWARDING_CRUD, PURCHASES.VIEW | SCM, ADMIN, SYS, MANAGER, PQP, FIN, WQS, BRANCH, STAFF | ✅ | OK |
| **purchases_forwarder_invoice.php** | AP_INVOICE_CRUD, FORWARDING_CRUD, PURCHASES.VIEW | FIN, ADMIN, SYS, MANAGER, PQP, SCM, WQS, BRANCH, STAFF | ✅ | OK |
| **purchases_forwarder_payment.php** | AP_PAYMENT_CRUD, FORWARDING_CRUD, PURCHASES.VIEW | (sama) | ✅ | OK |
| **purchases_ceisa_pib.php** | PURCHASES.CEISA_PIB, PURCHASES.VIEW | ACT, FIN, ADMIN, SYS, MANAGER, PQP, SCM, STAFF | ✅ | OK |
| **purchases_ceisa_pib_view.php** | (sama) | (sama) | ✅ | OK |
| **purchases_gr.php** | PURCHASES.GR_PROCESS, WQS.INCOMING_CRUD, PURCHASES.VIEW | ADMIN, SYS, PQP, FIN, ACT, WQS, SCM, BRANCH, MANAGER, STAFF | ✅ | OK |
| **purchases_gr_load_items.php** | PURCHASES.GR_PROCESS, WQS.INCOMING_CRUD | (tidak ada require_role) | ✅ | Via can_any saja |
| **purchases_reports.php** | PURCHASES.REPORTS_VIEW, PURCHASES.VIEW, PURCHASES.PO_CRUD, AP_INVOICE_CRUD, AP_PAYMENT_CRUD | (tidak ada) | ✅ | Via can_any |
| **stock_update_from_gr.php** | PURCHASES.GR_PROCESS, WQS.INCOMING_CRUD, PURCHASES.ADMIN_STOCK_UPDATE | ADMIN, SYS, SCM, ACT, FIN, PQP, MANAGER | ⚠️ | WQS, BRANCH, STAFF tidak di fallback |
| **gl_reversal_approvals.php** | PURCHASES.AP_PAYMENT_CRUD, PURCHASES.REPORTS_VIEW | FIN, ADMIN, SYS, MANAGER | ✅ | Terbatas (sengaja) |

### 3.2 Stock / WQS (stock/*.php)

| File | Permission | require_role Fallback | auth_legacy | Status |
|------|------------|------------------------|-------------|--------|
| **wqs_pr.php** | WQS.PR_CRUD, WQS.VIEW, PURCHASES.PO_CRUD | ADMIN, SUPERADMIN, SYS, WQS, BRANCH, MANAGER, STAFF | ⚠️ | PQP tidak di require_role fallback |
| **wqs_incoming.php** | WQS.INCOMING_CRUD, PURCHASES.GR_PROCESS, WQS.VIEW | WQS, ADMIN, SUPERADMIN, SYS, SCM, PQP | ⚠️ | FIN, ACT, BRANCH, MANAGER, STAFF tidak di fallback |

### 3.3 Master Data

| File | Permission | require_role Fallback | auth_legacy | Status |
|------|------------|------------------------|-------------|--------|
| **master_manufactures.php** | MASTER.MANUFACTURE_CRUD, MASTER.VIEW | SYS, SUPERADMIN, ADMIN | ❌ | MASTER.* tidak di auth_legacy; PQP/SCM hanya via RBAC DB |
| **master_vendors.php** | MASTER.VENDOR_CRUD, MASTER.VENDOR_VIEW, MASTER.VENDOR_EDIT, MASTER.VENDOR_DELETE | (tidak ada) | ❌ | Hanya via RBAC DB |

---

## 4. Inkonsistensi & Rekomendasi

### 4.1 wqs_pr.php — PQP Tidak di require_role

- **Masalah:** require_role fallback = ADMIN, SUPERADMIN, SYS, WQS, BRANCH, MANAGER, STAFF. PQP tidak ada.
- **Dampak:** Jika RBAC belum ready dan can_any gagal, user PQP dapat 403.
- **auth_legacy:** WQS.PR_CRUD & PURCHASES.PO_CRUD mengizinkan PQP.
- **Rekomendasi:** Tambah PQP ke require_role fallback di wqs_pr.php.

### 4.2 wqs_incoming.php — require_role Terlalu Ketat

- **Masalah:** require_role = WQS, ADMIN, SUPERADMIN, SYS, SCM, PQP. FIN, ACT, BRANCH, MANAGER, STAFF tidak ada.
- **auth_legacy:** WQS.INCOMING_CRUD mengizinkan FIN, ACT, BRANCH, MANAGER, STAFF.
- **Rekomendasi:** Perluas require_role: tambah FIN, ACT, BRANCH, MANAGER, STAFF.

### 4.3 stock_update_from_gr.php — WQS, BRANCH, STAFF Tidak di Fallback

- **Masalah:** require_role = ADMIN, SYS, SCM, ACT, FIN, PQP, MANAGER. WQS, BRANCH, STAFF tidak ada.
- **auth_legacy:** PURCHASES.GR_PROCESS & WQS.INCOMING_CRUD mengizinkan WQS, BRANCH, STAFF.
- **Rekomendasi:** Tambah WQS, BRANCH, STAFF ke require_role fallback.

### 4.4 Master Manufactures & Vendors — Hanya RBAC DB

- **Masalah:** MASTER.MANUFACTURE_CRUD, MASTER.VENDOR_* tidak ada di auth_legacy.
- **Dampak:** Jika RBAC tabel kosong/ belum di-seed, PQP/SCM tidak bisa akses Master Manufactures/Vendors.
- **Rekomendasi:** Tambah ke auth_legacy (opsional):
  - MASTER.MANUFACTURE_CRUD => ['ADMIN','SUPERADMIN','SYS','PQP','SCM','MANAGER']
  - MASTER.VENDOR_CRUD => ['ADMIN','SUPERADMIN','SYS','SCM','PQP','FIN','MANAGER']

### 4.5 purchases_forwarding_tasks — ACT Tidak Diizinkan

- **Status:** PURCHASES.FORWARDING_CRUD di auth_legacy tidak include ACT. require_role juga tidak include ACT.
- **Kesimpulan:** Konsisten. ACT tidak punya akses Forwarding Tasks (mungkin sengaja).

---

## 5. Sidebar (rmi_layout.php) — data-roles

Menu PQP di sidebar memakai `data-roles` untuk filter tampilan (client-side). Jika user klik link tapi tidak punya akses server-side → 403.

| Menu | data-roles |
|------|------------|
| PQP (Dashboard) | PQP, SCM, FIN, ACT, WQS, BRANCH, MANAGER, STAFF, ADMIN, SUPERADMIN, SYS |
| RFQ | (sama) |
| PO | (sama) |
| AP | (sama) |
| Forwarding Tasks | SCM, PQP, FIN, ACT, WQS, BRANCH, MANAGER, STAFF, ADMIN, SUPERADMIN, SYS |

**Catatan:** Sidebar Forwarding Tasks include ACT, tapi server-side (purchases_forwarding_tasks.php) tidak. User ACT akan lihat menu tapi dapat 403 saat buka halaman.

---

## 6. Scope Office (Manager/Staff)

Dashboard PQP memakai `ds_scope_ctx()` dari `dashboards/_manager_scope.php`:

- **is_admin** = role/level SYS, ADMIN, SUPERADMIN → lihat semua office
- **Bukan admin** → filter by `office_code` dari session

KPI (PR Submitted, PO Open, AP Outstanding) difilter per office jika user bukan admin.

---

## 7. Ringkasan Akses per Departemen

| Dept | Dashboard | RFQ | PO | AP Inv | AP Pay | Import Tower | Forwarding | FWD Quotes | FWD Inv | FWD Pay | CEISA/PIB | GR | WQS PR | WQS Incoming | Master Manu | Master Vendor |
|------|-----------|-----|----|--------|--------|--------------|------------|------------|---------|--------|-----------|----|--------|--------------|-------------|---------------|
| **PQP** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅* | ✅* | RBAC only | RBAC only |
| **SCM** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅* | ✅ | RBAC only | RBAC only |
| **FIN** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅* | ✅* | RBAC only | RBAC only |
| **ACT** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ | ✅* | ✅* | RBAC only | RBAC only |
| **WQS** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | RBAC only | RBAC only |
| **BRANCH** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅* | RBAC only | RBAC only |
| **MANAGER** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅* | RBAC only | RBAC only |
| **STAFF** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅* | RBAC only | RBAC only |

\* Bisa 403 jika RBAC fallback tidak selaras (lihat §4).

---

## 8. Checklist Verifikasi

- [x] auth_legacy_perm_allow_depts lengkap untuk PURCHASES.*, PQP.*, WQS.*, DASHBOARD.PROCUREMENT_VIEW
- [ ] wqs_pr.php: tambah PQP ke require_role fallback
- [ ] wqs_incoming.php: tambah FIN, ACT, BRANCH, MANAGER, STAFF ke require_role fallback
- [ ] stock_update_from_gr.php: tambah WQS, BRANCH, STAFF ke require_role fallback
- [ ] (Opsional) auth_legacy: tambah MASTER.MANUFACTURE_CRUD, MASTER.VENDOR_CRUD untuk fallback
- [ ] (Opsional) Sidebar: hapus ACT dari Forwarding Tasks data-roles jika ACT memang tidak boleh akses
