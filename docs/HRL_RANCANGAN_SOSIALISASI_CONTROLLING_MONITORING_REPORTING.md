# Rancangan HRL — Sosialisasi, Controlling, Monitoring & Reporting

**Tanggal:** 2026-03-09  
**Status:** Rancangan (belum implementasi)

---

## 1. Sosialisasi — Tambah & Rapikan Dokumen HRL

### 1.1 Tujuan

- Menambah dokumen HRL yang belum ada
- Merapikan struktur folder, register, penamaan

### 1.2 Struktur Folder (Usulan)

```
docs/officepack/
├── INDEX_v3.1.html                    # Index utama (update link)
├── dept_training/                     # Manual, SOP training per dept
│   ├── Panduan_Cepat_ERP_v1.0.html
│   ├── CRM_Manual_v3.0.html
│   ├── WQS_Manual_v3.0.html
│   ├── SOP_PQP_Dokumen_Manufacturer_v1.0.md   # (sudah ada)
│   ├── HRL_Manual_v3.0.html
│   └── ... (sesuai register)
├── branch_pack/                       # Playbook cabang
├── policies/                          # Policy (DoA, SoD)
├── checklists/                        # Checklist (Implementasi, GoLive, dll)
├── sosialisasi/                       # [BARU] Materi sosialisasi terpusat
│   ├── ROLLOUT_24H_SOCIALIZATION_PLAN.md
│   ├── ROLLOUT_COMMUNICATION_TEMPLATES.md
│   └── ERP_DAY1_FAQ_TROUBLESHOOT.md
└── register/
    └── HRL_Docs_Register_FinalOfficePack_v3.0.csv

uploads/hrl/docs/                      # Upload dokumen HRL (SOP, Form)
├── HRL_LEGAL_*/                       # Per category
├── HRL_HR_*/
└── HRL_IT_*/
```

### 1.3 Penamaan Dokumen (Konvensi)

| Format | Contoh | Keterangan |
|--------|--------|------------|
| `HRL-{UNIT}-{CAT}-{KODE}-{NO}` | HRL-HR-SOP-ERP-001 | doc_code di hrl_docs |
| `{Kategori}_{Judul}_v{versi}.{ext}` | SOP_Reg_Alkes_Proses_v1.0.html | File di officepack |
| `SOP_{Modul}_{Judul}_v{versi}` | SOP_PQP_Dokumen_Manufacturer_v1.0 | SOP |

### 1.4 Dokumen yang Perlu Ditambah (Gap dari Register)

| No | doc_code | Judul | Status |
|----|----------|-------|--------|
| 1 | HRL-HR-SOP-ERP-SOSIALISASI-001 | SOP Sosialisasi ERP (Rollout) | Belum ada |
| 2 | HRL-HR-FORM-ERP-ACK-001 | Form Acknowledgement Dokumen | Sudah ada flow di hrl_doc_view |
| 3 | HRL-HR-CHK-ERP-SOSIALISASI-001 | Checklist Sosialisasi per Dept | Belum ada |

### 1.5 Register CSV — Update

- **File:** `docs/officepack/register/HRL_Docs_Register_FinalOfficePack_v3.0.csv`
- **Aksi:** Tambah baris untuk dokumen baru (SOP Sosialisasi, Checklist Sosialisasi)
- **Sync:** Pastikan `hrl_docs` punya record yang match (bisa via seed/migration)

### 1.6 Langkah Implementasi

1. Buat folder `docs/officepack/sosialisasi/`
2. Pindahkan/relokasi `ROLLOUT_24H_SOCIALIZATION_PLAN.md` ke folder sosialisasi
3. Tambah SOP Sosialisasi ERP (ringkas dari ROLLOUT plan)
4. Tambah Checklist Sosialisasi per Dept
5. Update `HRL_Docs_Register_FinalOfficePack_v3.0.csv`
6. Update `docs/officepack/INDEX_v3.1.html` — tambah link ke materi sosialisasi
7. Seed ke `hrl_docs` jika ada dokumen baru

---

## 2. Controlling — Perluas Tower yang Ada

### 2.1 Tower yang Ada

| Tower | Path | Isi |
|-------|------|-----|
| HRL Tower | `hrl/hrl_tower.php` | Pengajuan dokumen, approve/reject, set active |
| HRL Process Tower | `hrl_process/tower.php` | Request proses |
| Reg Alkes Control Tower | `hrl_reg_alkes/reg_alkes_control_tower.php` | Step 1–15, case, SKU |

### 2.2 Perluasan yang Diusulkan

**Buat HRL Control Center (aggregasi):**

- **File baru:** `hrl/hr_control_center.php` atau `dashboards/hrl/hr_control_center.php`
- **Isi:** Satu halaman dashboard yang menampilkan:
  - Card/link ke HRL Tower (pengajuan dokumen)
  - Card/link ke HRL Process Tower
  - Card/link ke Reg Alkes Control Tower
  - Ringkasan: jumlah pending approval, jumlah case aktif Reg Alkes, dll

**Alternatif (lebih ringan):** Perluas `hrl_tower.php` atau `hrl_dashboard.php` dengan section "Control Center" yang berisi quick links + ringkasan angka.

### 2.3 Skema Ringkas

```
hrl_dashboard.php (atau hr_control_center.php)
├── Section: Dokumen
│   ├── Link ke hrl_docs
│   └── Link ke hrl_tower (pengajuan)
├── Section: Process
│   └── Link ke hrl_process/tower
├── Section: Reg Alkes
│   └── Link ke reg_alkes_control_tower
└── KPI: Pending approval, Case aktif, dll
```

### 2.4 Langkah Implementasi

1. Tambah section "Control Center" di `dashboards/hrl/hrl_dashboard.php`
2. Atau buat `hrl/hr_control_center.php` yang berisi agregasi link + KPI
3. Query: `hrl_docs` (status DRAFT/SUBMITTED), `hrl_reg_alkes_cases` (status open), dll
4. Tambah link di Quick Links dashboard

---

## 3. Monitoring — Perluas hrl_dashboard.php

### 3.1 KPI yang Sudah Ada

- Total dokumen HRL
- Absensi hari ini
- Karyawan aktif

### 3.2 KPI yang Ditambah

| KPI | Sumber | Query/Logic |
|-----|--------|-------------|
| Dokumen pending approval | hrl_docs / hrl_doc_versions | status IN ('DRAFT','SUBMITTED') |
| Ack tertinggal | hrl_doc_acks | Dokumen ACTIVE belum di-ack oleh user tertentu |
| Case Reg Alkes aktif | hrl_reg_alkes_cases | status open |
| Case Reg Alkes overdue | hrl_reg_alkes_cases | SLA/expiry terlewati |

### 3.3 Widget yang Ditambah

| Widget | Deskripsi |
|--------|-----------|
| Pending Approval | Jumlah dokumen menunggu approval |
| Reg Alkes Open | Jumlah case registrasi alkes aktif |
| Ack Compliance | % karyawan yang sudah ack dokumen wajib (jika ada data) |

### 3.4 Layout Usulan

```
Dashboard HRL
├── Row 1: KPI (existing + baru)
│   ├── Dokumen HRL
│   ├── Absensi Hari Ini
│   ├── Karyawan Aktif
│   ├── Pending Approval
│   └── Reg Alkes Open
├── Row 2: Control Center (quick links)
└── Row 3: Alert (opsional) — dokumen overdue, case overdue
```

### 3.5 Langkah Implementasi

1. Tambah query untuk `pending_approval`, `reg_alkes_open` di `hrl_dashboard.php`
2. Tambah card KPI baru di section kpi
3. Tambah section Control Center (link ke tower, process, reg alkes)
4. Opsional: Tambah alert jika ada overdue

---

## 4. Reporting — Perluas hrl_ack_report atau buat Report Center

### 4.1 Report yang Sudah Ada

### 4.1 Report yang Sudah Ada

| Report | Path | Isi |
|--------|------|-----|
| Acknowledgement Report | `hrl_ack_report.php` | Ack per dokumen, filter HR/Legal |
| Reg Alkes Export | `reg_alkes_export_compliance.php` | Export compliance |

### 4.2 Perluasan yang Diusulkan

**Opsi A: Perluas hrl_ack_report.php**

- Tambah filter: date range, department, office
- Tambah export: Excel, CSV
- Tambah summary: % dokumen ter-ack, daftar user belum ack

**Opsi B: Buat hrl_report_center.php**

- **Path:** `hrl/hr_report_center.php`
- **Isi:** Halaman agregasi report
  - Link ke Acknowledgement Report
  - Link ke Reg Alkes Export
  - Tambah: Report Kepatuhan Dokumen (ringkasan ack per dept)
  - Tambah: Report Reg Alkes (case per status, SLA)

### 4.3 Skema Report Center

```
hrl_report_center.php
├── Report 1: Acknowledgement (link ke hrl_ack_report)
├── Report 2: Reg Alkes Compliance (link ke reg_alkes_export_compliance)
├── Report 3: Kepatuhan per Dept (baru)
│   └── Query: hrl_doc_acks + master_system_login (department)
│   └── Tampilkan: % ack per dept per dokumen
└── Report 4: Reg Alkes Summary (baru)
    └── Query: hrl_reg_alkes_cases
    └── Tampilkan: Case per status, per step
```

### 4.4 Langkah Implementasi

1. Buat `hrl/hr_report_center.php` — halaman index report
2. Tambah link ke report yang sudah ada
3. Tambah Report Kepatuhan per Dept (query hrl_doc_acks + master)
4. Tambah Report Reg Alkes Summary (query hrl_reg_alkes_cases)
5. Perluas hrl_ack_report: filter date, export Excel/CSV
6. Tambah link Report Center di dashboard & Quick Links

---

## 5. Ringkasan Prioritas

| No | Area | Prioritas | Effort | Langkah Utama |
|----|------|------------|--------|---------------|
| 1 | Sosialisasi | Tinggi | Sedang | Folder, register, penamaan, dokumen baru |
| 2 | Controlling | Sedang | Rendah | Section Control Center di dashboard |
| 3 | Monitoring | Sedang | Sedang | KPI baru di hrl_dashboard |
| 4 | Reporting | Sedang | Sedang | Report center + report baru |

---

## 6. File yang Akan Dibuat/Diubah

| File | Aksi |
|------|------|
| `docs/officepack/sosialisasi/` | Buat folder |
| `docs/officepack/register/HRL_Docs_Register_FinalOfficePack_v3.0.csv` | Update |
| `docs/officepack/INDEX_v3.1.html` | Update link |
| `dashboards/hrl/hrl_dashboard.php` | Tambah KPI, Control Center |
| `hrl/hr_report_center.php` | Buat baru |
| `hrl/hr_control_center.php` | Opsional (bisa digabung ke dashboard) |

---

## 7. Revisi

| Versi | Tanggal | Perubahan |
|-------|---------|-----------|
| 1.0 | 2026-03-09 | Rancangan awal |
