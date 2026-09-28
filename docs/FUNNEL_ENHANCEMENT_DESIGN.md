# Rancangan Detail: Penguatan Funnel ERP_RMI_SOFULL

**Versi:** 1.0  
**Tanggal:** 2026-03-08  
**Status:** Draft Design

---

## 1. Ringkasan Eksekutif

Dokumen ini merancang penguatan fitur funnel yang sudah ada di ERP_RMI_SOFULL. Semua perubahan bersifat **read-only** (tidak mengubah proses bisnis) dan **tidak menambah input** dari user operasional.

---

## 2. Funnel yang Ada Saat Ini

| Funnel | Lokasi | Stage | Data Source |
|--------|--------|-------|-------------|
| CRM Leads | sales/sales_dashboard.php, crm_leads.php | DRAFT→SUBMITTED→APPROVED→CLOSED/CANCELLED | crm_leads (status) |
| Sales DO | sales/sales_dashboard.php | CRM→WQS→SCM→ACT→FIN | sales_do (flow_status) |
| Reg Alkes Case | hrl_reg_alkes/reg_alkes_control_tower.php | 15 stage | hrl_reg_alkes_cases (stage_no) |
| Import/PO | dashboards/owner/exec_summary.php | PO→PIB→GR→AP | purchases_po, wqs_incoming |

---

## 3. Rancangan Per Funnel

### 3.1 CRM Leads Funnel

#### 3.1.1 Conversion Rate Antar Stage

**Tujuan:** Menampilkan % lead yang berpindah dari satu stage ke stage berikutnya.

**Data yang tersedia:**
- `crm_leads`: status, submitted_at, approved_at, closed_at, cancelled_at, created_at
- Tidak ada audit log status change untuk crm_leads (hanya created_at, submitted_at, dll)

**Perhitungan:**
```
DRAFT → SUBMITTED:  (count SUBMITTED+APPROVED+CLOSED+CANCELLED) / total * 100
SUBMITTED → APPROVED: (count APPROVED+CLOSED+CANCELLED) / count(SUBMITTED+) * 100
APPROVED → CLOSED:   count CLOSED / count(APPROVED+) * 100
```

**Implementasi:**
- File: `sales/sales_dashboard.php`
- Tambah section "Conversion Rate" di card CRM Leads Funnel
- Query: `SELECT status, COUNT(*) FROM crm_leads WHERE ... GROUP BY status`
- Formula: hitung % berdasarkan urutan stage

**UI Mockup:**
```
Conversion:
  DRAFT→SUBMITTED: 45.2%
  SUBMITTED→APPROVED: 78.1%
  APPROVED→CLOSED: 62.3%
```

---

#### 3.1.2 Rata-rata Waktu per Stage (Time-in-Stage)

**Tujuan:** Mengetahui berapa hari rata-rata lead berada di tiap stage.

**Data yang tersedia:**
| Stage | Kolom "masuk" | Kolom "keluar" |
|-------|---------------|----------------|
| DRAFT | created_at | submitted_at (jika SUBMITTED+) |
| SUBMITTED | submitted_at | approved_at (jika APPROVED+) |
| APPROVED | approved_at | closed_at atau cancelled_at |
| CLOSED/CANCELLED | closed_at/cancelled_at | - |

**Perhitungan:**
- DRAFT: AVG(DATEDIFF(COALESCE(submitted_at, NOW()), created_at)) WHERE status IN ('SUBMITTED','APPROVED','CLOSED','CANCELLED')
- SUBMITTED: AVG(DATEDIFF(COALESCE(approved_at, NOW()), submitted_at)) WHERE status IN ('APPROVED','CLOSED','CANCELLED')
- APPROVED: AVG(DATEDIFF(COALESCE(closed_at, cancelled_at, NOW()), approved_at)) WHERE status IN ('CLOSED','CANCELLED')

**Implementasi:**
- File: `sales/sales_dashboard.php`
- Tambah query untuk AVG time per stage
- Tampilkan: "Avg days: DRAFT 3.2d | SUBMITTED 1.5d | APPROVED 5.1d"

**SQL Contoh:**
```sql
-- Avg days in DRAFT (untuk lead yang sudah keluar dari DRAFT)
SELECT AVG(DATEDIFF(COALESCE(submitted_at, approved_at, closed_at, cancelled_at, NOW()), created_at)) AS avg_days
FROM crm_leads
WHERE status IN ('SUBMITTED','APPROVED','CLOSED','CANCELLED')
  AND DATE(created_at) BETWEEN :df AND :dt;
```

---

### 3.2 Sales DO Funnel

#### 3.2.1 Conversion antar Stage

**Tujuan:** Melihat berapa DO yang berhasil melewati tiap stage (bottleneck detection).

**Data yang tersedia:**
- `sales_do`: flow_status (CRM, WQS, SCM, ACT, FIN)
- `sales_do`: wqs_picked_at, scm_delivered_at, act_invoiced_at, fin_paid_at
- `sales_do_audit`: status_from, status_to, created_at (untuk tracking perpindahan)

**Perhitungan:**
- Count per flow_status: sudah ada (stage_counts)
- Conversion: count(FIN dengan fin_paid_at) / count(CRM) * 100 = "DO to Payment %"
- Bottleneck: stage dengan count tertinggi + overdue tertinggi

**Implementasi:**
- File: `sales/sales_dashboard.php`
- Tambah:
  - "DO to Payment": (count flow_status=FIN AND fin_paid_at IS NOT NULL) / total_open * 100
  - "Bottleneck": stage dengan overdue terbanyak (sudah ada, bisa ditonjolkan)
  - "Avg days CRM→FIN": AVG(DATEDIFF(fin_paid_at, created_at)) untuk DO yang sudah paid

**SQL Contoh:**
```sql
-- Conversion: DO created → Paid
SELECT 
  COUNT(*) AS total_created,
  SUM(CASE WHEN fin_paid_at IS NOT NULL THEN 1 ELSE 0 END) AS total_paid
FROM sales_do
WHERE do_date BETWEEN :df AND :dt
  AND status NOT IN ('CANCELLED');
```

---

#### 3.2.2 Rata-rata Waktu per Stage (Sales DO)

**Data yang tersedia:**
- crm_created_at, crm_finish_at → CRM stage
- wqs_started_at, wqs_ready_at → WQS stage
- scm_delivered_at → SCM stage
- act_invoiced_at, act_due_date → ACT stage
- fin_paid_at → FIN stage

**Perhitungan:**
- CRM: AVG(TIMESTAMPDIFF(HOUR, crm_created_at, crm_finish_at))
- WQS: AVG(TIMESTAMPDIFF(HOUR, wqs_started_at, wqs_ready_at))
- SCM: (dari wqs_ready_at ke scm_delivered_at)
- ACT: (dari scm_delivered_at ke act_invoiced_at)
- FIN: (dari act_invoiced_at ke fin_paid_at)

**Implementasi:**
- Tambah card/section "Avg Time per Stage" di sales_dashboard
- Tampilkan dalam jam atau hari

---

### 3.3 Reg Alkes Case Funnel

#### 3.3.1 Funnel Chart (Visual)

**Tujuan:** Grafik funnel lebar per stage (stage 1 paling lebar, stage 15 paling sempit).

**Data yang tersedia:**
- `hrl_reg_alkes_cases`: stage_no (1-15), status, created_at, updated_at
- `stage_defs()`: label per stage

**Implementasi:**
- File: `hrl_reg_alkes/reg_alkes_control_tower.php` atau halaman baru
- Query: `SELECT stage_no, COUNT(*) AS cnt FROM hrl_reg_alkes_cases WHERE deleted_at IS NULL GROUP BY stage_no`
- UI: Horizontal bar chart atau CSS-based funnel (div dengan width %)

**UI Mockup:**
```
Stage 1  ████████████████████ 45
Stage 2  ██████████████ 32
Stage 3  ██████████ 22
...
Stage 15 ██ 3
```

---

#### 3.3.2 Rata-rata Waktu per Stage (Reg Alkes)

**Data yang tersedia:**
- `hrl_reg_alkes_cases`: stage_no, updated_at
- Tidak ada kolom "stage_entered_at" per stage — hanya updated_at saat stage berubah

**Keterbatasan:** Untuk time-in-stage yang akurat, idealnya ada tabel `hrl_reg_alkes_case_stage_log` (case_id, stage_no, entered_at, exited_at). **Ini butuh migration baru.**

**Opsi A (tanpa migration):** Pakai `updated_at` — kurang akurat karena updated_at bisa berubah untuk alasan lain.

**Opsi B (dengan migration):** Buat tabel stage_log, trigger atau aplikasi log setiap kali stage berubah. Effort: 2-3 hari.

**Rekomendasi:** Fase 1 cukup funnel chart (count per stage). Fase 2 tambah stage_log jika diperlukan.

---

### 3.4 Import/PO Pipeline Visual

**Tujuan:** Funnel visual PO → PIB/CEISA → GR → AP.

**Data yang tersedia:**
- `purchases_po`: status (OPEN, IN_PRODUCTION, READY, dll)
- `purchases_ceisa_pib`: po_code, status
- `wqs_incoming`: po_code (GR)
- `purchases_invoice_ap`: po_id (AP)

**Perhitungan:**
- Stage 1 (PO Open): COUNT purchases_po WHERE status IN ('OPEN','IN_PRODUCTION','READY')
- Stage 2 (PIB/CEISA): COUNT purchases_ceisa_pib atau purchases_forwarding
- Stage 3 (GR): COUNT wqs_incoming
- Stage 4 (AP): COUNT purchases_invoice_ap

**Implementasi:**
- File: `dashboards/owner/exec_summary.php` atau `purchases/purchases_import_control_tower.php`
- Tambah section "Import Pipeline Funnel" dengan 4 bar

---

## 4. Dashboard Funnel Terpusat

### 4.1 Konsep

Halaman baru `dashboards/funnels.php` yang menampilkan semua funnel dalam satu view.

### 4.2 Struktur Halaman

```
┌─────────────────────────────────────────────────────────────┐
│  Funnel Overview                    [Filter: Date Range]    │
├─────────────────────────────────────────────────────────────┤
│  CRM Leads          │  DRAFT 12 → SUBMITTED 8 → APPROVED 5  │
│  Conversion 42%     │  → CLOSED 3 | Avg 4.2d/stage          │
├─────────────────────────────────────────────────────────────┤
│  Sales DO           │  CRM 45 → WQS 32 → SCM 28 → ACT 20   │
│  Conversion 35%     │  → FIN 15 (Paid 12) | 3 overdue      │
├─────────────────────────────────────────────────────────────┤
│  Reg Alkes Case     │  [Funnel Chart 1-15]                  │
│  87 cases total     │  Top: Stage 3 (22), Stage 5 (18)      │
├─────────────────────────────────────────────────────────────┤
│  Import/PO           │  PO 120 → PIB 85 → GR 60 → AP 45     │
│  Value: Rp 2.1B      │  Ready late: 8                        │
└─────────────────────────────────────────────────────────────┘
```

### 4.3 Implementasi

- File baru: `dashboards/funnels.php`
- Reuse query logic dari sales_dashboard, exec_summary, reg_alkes_control_tower
- RBAC: require_any_permission(['DASHBOARD.SALES_VIEW', 'DASHBOARD.OWNER', 'SALES.VIEW'])
- Link dari: master_data.php, sales index, exec_summary

---

## 5. API untuk BI (Opsional)

### 5.1 Endpoint

```
GET /api/v1/internal/funnels_summary.php
  ?date_from=2026-01-01
  &date_to=2026-03-08
  &funnel=crm_leads|sales_do|reg_alkes|import
```

### 5.2 Response Format

```json
{
  "ok": true,
  "funnels": {
    "crm_leads": {
      "stages": {"DRAFT": 12, "SUBMITTED": 8, "APPROVED": 5, "CLOSED": 3, "CANCELLED": 2},
      "conversion_rates": {"DRAFT_to_SUBMITTED": 45.2, "SUBMITTED_to_APPROVED": 78.1},
      "avg_days_per_stage": {"DRAFT": 3.2, "SUBMITTED": 1.5}
    },
    "sales_do": { ... }
  }
}
```

### 5.3 Auth

- Internal API: X-API-Key atau session
- Scope: read-only

---

## 6. Rencana Implementasi (Phasing)

### Fase 1 — Quick Wins (3-4 hari)

| # | Item | File | Effort |
|---|------|------|--------|
| 1 | CRM Leads: Conversion rate antar stage | sales/sales_dashboard.php | 0.5 hari |
| 2 | CRM Leads: Avg time per stage | sales/sales_dashboard.php | 0.5 hari |
| 3 | Sales DO: DO-to-Payment % + bottleneck highlight | sales/sales_dashboard.php | 0.5 hari |
| 4 | Reg Alkes: Funnel chart (count per stage) | hrl_reg_alkes/reg_alkes_control_tower.php | 1 hari |

### Fase 2 — Dashboard Terpusat (2-3 hari)

| # | Item | File | Effort |
|---|------|------|--------|
| 5 | Buat dashboards/funnels.php | Baru | 2 hari |
| 6 | Import/PO funnel visual di exec_summary | dashboards/owner/exec_summary.php | 0.5 hari |

### Fase 3 — Opsional (2-3 hari)

| # | Item | File | Effort |
|---|------|------|--------|
| 7 | Sales DO: Avg time per stage | sales/sales_dashboard.php | 1 hari |
| 8 | API funnels_summary.php | api/v1/internal/ | 1 hari |
| 9 | Reg Alkes: stage_log + time-in-stage (butuh migration) | hrl_reg_alkes | 2 hari |

---

## 7. Migration (Fase 3 — DIIMPLEMENTASI)

**147_hrl_reg_alkes_case_stage_log.sql** — Implementasi selesai.

- **File:** `sql/migrations/147_hrl_reg_alkes_case_stage_log.sql`
- **Runner:** `php tools/run_migration_147.php`
- **Helper:** `hrl_reg_alkes/_stage_log_helper.php` — `reg_alkes_stage_log_enter()`, `reg_alkes_stage_log_close_current()`
- **Logging:** otomatis di create_case, quick_next, quick_close, quick_reopen (control_tower); update_case, next_stage, close_case, reopen_case (reg_alkes_case)
- **Tidak mengubah flow user** — hanya logging di belakang layar

---

## 8. Dependensi & Risiko

| Item | Dependensi | Risiko |
|------|------------|--------|
| CRM conversion | crm_leads (submitted_at, approved_at, closed_at) | Rendah — kolom sudah ada |
| Sales DO conversion | sales_do (flow_status, fin_paid_at) | Rendah — kolom sudah ada |
| Reg Alkes funnel chart | hrl_reg_alkes_cases (stage_no) | Rendah |
| Dashboard terpusat | Semua modul di atas | Rendah |
| stage_log | Migration baru | Sedang — perlu update reg_alkes_case.php |

---

## 9. Verifikasi

Setelah implementasi:
1. Buka sales_dashboard.php → CRM Leads Funnel card menampilkan conversion + avg days
2. Buka sales_dashboard.php → Sales DO cards menampilkan DO-to-Payment %
3. Buka reg_alkes_control_tower.php → Ada funnel chart
4. Buka dashboards/funnels.php → Semua funnel tampil
5. Buka exec_summary.php → Tile Import/PO menampilkan funnel PO→PIB→GR→AP
6. API: GET /api/v1/internal/funnels_summary.php?date_from=...&date_to=...&funnel=crm_leads,sales_do,reg_alkes,import
7. Tidak ada perubahan pada form/input user
8. Query tidak membebani DB (pakai index yang ada)

---

## 10. Lampiran: Query Reference

### CRM Leads — Status Count
```sql
SELECT status, COUNT(*) cnt FROM crm_leads
WHERE DATE(created_at) BETWEEN :df AND :dt
GROUP BY status;
```

### CRM Leads — Avg Days DRAFT
```sql
SELECT AVG(DATEDIFF(COALESCE(submitted_at, NOW()), created_at)) AS avg_days
FROM crm_leads
WHERE status IN ('SUBMITTED','APPROVED','CLOSED','CANCELLED')
  AND submitted_at IS NOT NULL
  AND DATE(created_at) BETWEEN :df AND :dt;
```

### Sales DO — Stage Count
```sql
SELECT flow_status AS stg, COUNT(*) AS cnt FROM sales_do
WHERE do_date BETWEEN :df AND :dt AND status NOT IN ('CANCELLED')
GROUP BY flow_status;
```

### Reg Alkes — Stage Count
```sql
SELECT stage_no, COUNT(*) AS cnt FROM hrl_reg_alkes_cases
WHERE (closed_at IS NULL OR status = 'OPEN')
GROUP BY stage_no
ORDER BY stage_no;
```
