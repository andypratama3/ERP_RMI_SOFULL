# Stock Office — Konsistensi ERP_RMI_SOFULL

## Single Source of Truth

| Lokasi | Fungsi |
|--------|--------|
| `stock/_stock_office_helper.php` | `RMI_DEFAULT_OFFICE_CODE` = 'BGR' |
| `stock/_stock_office_helper.php` | `wqs_stock_default_office()` — satu-satunya cara ambil default |

## Aturan (Tidak Ada Duplikat)

1. **Jangan hardcode** `'BGR'` atau `'HO'` di modul stock.
2. **Selalu gunakan** `wqs_stock_default_office()` untuk fallback office kosong.
3. **Require** `_stock_office_helper.php` sebelum memakai `wqs_stock_default_office()`.

## File yang Sudah Konsisten

| File | Require Helper | Penggunaan Default |
|------|----------------|-------------------|
| `stock/_stock_office_helper.php` | — | Definisikan constant + function |
| `stock/wqs_stock.php` | ✓ | `wqs_stock_default_office()` |
| `stock/wqs_incoming.php` | ✓ | `wqs_stock_default_office()` |
| `stock/wqs_picking.php` | ✓ | `wqs_stock_default_office()` |
| `stock/wqs_stock_adjustment.php` | ✓ | `wqs_stock_default_office()` |
| `stock/wqs_stock_opname.php` | ✓ | `wqs_stock_default_office()` |
| `stock/wqs_allocation.php` | ✓ | `wqs_stock_default_office()` |

## NOTE: Tidak Menggunakan HO

Office code hanya: BGR, BDG, BKS, TGR, SLO, SMG, KAL, JGY, SYS.

## Verifikasi

```bash
# Pastikan tidak ada hardcode BGR di stock (kecuali _stock_office_helper)
rg "['\"]BGR['\"]" stock/ --glob '*.php'
# Hanya _stock_office_helper.php yang boleh punya 'BGR'

# Pastikan tidak ada HO
rg "['\"]HO['\"]" stock/ sql/migrations/
# Hanya migration 126 (untuk cleanup legacy) dan run_wqs_stock_migration_125 (step 2b)
```
