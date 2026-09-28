# ERP RMI — Menu, Modul & Workflow Reference

> **Tujuan:** Dokumen referensi konsisten untuk perbaikan, penambahan fitur, dan modul baru.  
> **Update terakhir:** 2026-03-14 — RBAC canonical SYS/MANAGER/STAFF, hapus ADMIN/SUPERADMIN/OWNER/REG.

---

## 0. RBAC Canonical — Konvensi Wajib

### 0.1 Field Valid

| Field | Nilai Valid | Keterangan |
|---|---|---|
| `dept` | `SYS`, `ACT`, `CRM`, `WQS`, `SCM`, `FIN`, `PQP`, `ITC`, `HRL`, `MPR`, `BRANCH` | Uppercase. HO/REG deprecated. |
| `role` | `sys`, `manager`, `staff` | **Lowercase** di DB |
| `level` | `SYS`, `MANAGER`, `STAFF` | **Uppercase** di DB & session |

### 0.2 Mapping di Session (setelah login)

| DB | Session (strtoupper) |
|---|---|
| `role='sys'` | `$_SESSION['role'] = 'SYS'` |
| `role='manager'` | `$_SESSION['role'] = 'MANAGER'` |
| `role='staff'` | `$_SESSION['role'] = 'STAFF'` |
| `level='SYS'` | `$_SESSION['level'] = 'SYS'` |

### 0.3 Privileged: SYS = ADMIN = SUPERADMIN

> Tidak ada perbedaan level ADMIN vs SUPERADMIN. Semua privileged = **SYS**.  
> `ADMIN`, `SUPERADMIN`, `OWNER`, `ROOT` adalah nilai deprecated — tidak ada di DB aktif.

### 0.4 Username SYS yang Valid (5 akun)

| Username | Role | Level | Dept | Fungsi |
|---|---|---|---|---|
| `admin` | `sys` | `SYS` | `SYS` | Akun sistem utama |
| `superadmin` | `sys` | `SYS` | `SYS` | Akun sistem utama |
| `RizqullahMediskaSYS` | `sys` | `SYS` | `SYS` | Akun owner |
| `SmokeSYS_SYS` | `sys` | `SYS` | `SYS` | Smoke test — SYS |
| `SmokeBRANCH_SYS` | `staff` | `STAFF` | `BRANCH` | Smoke test — Staff |

> **Protected** (tidak bisa dihapus dari UI): `admin`, `superadmin`, `rizqullahmediskasys`

### 0.5 Format Username Operasional

```
{Prefix}{Dept}_{OfficeCode}
```

| Prefix | Artinya |
|---|---|
| `Mgr` | Manager |
| `Staff` | Staff |
| `Smoke` | Akun test otomatis |

Contoh valid: `MgrCRM_BGR`, `StaffFIN_BKS`, `SmokeSYS_SYS`

### 0.6 Office Code Valid

| Code | Kota |
|---|---|
| BGR | Bogor |
| BDG | Bandung |
| BKS | Bekasi |
| TGR | Tangerang |
| SLO | Solo |
| SMG | Semarang |
| KAL | Kalimantan |
| JGY | Yogyakarta |
| SYS | System (akun otomatis/test) |

---

## 1. Struktur Menu ERP

### 1.1 Menu View Filter

| Filter | Deskripsi |
|--------|-----------|
| Sesuai Dept | Tampilkan menu sesuai dept user (AUTO) |
| Semua Modul | Tampilkan semua menu |
| CRM, PQP, SCM, WQS, FIN, HRL, ACT, ITC, MPR, SYS | Filter per dept (SYS only) |

### 1.2 Shortcut

- **F1** — Bantuan konteks halaman

### 1.3 User Info (Footer Menu)

- **Login** — Username
- **Role** — `sys` / `manager` / `staff`
- **Dept** — Departemen
- **Level** — `SYS` / `MANAGER` / `STAFF`
- **Office** — Kantor/cabang
- **Logout** — Tombol logout

### 1.4 Struktur Kantor RMI (Multi Branch + Depo)

| Tipe | Kode | Keterangan |
|------|------|------------|
| **Branch** | BGR | Bogor |
| **Branch** | BKS | Bekasi |
| **Branch** | TGR | Tangerang |
| **Branch** | BDG | Bandung |
| **Branch** | SLO | Solo |
| **Branch** | SMG | Semarang |
| **Depo** | KAL | Kalimantan |
| **Depo** | JGY | Yogyakarta |

- **6 Branch:** BGR, BKS, TGR, BDG, SLO, SMG  
- **2 Depo:** KAL, JGY  
- Kode office dipakai di `office_code` (sales_do, wqs, dll.) dan `master_office`.

---

## 2. MAIN

| Menu | URL | Role/Dept | Sub-halaman / Fungsi |
|------|-----|-----------|----------------------|
| Dashboards | `/dashboards/index.php` | ALL | Dashboard Center → kartu per dept |
| Internal Chat | `/chat/index.php` | ALL (CHAT.VIEW) | Chat internal, channels |
| Help Center | `/docs/help_center.php` | ALL | Manual, SOP, Office Pack |
| Executive Summary | `/dashboards/owner/exec_summary.php` | SYS | Ringkasan bisnis |
| Quality & Complaint | `/dashboards/quality/qc_complaint_dashboard.php` | ALL (DASHBOARD.QUALITY_VIEW) | Incoming QC |

---

## 3. MASTER DATA

| Menu | URL | Role/Dept | Permission |
|------|-----|-----------|------------|
| Master Data | `/master/index.php` | ITC, SYS | MASTER.VIEW |

### 3.1 Sub-halaman Master Data

| Kartu | File | Fungsi |
|-------|------|--------|
| Products | master_products.php | SKU, kategori, unit, media/dokumen |
| Products Import | master_import_products.php | Import produk |
| Customers | master_customers.php | Customer, alamat, NPWP, kontak |
| Customers Import | master_import_customers.php | Import customer |
| Vendors | master_vendors.php | Vendor/supplier, rekening |
| Vendors Import | master_import_vendors.php | Import vendor |
| Manufactures | master_manufactures.php | Pabrikan (PO, Reg Alkes) |
| Employees | master_employees.php | Karyawan, dept, level, office |
| Pricelist Jual | master_pricelist_sell.php | Harga jual per produk/customer |
| Pricelist (Buy) | master_pricelist.php | Harga beli |
| Offices | master_office.php | Kantor/cabang |
| Departements | master_departements.php | Dept & mapping office |
| Tax | master_tax.php | Pajak |
| Payment Terms | master_payment_terms.php | Syarat pembayaran |
| ITC Reset Password | itc_reset_password.php | Reset password (ITC/SYS) |
| Account Readiness | account_readiness.php | Cek kesiapan akun |
| Master Data Center | master_data.php | Legacy master data |
| System Login | master_system_login.php | User, role, akses (SYS only) |
| System Config | master_system_config.php | Konfigurasi sistem (SYS only) |
| Company Bank Accounts | company_bank_accounts.php | Rekening perusahaan |

---

## 4. CRM (Sales)

| Menu | URL | Role/Dept | Redirect |
|------|-----|-----------|----------|
| Sales (CRM) | `/sales/index.php` | CRM, SYS | → sales_dashboard.php |

### 4.1 CRM Dashboard — Quick Links

| Kartu | File | Fungsi |
|-------|------|--------|
| CRM Stage | sales_do.php | Buat DO, input/approval |
| CRM Tasks | wqs_do_tasks.php | Task DO |
| WQS Stage | wqs_picking.php | Picking/packing |
| SCM Stage | scm_do_tasks.php | Logistik & forwarding |
| ACT Stage | act_do_tasks.php | Cek accounting |
| FIN Stage | fin_do_tasks.php | Invoice & pembayaran |
| Control Tower | sales_control_tower.php | Tracking DO |
| Tax Invoice | tax_invoices.php | Faktur pajak |
| CRM Leads | crm_leads.php | Pipeline leads |

### 4.2 CRM — Halaman Utama

| Halaman | File | Fungsi |
|---------|------|--------|
| Sales DO | sales_do.php | Buat/edit DO |
| Sales DO View | sales_do_view.php | Detail DO |
| Sales Order | sales_order.php | Sales order |
| CRM Leads | crm_leads.php | Daftar leads |
| CRM Lead Create | crm_lead_create.php | Buat lead |
| CRM Lead Edit | crm_lead_edit.php | Edit lead |
| CRM Lead View | crm_lead_view.php | Detail lead |
| WQS DO Tasks | stock/wqs_do_tasks.php | Task WQS |
| SCM DO Tasks | scm_do_tasks.php | Task SCM |
| ACT DO Tasks | act_do_tasks.php | Task ACT |
| FIN DO Tasks | fin_do_tasks.php | Task FIN |
| Tax Invoices | tax_invoices.php | Faktur pajak |
| Control Tower | sales_control_tower.php | Tracking DO |

---

## 5. PQP (Purchases)

| Menu | URL | Role/Dept | Redirect |
|------|-----|-----------|----------|
| Purchases (PQP) | `/purchases/index.php` | PQP, SCM, SYS | → purchases_dashboard.php |

### 5.1 Purchases — Halaman Utama

| Halaman | File | Fungsi |
|---------|------|--------|
| Purchases Dashboard | purchases_dashboard.php | Ringkasan pembelian |
| Import Control Tower | purchases_import_control_tower.php | Tracking import |
| PO | purchases_po.php | Purchase order |
| PO View | purchases_po_view.php | Detail PO |
| PO Print | purchases_po_print.php | Cetak PO |
| GR | purchases_gr.php | Goods receipt |
| Invoice AP | purchases_invoice_ap.php | AP invoice |
| Payment AP | purchases_payment_ap.php | Pembayaran AP |
| Forwarder Quotes | purchases_forwarder_quotes.php | Quote forwarder |
| Forwarding Tasks | purchases_forwarding_tasks.php | Task forwarder |
| Forwarder Invoice | purchases_forwarder_invoice.php | Invoice forwarder |
| Forwarder Payment | purchases_forwarder_payment.php | Pembayaran forwarder |
| CEISA PIB | purchases_ceisa_pib.php | PIB CEISA |
| Stock Update from GR | stock_update_from_gr.php | Update stok dari GR |
| Bank Recon | bank_recon.php | Rekonsiliasi bank |
| GL Auto | fin_gl_auto.php | Auto GL |

---

## 6. WQS (Warehouse / Stock)

| Menu | URL | Role/Dept | Redirect |
|------|-----|-----------|----------|
| Stock (WQS) | `/dashboards/warehouse/wqs_dashboard.php` | WQS, SYS | WQS Dashboard |
| Stock Opname (WQS) | `/stock/wqs_stock_opname.php` | WQS, SYS | Stock opname |

### 6.1 WQS — Halaman Utama

| Halaman | File | Fungsi |
|---------|------|--------|
| WQS Dashboard | dashboards/warehouse/wqs_dashboard.php | Ringkasan gudang |
| Stock | wqs_stock.php | Stock on hand |
| Incoming | wqs_incoming.php | Receiving |
| Incoming View | wqs_incoming_view.php | Detail incoming |
| Allocation | wqs_allocation.php | Alokasi stok |
| Picking | wqs_picking.php | Picking |
| Picking View | wqs_picking_view.php | View/print picking |
| DO Tasks | wqs_do_tasks.php | Task DO WQS |
| PR | wqs_pr.php | Purchase request |
| PR View | wqs_pr_view.php | Detail PR |
| Stock Transfer | wqs_stock_transfer.php | Transfer antar kantor |
| Stock Adjustment | wqs_stock_adjustment.php | Penyesuaian stok |
| Stock Opname | wqs_stock_opname.php | Stock opname |
| Stock Audit | wqs_stock_audit.php | Audit stok |

---

## 7. HRL

| Menu | URL | Role/Dept | Redirect / Fungsi |
|------|-----|-----------|-------------------|
| HRL (Docs) | `/hrl/index.php` | HRL, SYS | → hrl_docs.php |
| HRL Process | `/hrl_process/index.php` | ACT, CRM, MPR, SCM, WQS, PQP, ITC, HRL, FIN, BRANCH, SYS | Request, PIN approval, tower |
| HRL Reg Alkes | `/hrl_reg_alkes/index.php` | HRL, PQP, SYS | Registrasi alat kesehatan |
| Absensi | `/absensi/index.php` | ALL | Check-in/out, izin, approval |
| KPI Center | `/kpi/index.php` | ALL | KPI dashboard |

### 7.1 HRL Docs — Sub-halaman

| Halaman | File | Fungsi |
|---------|------|--------|
| HRL Docs | hrl_docs.php | Daftar dokumen HRL |
| HRL Doc View | hrl_doc_view.php | Lihat dokumen |
| HRL Doc Download | hrl_doc_download.php | Unduh dokumen |
| HRL Ack Report | hrl_ack_report.php | Laporan acknowledgement |
| HRL Tower | hrl_tower.php | Tower HRL |

### 7.2 HRL Process — Sub-halaman

| Halaman | File | Fungsi |
|---------|------|--------|
| Request View | request_view.php | Detail request |
| Request Print | request_print.php | Cetak request |
| My PIN | my_pin.php | PIN approval |
| Tower | tower.php | Process tower |
| Download | download.php | Unduh & arsip |

### 7.3 HRL Reg Alkes — Sub-halaman

| Halaman | File | Fungsi |
|---------|------|--------|
| Reg Alkes | reg_alkes.php | Kumpulkan dokumen |
| Reg Alkes Case | reg_alkes_case.php | Buat case NIE |
| Control Tower | reg_alkes_control_tower.php | Tracking 15 tahap |
| SKU by NIE | reg_alkes_sku_by_nie.php | Link NIE ke SKU |
| Expiry Check | reg_alkes_expiry_check.php | Cek expiry |

### 7.4 Absensi — Sub-halaman

| Halaman | File | Fungsi |
|---------|------|--------|
| Check-in | checkin.php | Check-in |
| Check-out | checkout.php | Check-out |
| Request | request.php | Izin/sakit/dinas |
| Approval | approval.php | Approval |
| Admin Rekap | admin/rekap.php | Rekap HR |
| Admin Offices | admin/offices.php | GeoFence office |
| Admin Users | admin/users.php | User absensi |

### 7.5 KPI Center — Sub-halaman

| Halaman | File | Fungsi |
|---------|------|--------|
| KPI Center | kpi_center.php | Dashboard KPI |
| KPI Daily | kpi_dashboard_daily.php | KPI harian |
| KPI Monthly | kpi_dashboard_monthly.php | KPI bulanan |
| KPI Employee | kpi_employee.php | KPI per karyawan |
| KPI Office | kpi_office.php | KPI per kantor |
| KPI Stock | kpi_stock.php | KPI stok |
| KPI Purchases | kpi_purchases.php | KPI pembelian |
| KPI DO Audit | kpi_do_audit.php | Audit DO |
| KPI DO SLA | kpi_do_sla.php | SLA DO |

---

## 8. FIN (Finance)

| Menu | URL | Role/Dept | Fungsi |
|------|-----|-----------|--------|
| Payroll | `/payroll/index.php` | FIN, HRL, SYS | Payroll |
| Rekening Perusahaan | `/master/company_bank_accounts.php` | FIN, SYS | Rekening perusahaan |

### 8.1 Payroll — Sub-halaman

| Halaman | File | Fungsi |
|---------|------|--------|
| Payroll Run | payroll_run.php | Buat/edit run |
| Salary Matrix | salary_matrix.php | Matrix gaji |
| Loans | loans.php | Pinjaman |
| Payslip | payslip.php | Slip gaji |
| Settings | payroll_settings.php | Pengaturan |
| Audit | audit.php | Audit payroll |

---

## 9. MPR

| Menu | URL | Role/Dept | Redirect |
|------|-----|-----------|----------|
| MPR | `/mpr/index.php` | MPR, FIN, SYS | MPR → mpr_dashboard.php; FIN → mpr_budget_fin.php |

### 9.1 MPR — Sub-halaman

| Halaman | File | Fungsi |
|---------|------|--------|
| MPR Dashboard | mpr_dashboard.php | Ringkasan MPR |
| MPR Plans | mpr_plans.php | Rencana kunjungan |
| MPR Plan View | mpr_plan_view.php | Detail plan |
| MPR Budget FIN | mpr_budget_fin.php | Approval budget (FIN) |
| MPR Ops Daily FIN | mpr_ops_daily_fin.php | Input kunjungan, bukti |
| MPR Ops Daily FIN Detail | mpr_ops_daily_fin_detail.php | Detail ops |
| MPR Ops Daily FIN Pay | mpr_ops_daily_fin_pay.php | Pembayaran ops |
| MPR Ops Daily FIN Export | mpr_ops_daily_fin_export.php | Export |
| MPR API Contacts | mpr_api_contacts.php | API kontak |

---

## 10. ACT (Fixed Asset)

| Menu | URL | Role/Dept | Fungsi |
|------|-----|-----------|--------|
| Asset (ACT) | `/Fixed_Asset/index.php` | ACT, FIN, SYS | Fixed asset |

### 10.1 Fixed Asset — Sub-halaman

| Halaman | File | Fungsi |
|---------|------|--------|
| Assets | assets.php | Asset register |
| Ops | ops.php | Ops/maintenance |
| Depreciation | depreciation.php | Depresiasi |
| Audit | audit.php | Audit asset |
| Tax Annual | tax_annual.php | Tax annual |
| Disposals | disposals.php | Penghapusan |
| Transfers | transfers.php | Transfer asset |

---

## 11. SETTINGS (SYS only)

| Menu | URL | Role/Dept | Fungsi |
|------|-----|-----------|--------|
| RBAC | `/rbac/index.php` | **SYS only** | Role-based access control (read/write) |
| Tools | `/tools/index.php` | **SYS only** | Tools, backup, health, QA CLI — bukan dept ITC |
| Nav Manager | `/master/nav_manager.php` | SYS | Kelola sidebar nav per dept |

> Kebijakan final: **ITC = dept operasional biasa** (tanpa hak khusus Tools/RBAC di menu Settings). **RBAC Center** dan **Tools** hanya untuk akun **SYS**. ITC mengurus reset password user operasional lewat `itc_reset_password.php` (permission `SYSTEM.USER_MANAGE`), bukan lewat Tools index.

### 11.1 RBAC — Sub-halaman

- Kelola role, permission, user–permission
- Legacy v1 dan v2

### 11.2 Tools — Sub-halaman (ringkas)

| Kategori | Contoh | Fungsi |
|----------|--------|--------|
| Backup | backup_now, backup_manager, restore_now | Backup & restore |
| Health | health.php | Health check |
| Migration | migration/migrate.php | Migrasi DB |
| QA | qa/*.php | UAT, smoke, contract |
| Ops | ops/control_center.php | Control center |
| Release | release/*.php | Release & deploy |
| Audit | audit/*.php | Enterprise audit |

### 11.3 Smoke Test

| Akun | Dept | Role | Fungsi |
|------|------|------|--------|
| `SmokeSYS_SYS` | SYS | sys | Test login & akses admin |
| `SmokeBRANCH_SYS` | BRANCH | staff | Test login & guard staff |
| `SmokeNoCsrf_SYS` | - | - | Test CSRF rejection (dummy, ditolak 403) |

> Password di-seed ulang setiap smoke run. Akun ini **tidak boleh dipakai** untuk operasional.

---

## 12. O2C — Status & Transisi

| Status | flow_status | Dept | Aksi berikutnya |
|--------|-------------|------|------------------|
| DRAFT | CRM | CRM | Submit DO |
| crm_to_wqs, sent_wqs | CRM | WQS | Mulai proses, cek stok |
| wqs_processing | WQS | WQS | Upload foto stok, set READY SCM |
| ready_scm, wqs_done | WQS | SCM | Atur pengiriman |
| on_delivery | SCM | SCM | Konfirmasi DELIVERED + POD |
| delivered, scm_done | SCM | ACT | Tukar faktur, set WAIT PAYMENT |
| wait_payment, act_done | ACT | FIN | Proses pembayaran |
| paid, fin_done, closed | DONE | - | Selesai |
| cancelled | DONE | - | DO dibatalkan |

---

## 13. Permission (RBAC)

| Modul | Permission | Deskripsi |
|-------|------------|-----------|
| Master | MASTER.VIEW | Akses master data |
| Sales | SALES.VIEW, DASHBOARD.SALES_VIEW | Akses sales/CRM |
| Chat | CHAT.VIEW | Akses internal chat |
| HRL Process | HRL.PROCESS_VIEW | Akses HRL process |
| HRL Reg Alkes | HRL.REG_ALKES_VIEW, HRL.VIEW, PQP.VIEW | Akses Reg Alkes |
| Absensi | ABSENSI.VIEW, ABSENSI.CHECKIN, ABSENSI.APPROVE, dll | Akses absensi |
| KPI | KPI.VIEW | Akses KPI |
| Quality | DASHBOARD.QUALITY_VIEW | Akses Quality dashboard |
| Payroll | PAYROLL.* | Berbagai aksi payroll |
| WQS | WQS.INCOMING_CRUD, STOCK.ADJUST, WQS.PR_CRUD, dll | Berbagai aksi WQS |
| System | SYSTEM.USER_MANAGE | Kelola user (SYS only) |
| System | SYSTEM.RBAC_MANAGE | Kelola RBAC (SYS only) |

---

## 14. Panduan Update Dokumen Ini

### Saat Menambah Modul Baru

1. Tambah entri di **Struktur Menu ERP** (bagian yang sesuai: MAIN, MASTER, CRM, dll).
2. Buat sub-bagian baru (mis. `## 15. MODUL_BARU`) dengan:
   - Menu, URL, Role/Dept, Redirect
   - Tabel sub-halaman (File, Fungsi)
3. Tambah permission di **Permission (RBAC)** jika ada.
4. Update `.cursor/rules/` jika ada konvensi baru.
5. **Jangan tambahkan ADMIN/SUPERADMIN/OWNER** — gunakan `SYS`.

### Saat Menambah Fitur ke Modul Existing

1. Tambah baris di tabel **Sub-halaman** modul terkait.
2. Update **Permission** jika ada permission baru.
3. Update **O2C** atau workflow lain jika alur berubah.

### Saat Mengubah Workflow

1. Update tabel **O2C** atau workflow spesifik modul.
2. Update diagram di `docs/officepack/diagrams/` jika ada.
3. Update `docs/dashboard_sales_do_flow.md` atau doc terkait jika relevan.

### Aturan Tambah User Baru

- Format: `{Prefix}{Dept}_{OfficeCode}` — `MgrCRM_BGR`, `StaffFIN_BKS`
- Role: `sys` / `manager` / `staff` (lowercase di DB)
- Level: `SYS` / `MANAGER` / `STAFF` (uppercase di DB)
- **Tidak boleh** menambah username di luar konvensi tanpa konfirmasi eksplisit

---

## 15. Dokumen Terkait

| Dokumen | Path | Isi |
|---------|------|-----|
| **Training Detail (PPT)** | docs/presentations/ERP_RMI_Training_Detail.html | Training untuk tim — sub-halaman, langkah O2C, tips |
| Overview Futuristic | docs/presentations/ERP_RMI_Overview_Futuristic.html | Presentasi overview singkat |
| Dashboard Landing Pages | docs/DASHBOARD_LANDING_PAGES.md | Isi widget/KPI per dashboard |
| Sales DO Flow | docs/dashboard_sales_do_flow.md | Flow rekap Sales DO |
| RBAC Matrix | docs/governance/RBAC_MATRIX_RMI.md | Matriks permission |
| RBAC All Modules | docs/governance/RBAC_ALL_MODULES_V1.md | Permission per modul |
| Landing Page per Dept | docs/governance/LANDING_PAGE_PER_DEPT.md | Landing per dept |
| SYS Users Guard Rule | .cursor/rules/sys-users-rbac-guard.mdc | Aturan wajib AI — RBAC & username |
