# Panduan Hapus Data ERP_RMI_SOFULL (Tanpa Ubah Logic)

**Tujuan:** Menghapus data yang sudah ada tanpa mengubah kode/logic aplikasi.

---

## 1. Ringkasan ERP_RMI_SOFULL

| Modul | Path | Fungsi |
|-------|------|--------|
| **Master** | `/master/` | Employees, Customers, Vendors, Products, Office, Dept, User, dll |
| **Sales (CRM)** | `/sales/` | DO, Order, Control Tower |
| **Purchases (PQP)** | `/purchases/` | PO, GR, Invoice AP, Payment |
| **Stock (WQS)** | `/stock/` | Stok, Picking, PR, Incoming |
| **HRL** | `/hrl/` | Docs, Proses (Mutasi/Kenaikan), Ack Report |
| **Absensi** | `/absensi/` | Check-in, Rekap, Approval |
| **Payroll** | `/payroll/` | Run, Matrix, Loans |
| **MPR** | `/mpr/` | Plans, Budget |
| **Fixed Asset** | `/Fixed_Asset/` | Asset, Depreciation |
| **Tools** | `/tools/` | Backup, Restore, Migration, QA |

---

## 2. Cara Hapus Data (Tanpa Ubah Logic)

### Opsi A: Via UI (Aman, Pakai Fitur yang Sudah Ada)

| Data | Halaman | Cara |
|------|---------|------|
| **Employees** | Master Employees | Tombol **Hapus Semua** → ketik `HAPUS SEMUA` → Submit. Kecuali SYS (ADMIN/SUPERADMIN). |
| **Employees (terpilih)** | Master Employees | Centang checkbox → **Hapus Terpilih** (bulk delete) |
| **User** | Master System Login | Bulk delete (jika ada) |
| **Products** | Master Products | Bulk delete |
| **Customers** | Master Customers | Hapus per record atau bulk (jika ada) |
| **Vendors** | Master Vendors | Hapus per record atau bulk (jika ada) |

### Opsi B: Restore dari Backup (Reset Penuh)

1. Buat backup dulu: `tools/backup_now.php` atau manual mysqldump
2. Restore ke state kosong/fresh:
   - Via **Tools → DR → Restore**: `tools/dr/restore_db.php`
   - Pilih file backup (.sql atau .gz) dari `storage/backups/`
3. Atau restore dari file SQL fresh: `sql/ERP_RMI_SOFULL.sql` / `sql/ERP_RMI_SOFULL_mariadb.sql`

### Opsi C: SQL Langsung (Manual, Hati-hati)

**Urutan hapus** (child → parent, ikuti FK):

```sql
-- 1. Transaksi & detail (child dulu)
TRUNCATE TABLE purchases_payment_ap;
TRUNCATE TABLE purchases_invoice_ap;
TRUNCATE TABLE purchases_po_items;
TRUNCATE TABLE purchases_po;
-- ... (sesuaikan dengan tabel lain: sales, stock, payroll, dll)

-- 2. Master (setelah transaksi bersih)
DELETE FROM master_employees WHERE NOT (dept_code = 'SYS' OR employee_code LIKE 'SYS%');
DELETE FROM master_customers;
DELETE FROM master_vendors;
DELETE FROM master_products;
-- Jangan hapus: master_system_login (admin), master_office, master_departements jika masih dipakai
```

**Penting:** Cek foreign key sebelum TRUNCATE/DELETE. Beberapa tabel punya referensi ke tabel lain.

### Opsi D: Drop & Recreate Database (Paling Bersih)

```bash
# 1. Backup dulu
mysqldump -u user -p ERP_RMI_SOFULL > backup_$(date +%Y%m%d).sql

# 2. Drop & create
mysql -u user -p -e "DROP DATABASE IF EXISTS ERP_RMI_SOFULL; CREATE DATABASE ERP_RMI_SOFULL CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 3. Import schema + data awal
mysql -u user -p ERP_RMI_SOFULL < sql/ERP_RMI_SOFULL.sql
# atau
mysql -u user -p ERP_RMI_SOFULL < sql/ERP_RMI_SOFULL_mariadb.sql
```

---

## 3. Proteksi yang Sudah Ada (Tidak Diubah)

- **Master Employees:** ADMIN & SUPERADMIN (dept SYS) tidak bisa dihapus via Hapus Semua / bulk
- **Master System Login:** admin, superadmin dilindungi
- **Holder:** Set holder_employee_code di Master System Login untuk link ke karyawan

---

## 4. Rekomendasi

| Skenario | Cara |
|----------|------|
| Hapus semua karyawan (kecuali admin) | Master Employees → **Hapus Semua** |
| Reset data uji coba | Restore dari backup kosong atau drop + import `sql/ERP_RMI_SOFULL.sql` |
| Hapus data transaksi saja | SQL manual (truncate tabel transaksi) — perlu cek FK |
| Fresh install | Drop DB → Import `sql/ERP_RMI_SOFULL.sql` |

---

*Dokumen ini untuk panduan operasional. Backup selalu sebelum aksi destructive.*
