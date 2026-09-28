# Changelog — Stock per Branch

## Ringkasan

Implementasi stock konsisten per branch (office_code). Stock tidak lintas branch tanpa serah terima.

## Perubahan

### Migration & Schema

- **125_wqs_stock_per_office.sql**: Tabel `wqs_stock_by_office` (product_id, office_code, stock_qty, updated_at)
- Migrasi data dari `wqs_stock` ke office BGR (NOTE: tidak menggunakan HO)
- Kolom `office_code` di `wqs_stock_adjustments` (via tool migration)

### Helper

- **stock/_stock_office_helper.php**:
  - `wqs_stock_by_office_exists()`
  - `wqs_stock_office_add()`
  - `wqs_stock_office_reduce()`
  - `wqs_stock_office_get()`
  - `wqs_stock_office_apply_delta()`

### Modul Stock

| File | Perubahan |
|------|-----------|
| wqs_incoming.php | add_stock → wqs_stock_office_add; BRANCH restrict office |
| wqs_picking.php | sub_stock → wqs_stock_office_reduce per office DO |
| wqs_allocation.php | BRANCH: offices = hanya office user |
| wqs_pr.php | BRANCH: offices = hanya office user; validasi create |
| wqs_stock_opname.php | add_items dari wqs_stock_by_office; apply per office |
| wqs_stock.php | Data dari wqs_stock_by_office; filter office; BRANCH hanya office sendiri |
| wqs_stock_adjustment.php | office_code; apply ke wqs_stock_by_office; BRANCH restrict |

### Tools

- **tools/run_wqs_stock_migration_125.php**: Web tool jalankan migration 125 (Admin only)

### Docs

- **docs/STOCK_PER_BRANCH_VERIFICATION.md**: Langkah verifikasi
- **docs/STOCK_PER_BRANCH_CHANGELOG.md**: Changelog ini

## Modul Transfer Antar Kantor (Migration 131)

- **wqs_stock_transfer.php** — Transfer/mutasi antar kantor dengan bukti otentik:
  - Foto kartu stok sebelum & sesudah (pengirim & penerima)
  - Foto fisik produk detail saat keluar & masuk
- Flow: DRAFT → SENT (reduce stock pengirim) → RECEIVED (add stock penerima)
- Cross-reference di Report Stock Opname untuk selisih minus (transfer keluar) & plus (transfer masuk)

## Belum Diimplementasi

- Stock audit & KPI update ke wqs_stock_by_office (jika diperlukan)
