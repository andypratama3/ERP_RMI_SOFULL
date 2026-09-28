# Panduan — WQS Purchase Request (PR)

**Halaman ERP:** `stock/wqs_pr.php`  
**Panduan dalam app (tombol header):** `stock/panduan_wqs_pr.php`  
**File Markdown:** `docs/panduan/stock/panduan_wqs_pr.md`  
**Judul layar:** WQS Purchase Request (PR)

## Fungsi
WQS mengajukan **Purchase Request** (tanpa harga). PR tersimpan sebagai **DRAFT**, lalu diproses lebih lanjut di halaman **View** / alur PQP (PO).

## Izin (ringkas)
Akses memakai salah satu: `WQS.PR_VIEW`, `WQS.PR_CREATE`, `WQS.PR_EDIT`, `WQS.VIEW`, atau izin PO terkait (`PURCHASES.PO_*`), atau `DOCS.PANDUAN_VIEW` / `DOCS.VIEW` — sesuai matrix RBAC.

## Langkah — buat PR baru
1. Isi **PR Date** (default hari ini).
2. Pilih **Office** (wajib).  
   - User **BRANCH** hanya melihat dan hanya boleh memilih **office sendiri**.
3. (Opsional) Isi **Note** untuk kebutuhan/konteks.
4. Di tabel **Items**: klik **+ Add Item**, pilih **produk** dari master (hanya produk **active**), isi **Qty** dan **Unit** (unit bisa mengikuti master saat produk dipilih).
5. Klik **Save PR (DRAFT)**.  
   - Setelah sukses, Anda diarahkan ke **`wqs_pr_view.php`** untuk detail PR.

## Daftar PR (bawah halaman)
- Tabel **PR List** (DataTables): kode PR, tanggal, office, status, pembuat, catatan.
- **View** — buka detail / lanjut alur.  
- **Print** — cetak PR.

## Tips
- Pastikan SKU sudah benar di **Master Produk** sebelum mengajukan.
- PR tanpa baris valid (produk + qty > 0) tidak akan terisi baris; minimal satu item dengan qty positif.
- Alur lanjutan (submit, approval, hingga PO) biasanya dari **View PR** dan modul **Purchases / PQP**, bukan dari halaman create ini saja.

## Link terkait
- `stock/wqs_pr_view.php` — detail PR  
- `stock/wqs_pr_print.php` — cetak  
- `purchases/purchases_dashboard.php` — PQP Dashboard  

---
*Buka dari ERP: **F1** → “Buka panduan halaman ini”, atau Help Center.*
