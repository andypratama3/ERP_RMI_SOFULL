# Cara Pengisian Excel Migrasi Data

Panduan singkat mengisi file Excel template migrasi (12 sheet).

---

## Langkah Umum

1. **Download template** dari Tools → Upload Migrasi Data → tombol "Download Excel Template"
2. Buka file Excel (format `.xlsx`)
3. Isi sheet sesuai urutan (1 → 4 dulu untuk master, lalu 5–7 untuk transaksi)
4. Simpan file
5. Upload di halaman yang sama → centang "Post Stock/AP/AR" → klik "Upload & Proses"

---

## Urutan Pengisian (Penting)

| Urutan | Sheet | Keterangan |
|--------|-------|------------|
| 1 | **1_Manufactures** | Principal/vendor. Wajib sebelum Products. |
| 2 | **2_Vendors** | Vendor forwarding/logistic. Opsional. |
| 3 | **3_Customers** | Customer. Wajib sebelum AR. |
| 4 | **4_Products** | Produk. Wajib sebelum Stock. manufacture_code harus ada di sheet 1. |
| 5 | **5_Stock** | Qty on hand per SKU. SKU harus ada di master_products. |
| 6 | **6_AP** | Hutang ke principal. manufacture_code harus ada. |
| 7 | **7_AR** | Piutang ke customer. customers_code harus ada. |
| 8 | **8_Office** | Cabang/depo. Biasanya sudah ada. |
| 9–11 | Bank, GL, FA | Coming soon. |
| 12 | **12_Cara_Pengisian** | Panduan. Jangan dihapus. |

---

## Detail Kolom per Sheet

### 1_Manufactures
- `manufacture_code` — Unik, wajib
- `manufacture_name`, `brand_name`, `origin_type`, `country`, `city`, `address`, `phone`, `email`
- `status` — `active` atau `inactive`

### 2_Vendors
- `vendors_code` — Unik
- `vendors_name`, `vendor_type`, `city`, `phone`, `email`, `npwp`, `status`

### 3_Customers
- `customers_code` — Unik
- `customers_name`, `category`, `segment`, `city`, `office_code` (BGR, BDG, dll), `address`, `phone`, `email`, `npwp`, `status`

### 4_Products
- `sku` — Unik
- `products_name`, `manufacture_code` (harus ada di sheet 1), `category`, `unit`, `barcode`, `status`

### 5_Stock
- `sku` — Harus ada di master_products
- `qty_on_hand` — Jumlah stok
- `office_code` — BGR, BDG, dll (default BGR)
- **Otomatis:** migration_batch, row_no (diisi sistem)

### 6_AP (Hutang)
- `invoice_number`, `invoice_date`, `due_date` (format YYYY-MM-DD)
- `manufacture_code` — Harus ada di master_manufactures
- `office_code`, `currency`, `balance_amount`, `note`
- **Otomatis:** migration_batch, row_no (diisi sistem)

### 7_AR (Piutang)
- `invoice_number`, `invoice_date`, `due_date` (format YYYY-MM-DD)
- `customers_code` — Harus ada di master_customers
- `office_code`, `currency`, `balance_amount`, `note`
- **Otomatis:** migration_batch, row_no (diisi sistem)

### 8_Office
- `office_code`, `office_name`, `office_lat`, `office_lng`, `office_radius_m`, `status`

---

## Tips

- **Jangan hapus baris header** (baris pertama tiap sheet)
- **Kosongkan sheet** jika tidak ada data untuk sheet tersebut (akan di-skip)
- **Office code** yang valid: BGR, BDG, BKS, TGR, SLO, SMG, JGY, KAL, SYS (lihat `.cursor/rules/office-codes.mdc`)
- Setelah upload, cek hasil di modul Stock, AP, dan AR di ERP
