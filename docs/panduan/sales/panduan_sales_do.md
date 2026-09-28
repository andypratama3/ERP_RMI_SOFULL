# Panduan — Sales DO

**Halaman ERP:** `sales/sales_do.php`  
**File Markdown:** `docs/panduan/sales/panduan_sales_do.md`

## Tujuan
Membuat dan mengelola Delivery Order (alur O2C).

## Langkah ringkas
1. Pilih customer, office, produk, qty.
2. Buat DO → Submit.
3. Kirim ke WQS (status `sent_wqs`).
4. Pantau di Control Tower jika stuck.

## Tips
- Pastikan customer & produk sudah di master.
- DO stuck? Buka Control Tower untuk tracking.

## Izin (ringkas)
Akses mengikuti halaman induk (`SALES.*`) atau `DOCS.PANDUAN_VIEW` / `DOCS.VIEW` bila diaktifkan di RBAC Center.
