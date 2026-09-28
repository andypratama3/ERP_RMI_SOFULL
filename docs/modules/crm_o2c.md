# CRM & Sales — Order to Cash (O2C)

## Ringkasan alur

**CRM** membuat/mengelola **Delivery Order (DO)** → **WQS** cek stok & readiness → **SCM** pengiriman → **ACT** delivered → **FIN** pembayaran/piutang. Monitoring end-to-end di dashboard & Control Tower.

## Halaman utama

| Path | Fungsi | Role / dept tipikal |
|------|--------|---------------------|
| `sales/sales_dashboard.php` | Ringkasan O2C, funnel, beban kerja | CRM, Manager |
| `sales/sales_control_tower.php` | Status DO per tahap, drill-down | CRM, SCM, WQS, FIN |
| `sales/sales_do.php` | Buat / kelola DO | CRM |
| `stock/wqs_do_tasks.php` | Task WQS (stok, READY SCM) | WQS |
| `sales/scm_do_tasks.php` | Task SCM (pengiriman) | SCM |
| `sales/act_do_tasks.php` | Task ACT (delivered) | ACT |
| `sales/fin_do_tasks.php` | Task FIN (AR / payment) | FIN |
| `sales/kpi_do_audit.php` | Audit perubahan status DO | Manager, SYS |
| `sales/kpi_do_sla.php` | SLA per stage DO | Manager, SYS |

## Monitoring & audit

- **Sales Control Tower** — prioritas untuk controlling harian.
- **Audit Log** (`master/audit_logs.php`) — filter `module` terkait sales/auth bila tersedia.
- **KPI DO** — kebijakan SLA: lihat `docs/KPI_SYS_GOVERNANCE.md` (mutasi SYS).

## Deep-dive (halaman kritis)

- Customer **portal** B2B: `docs/SOP_CUSTOMER_PORTAL.md`, `docs/RANCANGAN_CUSTOMER_PORTAL.md`.
- **API partner** order: `docs/PERSIAPAN_H2H_PO_HERMINA.md` (bila dipakai).
