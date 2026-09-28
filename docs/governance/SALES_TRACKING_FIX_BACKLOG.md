# Sales Tracking Fix Backlog

## Status: LEGACY_WARN_ALLOWED (cutover GO, tapi wajib ditindaklanjuti)

Violation details: `storage/logs/sales_tracking_violations_last.json`

## Violations

### `act_fin_integrity`
- **Problem**: Pelanggaran urutan transisi ACT→FIN (fin_paid_at < act_ready_fin_at atau null).
- **Root cause**: Data sebelum kolom tracking ditambahkan (legacy data).
- **Owner**: ACT + FIN Manager

## Tindakan Per Row

1. **flow_violation_rows** — DO yang fin_paid_at sudah ada tapi act_ready_fin_at belum diisi:
   ```sql
   -- Review rows:
   SELECT id, doc_code, act_ready_fin_at, fin_paid_at FROM sales_do
   WHERE act_ready_fin_at IS NOT NULL
   AND fin_paid_at IS NOT NULL
   AND fin_paid_at < act_ready_fin_at
   LIMIT 50;
   ```
   - Action: FIN konfirmasi tanggal pembayaran aktual, update `fin_paid_at` atau `act_ready_fin_at`.

2. **paid_order_violation_rows** — DO yang paid tapi act_ready_fin_at null:
   ```sql
   SELECT id, doc_code, fin_paid_at, act_ready_fin_at FROM sales_do
   WHERE fin_paid_at IS NOT NULL AND act_ready_fin_at IS NULL LIMIT 50;
   ```
   - Action: ACT mengisi `act_ready_fin_at` dengan tanggal serah ke FIN (retroaktif).

## Repair Script (DRY-RUN default)
```bash
php tools/dev/repair_sales_tracking_integrity.php --dry-run
# Apply hanya dengan:
php tools/dev/repair_sales_tracking_integrity.php --apply --i-understand="REPAIR_SALES_TRACKING"
```

## Flag Legacy
Saat ini `SALES_TRACKING_ALLOW_LEGACY=1` aktif di `.env`.
Setelah data diperbaiki: hapus flag dan jalankan cutover ulang.
