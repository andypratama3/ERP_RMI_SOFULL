# VALIDATION REPORT

- Run ID: `8`
- Source: `accurate`
- Started: `2026-02-11 02:32:09`
- Finished: `2026-02-11 02:32:09`
- Tolerance: `1`
- Status: **PASS**

## Trial Balance

| Metric | Source | Target | Delta |
|---|---:|---:|---:|
| Net Balance (DR-CR) | 0 | 0 | 0 |
| Debit Total (Target) | - | 1800000 | - |
| Credit Total (Target) | - | 1800000 | - |

## AR/AP

| Metric | Source | Target | Delta |
|---|---:|---:|---:|
| AR Outstanding | 700000 | 700000 | 0 |
| AP Outstanding | 450000 | 450000 | 0 |

## Stock

| Metric | Source | Target | Delta |
|---|---:|---:|---:|
| Qty Total | 13 | 13 | 0 |

## Document Count and Total

| Doc Type | Source Cnt | Target Cnt | Delta Cnt | Source Total | Target Total | Delta Total | Status |
|---|---:|---:|---:|---:|---:|---:|---|
| SALES_INVOICE | 2 | 2 | 0 | 922000.00 | 922000.00 | 0.00 | OK |
| SALES_PAYMENT | 1 | 1 | 0 | 222000.00 | 222000.00 | 0.00 | OK |
| PURCHASE_INVOICE | 2 | 2 | 0 | 1110000.00 | 1110000.00 | 0.00 | OK |
| PURCHASE_PAYMENT | 1 | 1 | 0 | 660000.00 | 660000.00 | 0.00 | OK |

## Mismatch Recommendation

- Jika delta melebihi toleransi: cek `migration_errors`, cek map tabel (`map_*`), lalu re-run `migrate.php` dengan source file yang sudah dibetulkan.
- Untuk mismatch AR/AP: validasi relasi `invoice_no` pada file payment agar ke-link ke invoice yang benar.
- Untuk mismatch stock: pastikan `item_code` dan `warehouse_code` sudah konsisten dengan file master.
