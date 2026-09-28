# Rancangan — RFQ & Quotation Comparison

**Tujuan:** PQP bisa membuat RFQ (Request for Quotation), beberapa manufacturer submit quotation, PQP membandingkan harga untuk dapat harga terendah.

---

## 0. Ringkasan Alur

```
PQP buat RFQ (produk/spesifikasi, deadline)
    ↓
Manufacturers lihat RFQ di Portal
    ↓
Manufacturers submit quotation (harga, lead time, terms)
    ↓
PQP lihat tabel perbandingan → pilih harga terendah / negosiasi
```

---

## 1. Tabel Database

### 1.1 `pqp_rfq` (Request for Quotation)

| Kolom | Tipe | Keterangan |
|-------|------|------------|
| id | INT PK | |
| rfq_code | VARCHAR(50) | RFQ-2026-001 |
| title | VARCHAR(200) | Judul RFQ |
| product_description | TEXT | Deskripsi produk/spesifikasi |
| product_sku | VARCHAR(50) NULL | Optional, referensi ke master_products |
| quantity | DECIMAL(15,2) NULL | Qty yang diminta |
| unit | VARCHAR(20) NULL | unit, pcs, box |
| spec_notes | TEXT NULL | Catatan spesifikasi |
| deadline | DATETIME | Batas submit quotation |
| status | ENUM | draft, open, closed, cancelled |
| created_by | VARCHAR(64) | Username PQP |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### 1.2 `pqp_rfq_quotations` (Quotation dari Manufacturer)

| Kolom | Tipe | Keterangan |
|-------|------|------------|
| id | INT PK | |
| rfq_id | INT | FK pqp_rfq |
| manufacture_id | INT | FK master_manufactures |
| manufacture_code | VARCHAR(50) | Denormalized |
| unit_price | DECIMAL(15,2) | Harga per unit |
| currency | VARCHAR(10) | USD, CNY, IDR |
| lead_time_days | INT NULL | Lead time (hari) |
| payment_terms | VARCHAR(100) NULL | Term pembayaran |
| notes | TEXT NULL | Catatan dari manufacturer |
| file_rel | VARCHAR(255) NULL | Attachment quotation |
| status | VARCHAR(20) | submitted, withdrawn |
| submitted_by | VARCHAR(64) | portal_username |
| submitted_at | DATETIME | |
| created_at | DATETIME | |
| updated_at | DATETIME | |

**Constraint:** Satu manufacture hanya bisa submit 1 quotation per RFQ (bisa revisi sebelum deadline).

---

## 2. Fitur PQP (ERP Internal)

### 2.1 Buat RFQ
- Form: Judul, deskripsi produk, qty, unit, deadline, status (draft/open)
- Status **open** = manufacturer bisa lihat & submit
- Status **draft** = hanya PQP yang lihat

### 2.2 Daftar RFQ
- Tabel: RFQ Code, Judul, Deadline, Status, Jumlah Quotation
- Filter: status (draft/open/closed/cancelled), deadline (dari–sampai)

### 2.3 Comparison View
- Pilih RFQ → tabel perbandingan:
  - Kolom: Manufacturer, Harga, Currency, ≈ USD, Lead Time, Payment Terms, File, Tanggal Submit
  - Sort by harga USD (terendah di atas)
  - Highlight harga terendah
  - Export CSV & Excel
  - Diskusi/komentar, Audit log

### 2.4 Menu
- PQP → RFQ / Request Quotation (atau di modul Purchases/PQP)

---

## 3. Fitur Manufacturer Portal

### 3.1 Daftar RFQ
- Halaman "RFQ" / "Request Quotation"
- List RFQ dengan status **open** dan deadline belum lewat
- Kolom: RFQ Code, Judul, Deadline, Status (Belum submit / Sudah submit)

### 3.2 Submit Quotation
- Klik RFQ → form: Harga, Currency, Lead Time, Payment Terms, Notes, File (optional)
- Validasi: 1 quotation per manufacture per RFQ
- Setelah submit → bisa edit selama deadline belum lewat (optional)

### 3.3 Riwayat
- Filter "Has" = daftar RFQ yang sudah di-submit quotation

---

## 4. Flow Lengkap

| Step | PQP | Manufacturer |
|------|-----|--------------|
| 1 | Buat RFQ (open), set deadline | |
| 2 | | Lihat RFQ di portal |
| 3 | | Submit quotation (harga, lead time, terms) |
| 4 | Lihat comparison, sort by price | |
| 5 | Pilih manufacturer (manual/offline) | |
| 6 | Close RFQ | |

---

## 5. Migrations

| No | File | Deskripsi |
|----|------|-----------|
| 151 | 151_pqp_rfq_quotations.sql | Tabel pqp_rfq, pqp_rfq_quotations |
| 152 | 152_pqp_rfq_extras.sql | Tabel pqp_rfq_comments (chat/komentar) |
| 153 | 153_rfq_config_seed.sql | Config RFQ_CURRENCY (rate_CNY, rate_IDR, rate_EUR), RFQ.PQP_EMAIL |

---

## 5.1 Fitur Tambahan (Implementasi)

| Fitur | Lokasi | Keterangan |
|-------|--------|------------|
| Export comparison | pqp_rfq.php, pqp_rfq_export.php | CSV & Excel |
| Notifikasi email | pqp_rfq_helper.php | RFQ open → manufacturer; quotation submit → PQP |
| Chat/komentar | pqp_rfq_comments | PQP & manufacturer |
| Attachment quotation | manufacturer_portal/rfq.php | PDF/Excel upload, pqp_rfq_download.php |
| Filter & sort | Manufacturer: All/Has/Pending; PQP: status, deadline |
| Currency conversion | system_config RFQ_CURRENCY | Kolom ≈ USD di comparison |
| Audit log | system_audit_logs | CREATE, TOGGLE_STATUS, SUBMIT |

---

## 6. URL & Menu

- **PQP:** `/pqp/pqp_rfq.php` atau `/purchases/pqp_rfq.php`
- **Manufacturer Portal:** `/manufacturer_portal/rfq.php`

---

## 7. Keamanan

- CSRF pada semua form
- PQP: require permission PQP.VIEW atau PQP.RFQ
- Manufacturer: hanya bisa submit untuk manufacture_code sendiri
- RFQ draft: tidak tampil di portal
