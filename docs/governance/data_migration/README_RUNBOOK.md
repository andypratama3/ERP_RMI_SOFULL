# Data Migration Runbook — ERP RMI SOFULL

Panduan lengkap migrasi data lama (opening balance) ke ERP.

---

## 📥 Cara Cepat: Upload Excel

1. Login sebagai **FIN Manager** atau **ADMIN/SUPERADMIN**
2. Buka **Tools → Upload Migrasi Data** (atau `/tools/migration_upload.php`)
3. Klik **Download Excel Template** — dapat file 12 sheet + contoh isian
4. Isi data dari sistem lama di Excel (ikuti sheet **12_Cara_Pengisian** atau [CARA_PENGISIAN_EXCEL.md](CARA_PENGISIAN_EXCEL.md))
5. Upload file Excel → centang **Post Stock/AP/AR** → klik **Upload & Proses**
6. Data masuk langsung ke sistem

---

## 📋 Prasyarat

- [ ] **Master data sudah lengkap** di ERP:
  - `master_products` — SKU produk
  - `master_manufactures` — Kode principal/vendor
  - `master_customers` — Kode customer
  - `master_office` — Office code (BGR, BDG, BKS, dll)
- [ ] Backup database sebelum migrasi
- [ ] Akses MySQL/MariaDB (user dengan hak INSERT/UPDATE)

---

## 📁 Struktur File

```
docs/governance/data_migration/
├── README_RUNBOOK.md          ← Panduan ini
├── CHECKLIST.md               ← Checklist migrasi
├── sql/
│   ├── 001_create_staging_tables.sql
│   ├── 002_validate_staging.sql
│   ├── 003_post_opening_stock.sql      ← wqs_stock_by_office (BGR)
│   ├── 003_legacy_wqs_stock.sql        ← fallback jika belum ada wqs_stock_by_office
│   ├── 004_post_opening_ap.sql
│   ├── 005_post_opening_ar.sql
│   └── 006_rollback.sql
├── templates/
│   ├── stock_opening_template.csv
│   ├── ap_opening_template.csv
│   └── ar_opening_template.csv
└── tools/
    └── load_staging_from_csv.php   ← Helper load CSV ke staging
```

---

## 🔄 Urutan Eksekusi

### Step 0: Siapkan Batch Code

Ganti `CUTOVER_2026Q1` di semua script dengan batch code tim (mis: `CUTOVER_2026Q2`, `LEGACY_202603`).

```bash
# Cari & ganti di semua file sql
grep -r "CUTOVER_2026Q1" docs/governance/data_migration/sql/
```

### Step 1: Buat Staging Tables

```bash
mysql -h 127.0.0.1 -u root -p erp_rmi_sofull < docs/governance/data_migration/sql/001_create_staging_tables.sql
```

### Step 2: Load Data ke Staging

1. Copy template dari `templates/` (stock_opening_template.csv, ap_opening_template.csv, ar_opening_template.csv)
2. Isi data dari sistem lama
3. Load ke staging:

```bash
# Stock (sesuaikan path)
mysql -h 127.0.0.1 -u root -p erp_rmi_sofull -e "
LOAD DATA LOCAL INFILE '/path/to/stock_opening.csv'
INTO TABLE mig_opening_stock_stg
FIELDS TERMINATED BY ',' ENCLOSED BY '\"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(migration_batch, row_no, sku, qty_on_hand);
"

# AP
mysql ... -e "LOAD DATA LOCAL INFILE '...' INTO TABLE mig_opening_ap_stg ..."

# AR
mysql ... -e "LOAD DATA LOCAL INFILE '...' INTO TABLE mig_opening_ar_stg ..."
```

**Alternatif — script PHP (dari project root):**
```bash
cd /volume4/web/ERP_RMI_SOFULL
php docs/governance/data_migration/tools/load_staging_from_csv.php stock docs/governance/data_migration/templates/stock_opening_template.csv
php docs/governance/data_migration/tools/load_staging_from_csv.php ap docs/governance/data_migration/templates/ap_opening_template.csv
php docs/governance/data_migration/tools/load_staging_from_csv.php ar docs/governance/data_migration/templates/ar_opening_template.csv
```

Atau import manual via phpMyAdmin / DBeaver.

### Step 3: Validasi Staging

**Wajib** — pastikan semua query return 0 error:

```bash
mysql -h 127.0.0.1 -u root -p erp_rmi_sofull < docs/governance/data_migration/sql/002_validate_staging.sql
```

- `stock_missing_sku` = 0 (semua SKU ada di master_products)
- `ap_missing_manufacture` = 0 (semua manufacture_code ada)
- `ar_missing_customer` = 0 (semua customers_code ada)
- `ap_invalid_amount` = 0
- `ar_invalid_amount` = 0

Jika ada error, perbaiki data di staging atau lengkapi master data.

### Step 4: Post ke Tabel Produksi

**Urutan wajib:** Stock → AP → AR

```bash
# 4a. Stock (pakai 003_legacy_wqs_stock.sql jika wqs_stock_by_office belum ada)
mysql -h 127.0.0.1 -u root -p erp_rmi_sofull < docs/governance/data_migration/sql/003_post_opening_stock.sql

# 4b. AP (Hutang)
mysql -h 127.0.0.1 -u root -p erp_rmi_sofull < docs/governance/data_migration/sql/004_post_opening_ap.sql

# 4c. AR (Piutang)
mysql -h 127.0.0.1 -u root -p erp_rmi_sofull < docs/governance/data_migration/sql/005_post_opening_ar.sql
```

### Step 5: Verifikasi

Cek hasil di ERP:
- Stock: `stock/wqs_stock.php` atau `stock/wqs_stock_by_office`
- AP: modul Purchasing / AP
- AR: modul Sales / DO (status act_done, fin pending)

---

## ⚠️ Rollback

Jika ada kesalahan, jalankan:

```bash
mysql -h 127.0.0.1 -u root -p erp_rmi_sofull < docs/governance/data_migration/sql/006_rollback.sql
```

**Penting:** Edit `006_rollback.sql` — set `@batch` dan konfirmasi sebelum run.

---

## 🆘 Troubleshooting

| Masalah | Solusi |
|---------|--------|
| SKU tidak ditemukan | Tambah produk di `master_products` atau perbaiki SKU di CSV |
| Manufacture code tidak ada | Tambah di `master_manufactures` |
| Customer code tidak ada | Tambah di `master_customers` |
| Duplicate SKU di batch | Gabungkan qty, hapus duplikat di staging |
| Error "Unknown column" | Cek struktur tabel — mungkin migration schema belum dijalankan |

---

## 📞 Kontak

PQP / FIN / ITC — Rizqullah Mediska Indonesia
