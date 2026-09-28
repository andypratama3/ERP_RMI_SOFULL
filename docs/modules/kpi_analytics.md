# KPI & Analytics

## Ringkasan alur

**KPI Center** mengagregasi metrik operasional (sales, purchases, stock, dll.) dengan **snapshot** harian/bulanan. **SLA DO** dan kebijakan KPI sensitif diatur level **SYS**.

## Halaman utama

| Path | Fungsi | Role / dept tipikal |
|------|--------|---------------------|
| `kpi/kpi_center.php` | Pusat KPI & navigasi metrik | Semua (VIEW), SYS (mutasi) |
| `kpi/kpi_dashboard_daily.php` | Snapshot harian | Manager |
| `kpi/kpi_dashboard_monthly.php` | Snapshot bulanan | Manager |
| `kpi/kpi_purchases.php` | KPI pembelian | PQP, FIN, SYS |
| `kpi/kpi_stock.php` | KPI stok | WQS, SYS |
| `kpi/kpi_do_sla.php` | SLA per tahap DO | Manager, SYS |
| `dashboards/owner/exec_summary.php` | Ringkasan eksekutif | Owner, SYS |
| `dashboards/funnels.php` | Funnel lintas modul | Manager |

## Monitoring

- **Exec Summary** — tile import/O2C ringkas.
- **Monitoring Center** (`master/monitoring_center.php`) — pintasan ke KPI & audit.

## Deep-dive (wajib baca untuk admin kebijakan)

- `docs/KPI_SYS_GOVERNANCE.md` — dua lapis RBAC vs **SYS** untuk mutasi KPI & SLA DO.
- Review berkala permission: `docs/RBAC_PERIODIC_REVIEW.md`.
