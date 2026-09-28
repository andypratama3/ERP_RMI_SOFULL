# UAT per Dept — Manager & Staff

**Tujuan:** Checklist UAT untuk tim masing-masing departemen (Manager dan Staff).  
**Sumber:** docs/ERP_MENU_WORKFLOW_REFERENCE.md, docs/governance/RBAC_MATRIX_RMI_v1.md  
**Update:** 2026-03

---

## Panduan Umum

| Role | Akses | Approval |
|------|-------|----------|
| **Manager** | View + Create + Edit + **Approve** | Bisa approve sesuai modul dept |
| **Staff** | View + Create + Edit | Tidak bisa approve (submit ke Manager) |

**FIN Khusus:** Hanya `MgrFIN_BGR` + SYS yang boleh approve/pay pengeluaran. MgrFIN cabang lain: view only.

---

## 1. CRM (Sales)

### 1.1 CRM Manager

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun MgrCRM_* | Berhasil, redirect ke Dashboard |
| 2 | Dashboard | Buka Sales Dashboard | Kartu stage CRM/WQS/SCM/ACT/FIN tampil |
| 3 | Buat DO | Sales DO → Buat DO baru | Form load, bisa input customer, item |
| 4 | Submit DO | Submit DO (status DRAFT → sent) | DO terkirim ke WQS |
| 5 | Control Tower | Buka Sales Control Tower | List DO dengan status per stage |
| 6 | CRM Leads | Buka CRM Leads | List leads, filter status |
| 7 | Tax Invoice | Buka Tax Invoices | List faktur pajak (jika ada) |
| 8 | Chat | Buka Internal Chat | Bisa kirim/baca chat |

### 1.2 CRM Staff

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun StaffCRM_* | Berhasil |
| 2 | Dashboard | Buka Sales Dashboard | Kartu stage tampil |
| 3 | Buat DO | Sales DO → Buat DO baru | Bisa input, submit |
| 4 | Control Tower | Buka Control Tower | Bisa lihat DO |
| 5 | CRM Leads | Buka CRM Leads | Bisa create/edit lead |
| 6 | Approval | Coba akses halaman approval (jika ada) | 403 atau tidak tampil |

---

## 2. PQP (Purchasing)

### 2.1 PQP Manager

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun MgrPQP_* | Berhasil |
| 2 | Dashboard | Buka Purchases Dashboard | Ringkasan PO, GR, AP |
| 3 | Import Control Tower | Buka Import Control Tower | Tracking import, filter |
| 4 | PO | Buka PO → Buat/Edit PO | Form load, approve PO |
| 5 | GR | Buka GR | List GR, post GR |
| 6 | Invoice AP | Buka Invoice AP | Input invoice AP |
| 7 | Approval | Approve PO/PR (jika sesuai) | Status berubah |

### 2.2 PQP Staff

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun StaffPQP_* | Berhasil |
| 2 | Dashboard | Buka Purchases Dashboard | Ringkasan tampil |
| 3 | PO | Buka PO → Buat PO | Bisa create, submit |
| 4 | GR | Buka GR | Bisa input GR |
| 5 | Invoice AP | Buka Invoice AP | Bisa input invoice |
| 6 | Approval | Coba approve PO | 403 (Staff tidak approve) |

---

## 3. WQS (Warehouse)

### 3.1 WQS Manager

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun MgrWQS_* | Berhasil |
| 2 | Dashboard | Buka WQS Dashboard | Stock, Incoming, Allocation, Picking |
| 3 | Stock | Buka Stock | List stok on hand |
| 4 | Incoming | Buka Incoming | List receiving |
| 5 | PR | Buka PR (Purchase Request) | Buat PR, approve PR |
| 6 | Stock Opname | Buka Stock Opname | Buat opname, apply opname |
| 7 | Stock Adjustment | Buka Stock Adjustment | Approve adjustment |
| 8 | Picking | Buka Picking | List picking, proses |

### 3.2 WQS Staff

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun StaffWQS_* | Berhasil |
| 2 | Dashboard | Buka WQS Dashboard | Kartu tampil |
| 3 | Incoming | Buka Incoming | Bisa input receiving |
| 4 | PR | Buka PR | Bisa buat PR, submit |
| 5 | Picking | Buka Picking | Bisa proses picking |
| 6 | DO Tasks | Buka WQS DO Tasks | Task DO WQS |
| 7 | Stock Opname | Buka Stock Opname | Bisa buat, submit (apply butuh Manager) |

---

## 4. SCM (Supply Chain)

### 4.1 SCM Manager

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun MgrSCM_* | Berhasil |
| 2 | Dashboard | Buka WQS Dashboard / Import Control Tower | Sesuai akses |
| 3 | SCM DO Tasks | Buka SCM DO Tasks | Task DO logistik |
| 4 | Forwarder | Buka Forwarder Quotes / Forwarding Tasks | Quote, task forwarder |
| 5 | Control Tower | Buka Sales Control Tower | Tracking DO |
| 6 | Import Control Tower | Buka Import Control Tower | Tracking import |

### 4.2 SCM Staff

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun StaffSCM_* | Berhasil |
| 2 | SCM DO Tasks | Buka SCM DO Tasks | Bisa proses task |
| 3 | Forwarding | Buka Forwarding Tasks | Bisa update status |
| 4 | Control Tower | Buka Control Tower | Bisa lihat DO |

---

## 5. ACT (Accounting)

### 5.1 ACT Manager

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun MgrACT_* | Berhasil |
| 2 | Dashboard | Buka Finance Dashboard (AR/AP) | Dashboard tampil |
| 3 | ACT DO Tasks | Buka ACT DO Tasks | Task accounting DO |
| 4 | Tax Invoice | Buka Tax Invoices | Faktur pajak |
| 5 | Invoice AP | Buka Invoice AP | Validasi AP |
| 6 | Fixed Asset | Buka Fixed Asset | Asset register, depreciation |
| 7 | CEISA PIB | Buka CEISA PIB | PIB CEISA |

### 5.2 ACT Staff

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun StaffACT_* | Berhasil |
| 2 | ACT DO Tasks | Buka ACT DO Tasks | Bisa proses task |
| 3 | Tax Invoice | Buka Tax Invoices | Bisa input/lihat |
| 4 | Invoice AP | Buka Invoice AP | Bisa input invoice |
| 5 | Fixed Asset | Buka Fixed Asset | Bisa lihat/edit (sesuai permission) |

---

## 6. FIN (Finance)

### 6.1 FIN Manager (MgrFIN_BGR — Central Approver)

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun MgrFIN_BGR | Berhasil |
| 2 | Dashboard | Buka Finance Dashboard | AR/AP, Cash tampil |
| 3 | Payment AP | Buka Payment AP | **Bisa approve & execute payment** |
| 4 | FIN DO Tasks | Buka FIN DO Tasks | Proses pembayaran DO |
| 5 | Payroll | Buka Payroll | Run, salary matrix, payslip |
| 6 | MPR Budget | Buka MPR Budget FIN | Approval budget MPR |
| 7 | Company Bank | Buka Company Bank Accounts | Rekening perusahaan |

### 6.2 FIN Manager (MgrFIN selain BGR — Cabang)

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun MgrFIN_BKS / MgrFIN_BDG | Berhasil |
| 2 | Payment AP | Coba approve/pay AP | **403 — tidak boleh** |
| 3 | View | Buka Payment AP, Finance Dashboard | Bisa lihat (view only) |

### 6.3 FIN Staff

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun StaffFIN_* | Berhasil |
| 2 | Dashboard | Buka Finance Dashboard | Bisa lihat |
| 3 | Payment AP | Coba approve payment | 403 |
| 4 | Payroll | Buka Payroll | Sesuai permission (baca payslip) |

---

## 7. HRL (HR & Legal)

### 7.1 HRL Manager

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun MgrHRL_* | Berhasil |
| 2 | HRL Docs | Buka HRL Docs | Daftar dokumen |
| 3 | HRL Tower | Buka HRL Tower | Tower HRL |
| 4 | Reg Alkes | Buka Reg Alkes | Case NIE, Control Tower |
| 5 | Absensi | Buka Absensi Admin Rekap | Rekap absensi |
| 6 | Absensi Approval | Buka Absensi Approval | **Bisa approve izin/sakit** |
| 7 | Payroll | Buka Payroll (jika ada akses) | Run, payslip |

### 7.2 HRL Staff

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun StaffHRL_* | Berhasil |
| 2 | HRL Docs | Buka HRL Docs | Bisa lihat dokumen |
| 3 | Reg Alkes | Buka Reg Alkes | Bisa input case |
| 4 | Absensi | Check-in, Check-out, Request izin | Berhasil |
| 5 | Approval | Coba akses Absensi Approval | 403 (Staff tidak approve) |

---

## 8. MPR (Medical Representative)

### 8.1 MPR Manager

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun MgrMPR_* | Berhasil |
| 2 | MPR Dashboard | Buka MPR Dashboard | Plans, visits, budget |
| 3 | MPR Plans | Buka MPR Plans | Buat/edit plan |
| 4 | MPR Ops Daily | Buka MPR Ops Daily FIN | Input kunjungan, bukti |
| 5 | Approval | Approve plan (jika ada) | Status berubah |
| 6 | KPI | Buka KPI Center | KPI tampil |

### 8.2 MPR Staff

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun StaffMPR_* | Berhasil |
| 2 | MPR Dashboard | Buka MPR Dashboard | Tampil |
| 3 | MPR Plans | Buka MPR Plans | Bisa buat plan |
| 4 | MPR Ops Daily | Buka MPR Ops Daily | Input kunjungan |
| 5 | KPI | Buka KPI Center | Bisa lihat KPI |

---

## 9. BRANCH (Cabang)

### 9.1 BRANCH Manager

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun Mgr*_BKS/BDG/SLO/dll | Berhasil |
| 2 | Dashboard | Buka Dashboard Center | Kartu sesuai dept cabang |
| 3 | Sales | Jika CRM cabang → Sales Dashboard | Tampil |
| 4 | WQS | Jika WQS cabang → WQS Dashboard | Tampil |
| 5 | Absensi | Absensi Check-in/out, Approval | Sesuai permission |
| 6 | KPI | KPI Center | Tampil |

### 9.2 BRANCH Staff

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun Staff*_BKS/BDG/dll | Berhasil |
| 2 | Dashboard | Buka Dashboard Center | Kartu sesuai dept |
| 3 | Modul | Akses modul sesuai dept (Sales/WQS/dll) | Sesuai permission |
| 4 | Absensi | Check-in, Check-out | Berhasil |

---

## 10. ITC (IT / Infrastructure)

### 10.1 ITC Manager & Staff

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun MgrITC_* / StaffITC_* | Berhasil |
| 2 | Master Data | Buka Master Data (jika ada akses) | Tampil |
| 3 | Tools | Buka Tools (jika ada akses) | Health, backup view |
| 4 | RBAC | Coba akses RBAC Center | 403 (hanya SYS) |
| 5 | System Login | Coba akses Master System Login | 403 (hanya SYS) |

**Catatan:** ITC default = dept biasa. Akses khusus (Tools, RBAC) hanya jika SYS assign.

---

## 11. SYS (System Admin)

### 11.1 SYS

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login | Login dengan akun SYS | Berhasil |
| 2 | All Modules | Akses semua modul | 200, tidak 403 |
| 3 | RBAC | Buka RBAC Center | Bisa kelola permission |
| 4 | System Login | Buka Master System Login | Bisa tambah/edit user |
| 5 | Tools | Buka Tools | Backup, health, QA |
| 6 | Nav Manager | Buka Nav Manager | Kelola sidebar |
| 7 | FIN Payment | Approve Payment AP | Bisa (override MgrFIN_BGR) |

---

## 12. UAT Umum (Semua Dept)

| # | Test | Langkah | Expected |
|---|------|---------|----------|
| 1 | Login/Logout | Login → Logout | Session bersih |
| 2 | Help Center | Buka Help Center | Manual, SOP tampil |
| 3 | Chat | Buka Internal Chat | Bisa kirim/baca |
| 4 | Absensi | Check-in, Check-out | Berhasil (kecuali guest) |
| 5 | KPI | Buka KPI Center | Sesuai permission |
| 6 | Menu Filter | Cek sidebar | Hanya menu yang boleh akses tampil |
| 7 | 403 Guard | Akses modul dept lain (manual URL) | 403 Forbidden |

---

## 13. Template Sign-off

| Dept | Manager | Staff | Tanggal | Catatan |
|------|---------|-------|---------|---------|
| CRM | ☐ | ☐ | | |
| PQP | ☐ | ☐ | | |
| WQS | ☐ | ☐ | | |
| SCM | ☐ | ☐ | | |
| ACT | ☐ | ☐ | | |
| FIN | ☐ | ☐ | | |
| HRL | ☐ | ☐ | | |
| MPR | ☐ | ☐ | | |
| BRANCH | ☐ | ☐ | | |
| ITC | ☐ | ☐ | | |

---

## Referensi

- **ERP Menu:** docs/ERP_MENU_WORKFLOW_REFERENCE.md
- **RBAC Matrix:** docs/governance/RBAC_MATRIX_RMI_v1.md
- **RBAC All Modules:** docs/governance/RBAC_ALL_MODULES_V1.md
- **Landing per Dept:** docs/governance/LANDING_PAGE_PER_DEPT.md
