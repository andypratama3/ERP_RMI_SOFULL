# Stock Opname — Bukti Otentik Validasi

## Fitur yang Diimplementasikan

### 1. Traceability ke wqs_stock_adjustments

Saat Apply Opname (baik dari DRAFT maupun PENDING_VERIFY), sistem akan:
- Insert record ke `wqs_stock_adjustments` untuk setiap item dengan selisih (delta_qty != 0)
- `adj_code` = kode opname (untuk traceability)
- `reason` = "opname [opname_code]"
- `office_code` = kantor opname (jika kolom ada)

**Migration:** `sql/migrations/129_wqs_stock_adjustments_opname_trace.sql`

### 2. Foto Fisik

- Upload foto kondisi gudang saat opname
- Disimpan di `uploads/stock_opname/photos/`
- Tabel: `wqs_stock_opname_attachments` (opname_id, file_path, original_filename, caption, uploaded_by, uploaded_at)
- Ditampilkan di Report Stock Opname

### 3. Tanda Tangan / Paraf

- Canvas signature pad untuk verifikator (saat Verify & Apply)
- Disimpan sebagai PNG di `uploads/stock_opname/signatures/`
- Kolom: `wqs_stock_opname.signature_path`
- Ditampilkan di Report Stock Opname

### 4. Verifikasi Dua Pihak

**Alur:**
1. **DRAFT** — Input qty fisik oleh WQS
2. **Submit untuk Verifikasi** → status **PENDING_VERIFY**
3. **Verify & Apply** — Verifikator (WQS Lead/Manager) paraf + apply → status **APPLIED**

**Kolom baru:**
- `submitted_by`, `submitted_at` — saat submit
- `verified_by`, `verified_at` — saat verify & apply
- `signature_path` — path file tanda tangan

**Backward compat:** Tetap bisa "Apply Langsung" dari DRAFT (tanpa verifikasi dua pihak).

## Migration

```bash
# Jalankan migration 128 & 129
php tools/migration/migrate.php
# atau manual:
mysql -u user -p dbname < sql/migrations/128_wqs_stock_opname_evidence.sql
mysql -u user -p dbname < sql/migrations/129_wqs_stock_adjustments_opname_trace.sql
```

## Keterkaitan dengan WQS DO Tasks & WQS Incoming (foto kartu stok)

### Pola Sama: DO Release & Incoming (GR)

| Modul | Foto Kartu Stok | Tujuan |
|-------|-----------------|--------|
| **WQS DO Tasks** | Wajib upload sebelum & sesudah saat set READY SCM | Bukti barang keluar |
| **WQS Incoming** | Wajib upload sebelum & sesudah saat simpan Incoming | Bukti barang masuk (Stock Snapshot opname) |

**Migration:** `sql/migrations/130_wqs_incoming_stock_card.sql` — kolom `wqs_stock_before`, `wqs_stock_after` di `wqs_incoming`.

### Cross-reference di Report Opname

- **Selisih minus** (fisik < sistem): DO yang me-release produk (14 hari sebelum opname) + status foto
- **Selisih plus** (fisik > sistem): Incoming yang menambah stok (14 hari sebelum opname) + status foto

Ini membantu investigasi selisih: cek DO/Incoming terkait dan apakah punya bukti foto kartu stok.

### Info Opname di WQS DO Tasks

Halaman WQS DO Tasks menampilkan **Opname terakhir (Stock Snapshot)** per kantor — link ke Report Opname untuk konteks Stock Snapshot stok.

## Verifikasi

1. Buat opname baru → input qty fisik
2. Upload foto → cek di Report
3. Submit untuk Verifikasi → status PENDING_VERIFY
4. Verify & Apply (paraf di canvas) → status APPLIED
5. Cek `wqs_stock_adjustments` — ada record dengan adj_code = opname_code
6. Cek Report — tampil verified_by, signature, foto
7. Untuk item selisih minus — cek kolom "DO/Incoming Terkait" (DO) dan status foto
8. Untuk item selisih plus — cek kolom "DO/Incoming Terkait" (Incoming) dan status foto
