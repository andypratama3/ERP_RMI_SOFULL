# Branch — Kantor Cabang

## Ringkasan alur

User **dept BRANCH** memakai **menu disederhanakan**: dashboard cabang, **DO**, **WQS** (picking / task terkait), **absensi**, **HRL process** sesuai akses.

## Halaman utama

| Path | Fungsi | Role / dept tipikal |
|------|--------|---------------------|
| `dashboards/branch/branch_dashboard.php` | Home cabang | BRANCH |
| `sales/sales_do.php` | Delivery order | BRANCH |
| `sales/sales_control_tower.php` | Control tower (read) | BRANCH |
| `stock/wqs_picking.php` | Picking | BRANCH |
| `stock/wqs_do_tasks.php` | Task DO (WQS) | BRANCH |
| `sales/scm_do_tasks.php` | Task SCM | BRANCH |
| `absensi/index.php` | Absensi | BRANCH |
| `hrl_process/tower.php` | Proses HRL (jika permission) | BRANCH |

## Monitoring

- **Sales Control Tower** — visibilitas status DO cabang.
- **Playbook cabang** — Help Center → dokumen BRANCH (real office).

## Deep-dive

- `docs/governance/LANDING_PAGE_PER_DEPT.md`, `docs/DASHBOARD_LANDING_PAGES.md`.
- Office code & stok mengikuti kantor user (bukan HO).
