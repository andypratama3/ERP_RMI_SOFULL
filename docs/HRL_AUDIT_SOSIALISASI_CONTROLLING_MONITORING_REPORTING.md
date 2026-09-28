# Audit HRL — Sosialisasi, Controlling, Monitoring & Reporting

**Tanggal:** 2026-03-09  
**Tujuan:** Cek fitur HRL yang sudah ada di ERP agar tidak duplikat saat pengembangan "terima beres"

---

## 1. Ringkasan Kebutuhan

| Fungsi | Deskripsi | Terbaca di ERP |
|--------|-----------|----------------|
| **Sosialisasi** | Materi, program, pelaksanaan sosialisasi ke karyawan | ? |
| **Controlling** | Pengendalian proses & kepatuhan HRL | ? |
| **Monitoring** | Pemantauan berjalan (progress, KPI, status) | ? |
| **Reporting** | Laporan yang bisa dibaca/diakses di ERP | ? |

---

## 2. Fitur HRL yang Sudah Ada

### 2.1 Modul HRL (`/hrl/`)

| File | Fungsi | Status |
|------|--------|--------|
| `hrl_docs.php` | Manajemen dokumen HRL (SOP, Form, Policy, dll) | ✅ Ada |
| `hrl_doc_view.php` | View & download dokumen | ✅ Ada |
| `hrl_doc_download.php` | Download dokumen | ✅ Ada |
| `hrl_ack_report.php` | **Reporting** — Acknowledgement per dokumen (ack count, last ack) | ✅ Ada |
| `hrl_tower.php` | Tower / overview | ✅ Ada |
| `index.php` | Redirect ke hrl_docs | ✅ Ada |

**Tabel:** `hrl_docs`, `hrl_doc_acks`, `hrl_doc_versions`

### 2.2 Modul HRL Process (`/hrl_process/`)

| File | Fungsi | Status |
|------|--------|--------|
| `tower.php` | Control tower proses | ✅ Ada |
| `request_view.php` | View request | ✅ Ada |
| `request_print.php` | Print request | ✅ Ada |
| `index.php` | Redirect ke tower | ✅ Ada |

### 2.3 Modul HRL Reg Alkes (`/hrl_reg_alkes/`)

| File | Fungsi | Status |
|------|--------|--------|
| `reg_alkes_control_tower.php` | **Controlling + Monitoring** — Control tower registrasi alkes (Step 1–15, case, SKU) | ✅ Ada |
| `reg_alkes_case.php` | Case registrasi | ✅ Ada |
| `reg_alkes.php` | Daftar reg alkes | ✅ Ada |
| `reg_alkes_sku_by_nie.php` | SKU by NIE | ✅ Ada |
| `reg_alkes_expiry_check.php` | Cek expiry | ✅ Ada |
| `reg_alkes_export_compliance.php` | Export compliance | ✅ Ada |

**Tabel:** `hrl_reg_alkes_cases`, `hrl_reg_alkes_case_docs`

### 2.4 Dashboard HRL (`/dashboards/hrl/`)

| File | Fungsi | Status |
|------|--------|--------|
| `hrl_dashboard.php` | Dashboard utama — KPI (docs total, absensi hari ini, karyawan aktif), Quick Links | ✅ Ada |

**KPI yang ditampilkan:**
- Total dokumen HRL
- Absensi hari ini
- Karyawan aktif

### 2.5 Modul Terkait

| Modul | Path | Keterangan |
|-------|------|------------|
| Absensi | `/absensi/` | Absensi karyawan |
| Payroll | `/payroll/` | Penggajian |
| KPI Center | `/kpi/kpi_center.php` | KPI umum (DO SLA, Office, dll) — bukan HRL-specific |
| Master Karyawan | `/master/master_employees.php` | Data karyawan |

### 2.6 Dokumen & Upload

| Lokasi | Keterangan |
|--------|------------|
| `uploads/hrl/docs/` | SOP, Form, Checklist (HRL_LEGAL_*, HRL_HR_*, HRL_IT_*) |
| `docs/officepack/` | Office pack, training, SOP index |
| `docs/officepack/register/HRL_Docs_Register_FinalOfficePack_v3.0.csv` | Register dokumen HRL |

---

## 3. Gap Analysis — Sosialisasi, Controlling, Monitoring, Reporting

### 3.1 Sosialisasi

| Kebutuhan | Sudah Ada? | Lokasi | Keterangan |
|-----------|------------|--------|------------|
| Materi sosialisasi | ⚠️ Sebagian | `ROLLOUT_24H_SOCIALIZATION_PLAN.md`, `docs/officepack/` | Dokumen ada, tapi tidak terstruktur di ERP sebagai "program sosialisasi" |
| Daftar program sosialisasi | ❌ | - | Tidak ada modul khusus "Sosialisasi" |
| Tracking peserta sosialisasi | ❌ | - | Tidak ada |
| Jadwal & status sosialisasi | ❌ | - | Tidak ada |

**Gap:** Tidak ada modul **Sosialisasi** terpusat di ERP. Materi tersebar di docs/officepack, register CSV, dan ROLLOUT plan.

### 3.2 Controlling

| Kebutuhan | Sudah Ada? | Lokasi | Keterangan |
|-----------|------------|--------|------------|
| Control tower Reg Alkes | ✅ | `hrl_reg_alkes/reg_alkes_control_tower.php` | Step 1–15, case, SKU |
| Control tower HRL Process | ✅ | `hrl_process/tower.php` | Request, proses |
| Pengendalian kepatuhan dokumen | ⚠️ Sebagian | `hrl_ack_report.php` | Ack per dokumen, bukan kontrol kepatuhan menyeluruh |

**Gap:** Controlling Reg Alkes & Process sudah ada. Belum ada controlling terpusat untuk "Sosialisasi" atau "Kepatuhan HRL menyeluruh".

### 3.3 Monitoring

| Kebutuhan | Sudah Ada? | Lokasi | Keterangan |
|-----------|------------|--------|------------|
| Monitoring Reg Alkes | ✅ | `reg_alkes_control_tower.php`, `reg_alkes_expiry_check.php` | Case, SLA, expiry |
| Monitoring ack dokumen | ✅ | `hrl_ack_report.php` | Ack count, last ack |
| Monitoring KPI HRL | ⚠️ Sebagian | `dashboards/hrl/hrl_dashboard.php` | Docs, absensi, karyawan — terbatas |
| Monitoring sosialisasi | ❌ | - | Tidak ada |

**Gap:** Monitoring Reg Alkes & ack ada. Monitoring sosialisasi & KPI HRL menyeluruh belum.

### 3.4 Reporting

| Kebutuhan | Sudah Ada? | Lokasi | Keterangan |
|-----------|------------|--------|------------|
| Acknowledgement Report | ✅ | `hrl_ack_report.php` | Per dokumen, filter HR/Legal |
| Reg Alkes export compliance | ✅ | `reg_alkes_export_compliance.php` | Export data compliance |
| Laporan sosialisasi | ❌ | - | Tidak ada |
| Laporan kepatuhan HRL | ⚠️ Sebagian | `hrl_ack_report` | Ack saja, bukan laporan kepatuhan lengkap |

**Gap:** Reporting ack & Reg Alkes ada. Laporan sosialisasi & kepatuhan HRL menyeluruh belum.

---

## 4. Tabel Ringkasan — Sudah vs Belum

| Fungsi | Sudah Ada | Belum Ada |
|--------|-----------|-----------|
| **Sosialisasi** | Materi di docs/officepack, ROLLOUT plan | Modul program sosialisasi, tracking peserta, jadwal |
| **Controlling** | Reg Alkes control tower, HRL Process tower | Controlling sosialisasi, controlling kepatuhan terpusat |
| **Monitoring** | Reg Alkes, ack, KPI dasar (docs/absensi/karyawan) | Monitoring sosialisasi, KPI HRL menyeluruh |
| **Reporting** | Ack report, Reg Alkes export | Laporan sosialisasi, laporan kepatuhan HRL |

---

## 5. Rekomendasi — Hindari Duplikat

1. **Sosialisasi:** Bangun modul baru (mis. `hrl_sosialisasi/`) — tidak ada yang overlap.
2. **Controlling:** Perluas `hrl_tower.php` atau buat dashboard terpusat yang agregasi Reg Alkes + Process + (nanti) Sosialisasi.
3. **Monitoring:** Perluas `dashboards/hrl/hrl_dashboard.php` — tambah widget monitoring sosialisasi & KPI HRL.
4. **Reporting:** Perluas `hrl_ack_report.php` atau buat `hrl_report_center.php` — agregasi ack + Reg Alkes + (nanti) sosialisasi.

---

## 6. File & Path Referensi

```
hrl/
├── index.php
├── hrl_docs.php          # CRUD dokumen
├── hrl_doc_view.php
├── hrl_doc_download.php
├── hrl_ack_report.php    # Reporting ack
├── hrl_tower.php
└── _inc/

hrl_process/
├── index.php
├── tower.php             # Control tower
├── request_view.php
└── request_print.php

hrl_reg_alkes/
├── reg_alkes_control_tower.php  # Controlling + Monitoring
├── reg_alkes_case.php
├── reg_alkes.php
├── reg_alkes_expiry_check.php
└── reg_alkes_export_compliance.php

dashboards/hrl/
└── hrl_dashboard.php     # KPI dasar
```

---

## 7. Revisi

| Versi | Tanggal | Perubahan |
|-------|---------|-----------|
| 1.0 | 2026-03-09 | Audit awal |
