# Verifikasi Funnel — Checklist

Checklist untuk memverifikasi funnel enhancement (CRM Leads, Sales DO, Reg Alkes, Import/PO) setelah deployment.

## Prasyarat

**CLI di NAS:** Gunakan `./tools/nas/erp.sh php tools/xxx.php` (bukan `php` langsung) — PHP default NAS tidak punya pdo_mysql. Lihat `docs/PHP_CLI_PDO_MYSQL_SYNOLOGY.md`.

1. **Migration 147** (Reg Alkes stage log) sudah dijalankan:
   ```bash
   cd /volume4/web/ERP_RMI_SOFULL
   ./tools/nas/erp.sh php tools/run_migration_147.php
   ```
   Atau jalankan SQL: `sql/migrations/147_hrl_reg_alkes_case_stage_log.sql`

2. **Migration 149** (Vendor index multi-cabang) — opsional, untuk Master Vendors:
   ```bash
   ./tools/nas/erp.sh php tools/run_migration_149.php
   ```

---

## 1. Funnel Overview (`dashboards/funnels.php`)

**URL:** `{APP_URL}/dashboards/funnels.php`

| Cek | Harapan |
|-----|---------|
| Halaman load tanpa error | ✓ |
| Filter date_from / date_to berfungsi | ✓ |
| **CRM Leads** card: total, DRAFT/SUBMITTED/APPROVED/CLOSED/CANCELLED | ✓ |
| **Sales DO** card: CRM→WQS→SCM→ACT→FIN, total, paid, % to payment | ✓ |
| **Reg Alkes Case** card: Stage 1–15 count, link ke Control Tower | ✓ |
| **Reg Alkes avg days** (jika ada data): S1:Xd \| S2:Yd \| ... | ✓ (setelah case pindah stage) |
| **Import/PO Pipeline** card: PO → PIB → GR → AP | ✓ |
| Tombol: CRM Dashboard, Reg Alkes Tower, Exec Summary | ✓ |

**Catatan:** Avg days Reg Alkes hanya tampil jika:
- Tabel `hrl_reg_alkes_case_stage_log` ada (migration 147)
- Ada case yang sudah pindah stage (exited_at terisi)

---

## 2. Sales Dashboard (`sales/sales_dashboard.php`)

**URL:** `{APP_URL}/sales/sales_dashboard.php`

| Cek | Harapan |
|-----|---------|
| Filter date_from, date_to, department, status | ✓ |
| **Stage cards** (CRM, WQS, SCM, ACT, FIN): count, overdue, bottleneck badge | ✓ |
| **Sales DO Summary**: total DO, paid, % to payment | ✓ |
| **CRM Leads Funnel**: DRAFT→SUBMITTED→APPROVED→CLOSED, close rate | ✓ |
| **Conversion rate**: DRAFT→SUBM, SUBM→APPR, APPR→CLOSED | ✓ |
| **Avg days per stage** (CRM Leads): DRAFT, SUBM, APPR | ✓ |

---

## 3. Reg Alkes Control Tower (`hrl_reg_alkes/reg_alkes_control_tower.php`)

**URL:** `{APP_URL}/hrl_reg_alkes/reg_alkes_control_tower.php`

| Cek | Harapan |
|-----|---------|
| Funnel bar chart: Stage 1–15 dengan count | ✓ |
| **Avg days per stage** (Xd) di samping setiap bar | ✓ (jika ada case yang sudah pindah) |
| Filter status (ALL, OPEN, CLOSED) | ✓ |
| Tabel case list | ✓ |

**Catatan:** Avg days (Xd) tampil per stage jika ada record di `hrl_reg_alkes_case_stage_log` dengan `exited_at IS NOT NULL` untuk stage tersebut.

---

## 4. Exec Summary (`dashboards/owner/exec_summary.php`)

**URL:** `{APP_URL}/dashboards/owner/exec_summary.php` (perlu permission DASHBOARD.OWNER_VIEW)

| Cek | Harapan |
|-----|---------|
| Import/PO funnel visual | ✓ |
| Link ke Import Tower | ✓ |

---

## 5. API Funnels (`api/v1/internal/funnels_summary.php`)

**URL:** `{APP_URL}/api/v1/internal/funnels_summary.php?date_from=2025-01-01&date_to=2025-12-31`

| Cek | Harapan |
|-----|---------|
| Response JSON valid | ✓ |
| Keys: crm_leads, sales_do, reg_alkes, import_po | ✓ |
| reg_alkes.avg_days_per_stage (jika ada data) | ✓ |

---

## Troubleshooting

### Avg days Reg Alkes kosong
- Pastikan migration 147 sudah dijalankan
- Stage log terisi saat case pindah stage (quick_next, edit stage di reg_alkes_case.php)
- Cek: `SELECT COUNT(*) FROM hrl_reg_alkes_case_stage_log` — harus > 0
- Avg days hanya dari record dengan `exited_at IS NOT NULL` (stage sudah selesai)

### Funnel Overview 404 / blank
- Cek permission: DASHBOARD.VIEW atau DASHBOARD.SALES_VIEW atau DASHBOARD.OWNER_VIEW
- Cek bootstrap: `_dashboard_bootstrap.php` loaded

### Sales Dashboard angka 0
- Cek filter date range
- Cek scope department (non-admin mungkin terfilter)
