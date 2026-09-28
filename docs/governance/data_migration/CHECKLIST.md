# Data Migration Checklist

Gunakan checklist ini saat tim siap migrasi.

---

## Sebelum Migrasi

- [ ] Backup database lengkap
- [ ] Master Products sudah diisi (minimal SKU yang akan di-migrate)
- [ ] Master Manufactures sudah diisi (untuk AP)
- [ ] Master Customers sudah diisi (untuk AR)
- [ ] Master Office sudah ada (BGR, BDG, dll)
- [ ] Batch code ditentukan (contoh: CUTOVER_2026Q1)
- [ ] CSV template diisi dari data lama

---

## Staging

- [ ] Jalankan `001_create_staging_tables.sql`
- [ ] Load data ke staging (CSV / LOAD DATA / import manual)
- [ ] Jalankan `002_validate_staging.sql` — semua error = 0
- [ ] Perbaiki data jika ada error validasi

---

## Posting (urutan wajib)

- [ ] Stock: `003_post_opening_stock.sql` (atau `003_legacy_wqs_stock.sql` jika belum ada wqs_stock_by_office)
- [ ] AP: `004_post_opening_ap.sql`
- [ ] AR: `005_post_opening_ar.sql`

---

## Verifikasi

- [ ] Stock tampil di `stock/wqs_stock.php` (filter office BGR)
- [ ] AP tampil di modul Purchasing / AP
- [ ] AR tampil di modul Sales (DO opening)

---

## Rollback (jika perlu)

- [ ] Edit `006_rollback.sql` — set @batch
- [ ] Jalankan rollback
- [ ] Restore backup jika stock perlu di-reset
