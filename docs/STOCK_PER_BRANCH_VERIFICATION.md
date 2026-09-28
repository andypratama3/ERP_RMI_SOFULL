# Verifikasi Stock per Branch

## Ringkasan

Stock konsisten per branch. Tidak boleh lintas branch tanpa serah terima.

## Modul yang Tersentuh

| Modul | Perubahan |
|-------|-----------|
| `stock/wqs_incoming.php` | Add stock ke `wqs_stock_by_office` per office; BRANCH hanya bisa pilih office sendiri |
| `stock/wqs_picking.php` | Reduce stock dari `wqs_stock_by_office` per office DO |
| `stock/wqs_allocation.php` | BRANCH: dropdown office hanya office user |
| `stock/wqs_pr.php` | BRANCH: dropdown office hanya office user; validasi create |
| `stock/wqs_stock_opname.php` | Add items dari `wqs_stock_by_office` per office; apply opname update per office |
| `stock/wqs_stock.php` | Filter & tampilan stock dari `wqs_stock_by_office` per office; BRANCH hanya office sendiri |
| `stock/wqs_stock_adjustment.php` | Adjustment per office; BRANCH hanya office sendiri |
| `stock/_stock_office_helper.php` | Helper: add, reduce, get, apply_delta per office |
| `sql/migrations/125_wqs_stock_per_office.sql` | Tabel `wqs_stock_by_office` + migrasi data ke BGR |
| `tools/run_wqs_stock_migration_125.php` | Web tool jalankan migration 125 |

## Langkah Pengecekan

### 1. Migration 125

- [ ] Buka `/tools/run_wqs_stock_migration_125.php` (login Admin)
- [ ] Klik "Jalankan Migrasi"
- [ ] Pastikan status: wqs_stock_by_office ✓ Ada, office_code ✓ Ada

### 2. WQS Incoming

- [ ] Login sebagai Staff BRANCH (office BGR/BDG)
- [ ] Buka WQS Incoming → Create
- [ ] Pastikan dropdown Office hanya menampilkan office user
- [ ] Coba input incoming → stock bertambah di `wqs_stock_by_office` untuk office tersebut

### 3. WQS Allocation

- [ ] Login sebagai Staff BRANCH
- [ ] Buka WQS Allocation
- [ ] Pastikan dropdown Office hanya office user

### 4. WQS PR

- [ ] Login sebagai Staff BRANCH
- [ ] Buka WQS PR → Create
- [ ] Pastikan dropdown Office hanya office user
- [ ] Coba buat PR untuk office lain → harus ditolak

### 5. WQS Picking

- [ ] Allocation dengan office tertentu
- [ ] Picking → stock berkurang di `wqs_stock_by_office` untuk office DO

### 6. Stock Opname

- [ ] Buka Stock Opname
- [ ] Pilih office → add items dari stock office tersebut
- [ ] Apply opname → update `wqs_stock_by_office` untuk office tersebut

### 7. WQS Stock (Summary)

- [ ] Login BRANCH → hanya lihat stock office sendiri
- [ ] Login WQS/Admin → filter office, default BGR

### 8. Stock Adjustment

- [ ] Login BRANCH → office readonly (office user)
- [ ] Login WQS/Admin → pilih office
- [ ] Adjustment tersimpan dengan office_code di wqs_stock_adjustments

## Catatan

- **NOTE: Tidak menggunakan HO.** Office code hanya BGR, BDG, BKS, TGR, SLO, SMG, KAL, JGY, SYS.
- Jika migration 125 pernah dijalankan dengan HO, jalankan `sql/migrations/126_wqs_stock_ho_to_bgr.sql` untuk pindahkan data ke BGR.
- Modul **Serah Terima** antar branch belum diimplementasi (transfer dokumen, kurangi pengirim, tambah penerima).
- Stock audit & KPI yang pakai `wqs_stock` perlu diupdate ke `wqs_stock_by_office` jika ingin per-office.
