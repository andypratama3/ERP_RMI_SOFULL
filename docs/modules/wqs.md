# WQS — Gudang & Stok

## Ringkasan alur

**WQS** mengelola **stok fisik**, **incoming**, **alokasi** ke DO, **picking**, **PR** ke pembelian, serta **penyesuaian** dengan jejak audit.

## Halaman utama

| Path | Fungsi | Role / dept tipikal |
|------|--------|---------------------|
| `dashboards/warehouse/wqs_dashboard.php` | Dashboard gudang | WQS |
| `stock/wqs_stock.php` | Stok per SKU/batch/exp | WQS |
| `stock/wqs_incoming.php` | Penerimaan barang | WQS |
| `stock/wqs_pr.php` | Purchase Request | WQS |
| `stock/wqs_allocation.php` | Alokasi stok ke DO | WQS |
| `stock/wqs_picking.php` | Picking list | WQS |
| `stock/wqs_stock_adjustment.php` | Penyesuaian stok + alasan | WQS, Manager |
| `stock/wqs_stock_audit.php` | Riwayat/audit pergerakan stok | WQS, QA |
| `stock/wqs_stock_opname.php` | Stock opname | WQS |
| `stock/wqs_stock_transfer.php` | Transfer antar kantor (terbatas) | SYS |

## Monitoring

- Task DO terhubung ke alur O2C — lihat modul **CRM & Sales (O2C)**.
- Office default / kode kantor: ikuti `docs` project rules (`wqs_stock_default_office`, bukan HO).

## Deep-dive

- **Stock adjustment** — pastikan alasan & audit internal sesuai SOP gudang.
- Transfer antar kantor biasanya **SYS-only**; cabang memakai alur pembelian antar kantor.
