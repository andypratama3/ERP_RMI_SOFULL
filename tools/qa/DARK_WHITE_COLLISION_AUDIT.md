# DARK/WHITE MODE COLLISION AUDIT (2026-09-29, read-only scan)
Sumber: sub-agent explore. Total 28 temuan: 8 P0, 12 P1, 8 P2/SAFE.

## Akar global (P0)
- `_shared/rmi.css` 1711 baris, NOL `@media print`; `--rmi-text:#e5e7eb` (dark).
- `_shared/rmi_layout.php:784,342` default dark + body color var(--rmi-text).
- Referensi benar: `sales/sales_do_view.php` @media print menimpa var().

## RENTAN var() tanpa override print (P1)
- sales/sales_do_print_cf.php:247,251 (.table-dark-custom, tertolong cf-wrapper color)
- stock/wqs_do_tasks.php:1311+ | stock/wqs_quarantine.php:382+ | sales/scm_do_tasks__.php:628+
- stock/wqs_pr_view.php:215,226 | purchases/purchases_po_view.php:508,511,513
- panduan.php per-modul (P2): fallback var terang

## Hardcoded terang (P0/P1)
- P0: absensi/admin/kiosk_poster.php:112,119,128,149,196 (putih-di-putih saat cetak)
- P1: stock/wqs_stock_adjustment.php:379+ ; wqs_stock_adjustmentidstok.php:347+
- P1: _shared/rmi_panduan_helper.php:143,145,155 (text-light di panduan)
- SAFE: btn-bar display:none; thead putih-disengaja; portal/payslip .no-print

## SHOW TABLES LIKE ? → MySQL 1064 (P0, 8 file)
- master_product_resolver.php:46 | purchases_ap_import.php:165 | purchases_ap_importress.php:111
- fin_do_tasks.php:132 | fin_ar_import.php:165 | _shared/erp_audit.php:56
- wqs_quarantine.php:51 | tools/review_kit_workspace.php:179
- Sudah benar: absensi/_inc/schema.php (information_schema), depo_dashboard.php
- Sudah di-fix: sales/sales_do_view.php sdv_table_exists()

## text-muted di area cetak
- P0: sales_do_print_cf.php:257-258 | P1: wqs_pr_view.php:324 | P2: panduan_helper:207
- SAFE: po_print, pr_print, products_print, do_print, request_print, payslip (.muted eksplisit)
