# Panduan RBAC Center — ERP RMI SOFULL

> Hanya SYS (admin/superadmin/RizqullahMediskaSYS) yang dapat mengakses dan mengatur RBAC Center.
> URL: `/ERP_RMI_SOFULL/rbac/index.php`

---

## Single Source of Truth: Permission Catalog

**File:** `config/rbac_permissions.php`

File ini adalah **satu-satunya sumber kebenaran** untuk daftar permission resmi di ERP_RMI_SOFULL.

| Aspek | Detail |
|---|---|
| **Lokasi** | `/volume4/web/ERP_RMI_SOFULL/config/rbac_permissions.php` |
| **Format** | Array PHP: `[perm_code, perm_name, module, description]` |
| **Jumlah** | ~425 entries (setelah pangkas mirror + alias CRUD); hitung pasti: `php tools/rbac/build_rbac_catalog_md.php` |
| **Legacy → kanonik** | `config/rbac_legacy_merge_map.php` + migrasi **162** — lihat `docs/RBAC_LEGACY_MERGE.md` |
| **Katalog per modul (MD)** | [`RBAC_CATALOG_BY_MODULE.md`](./RBAC_CATALOG_BY_MODULE.md) — tabel per `module` + kolom **Kelas CRUD** |
| **Standar quartet** | [`RBAC_CRUD_STANDARD.md`](./RBAC_CRUD_STANDARD.md) (VIEW / CREATE / EDIT / DELETE) |
| **Di-load oleh** | `rbac_seed_permissions()` di `_shared/rbac.php` |
| **Fallback jika file tidak ada** | Inline list di `_shared/rbac.php` |

### Cara Sync Bekerja (PENTING)

```
config/rbac_permissions.php
        ↓  dibaca oleh rbac_seed_permissions()
        ↓  INSERT ... ON DUPLICATE KEY UPDATE
        ↓
rbac_permissions (tabel DB)  ← ADDITIVE ONLY, TIDAK PERNAH DELETE
```

- Sync **menambah** permission yang ada di file tapi belum ada di DB
- Sync **mengupdate** nama/deskripsi permission yang sudah ada (berdasarkan kode)
- Sync **TIDAK MENGHAPUS** permission yang ada di DB tapi tidak ada di file
- DB boleh memiliki lebih banyak permission dari file (dari penambahan manual via RBAC Center UI)

### Arti Pesan Setelah Sync

```
"Permission registry disinkronkan dari config/rbac_permissions.php
 (319 entries di file → 380 total aktif di database)"
```

- `319 entries di file` = jumlah yang dibaca dari `config/rbac_permissions.php`
- `380 total aktif di database` = total permission yang benar-benar aktif di DB (termasuk yang ditambah manual)
- Keduanya valid — DB **tidak berkurang**

### Merapikan orphan (DB lebih banyak dari config)

Jika setelah cutover/migrasi Anda ingin **total registry DB mengikuti file** (menghapus kode yang sudah tidak ada di config):

1. Review daftar selisih: `php tools/rbac_diff_config_db.php` (atau buka versi web dari RBAC Center → Health).
2. RBAC Center → tab **Sync** → **Hapus orphan registry**, atau CLI: `php tools/rbac/prune_rbac_orphan_permissions.php --dry-run` lalu `--execute`.

Penghapusan memakai `DELETE` pada `rbac_permissions` untuk kode yang **tidak** tercantum di config; baris matrix & override yang mereferensi ikut hilang jika FK **ON DELETE CASCADE** aktif.

### Aturan Wajib: Menambah Permission Baru

Setiap permission baru **WAJIB** ditambahkan ke `config/rbac_permissions.php` terlebih dahulu:

```php
// Di config/rbac_permissions.php, tambahkan di section modul yang sesuai:
['MODUL.AKSI_BARU', 'Nama Permission', 'MODUL', 'Deskripsi lengkap.'],
```

Kemudian jalankan **Sync Permissions** dari RBAC Center. Jangan hanya tambah ke DB manual tanpa update file config — data di file dan DB akan tidak sinkron.

### Kenapa Ada Dua List (config file vs inline rbac.php)?

| Sumber | Entries | Dipakai |
|---|---|---|
| `config/rbac_permissions.php` | 319 | **PRIMARY** — dipakai jika file ada |
| Inline di `_shared/rbac.php` | 237 | Fallback jika config file tidak ditemukan |

Inline di `rbac.php` ada sebagai emergency fallback agar sistem tetap bisa seed meskipun config file hilang/corrupt. Namun `config/rbac_permissions.php` selalu diutamakan.

---

## Prinsip Utama

| Konsep | Penjelasan |
|---|---|
| **SYS = bypass penuh** | SYS tidak perlu entry di tabel RBAC. Akses penuh via `rbac_is_privileged_session()` |
| **Semua dept lain** | Wajib ada entry di `rbac_dept_role_permissions` atau `rbac_user_permissions` |
| **STAFF** | Hanya VIEW + CREATE + EDIT (tidak bisa DELETE/APPROVE) |
| **MANAGER** | VIEW + CREATE + EDIT + DELETE + APPROVE |
| **CRUD alias** | Tetap ada di registry sebagai backward-compat, namun jangan digunakan untuk rule baru |

---

## Struktur Permission: Aksi yang Tersedia

Setiap permission berbentuk `MODULE.AKSI`. Aksi yang tersedia:

| Aksi | Kode | Kapan Digunakan |
|---|---|---|
| **View** | `_VIEW` | Melihat list/detail halaman |
| **Create** | `_CREATE` | Menambah/input data baru |
| **Edit** | `_EDIT` | Mengubah data existing |
| **Delete** | `_DELETE` | Menghapus/membatalkan data (high risk) |
| **Approve** | `_APPROVE` | Finalisasi/approve dokumen |
| **Print** | `_PRINT` | Cetak dokumen |
| **Export** | `_EXPORT` | Download CSV/Excel |
| **Import** | `_IMPORT` | Upload bulk data |
| **Process** | `_PROCESS` | Aksi operasional khusus (mis: GR_PROCESS) |
| **Audit** | `_AUDIT_VIEW` | Lihat log audit |
| **Settings** | `_SETTINGS` | Konfigurasi modul |

---

## Daftar Permission per Modul

### SYSTEM — Manajemen Sistem
| Permission | Deskripsi | Untuk Siapa |
|---|---|---|
| `SYSTEM.USER_MANAGE` | Kelola akun login, dept/role/office | ITC Manager |
| `SYSTEM.RBAC_MANAGE` | Kelola permission & mapping Dept+Role | ITC Manager |
| `SYSTEM.CONFIG_MANAGE` | Konfigurasi global sistem | ITC Manager |
| `SYSTEM.SECURITY_VIEW` | Lihat diagnostik keamanan/sesi | ITC |
| `SYSTEM.MFA_POLICY_MANAGE` | Kelola policy MFA per role/dept | ITC Manager |
| `SYSTEM.MFA_BYPASS_MANAGE` | Kelola bypass ticket MFA | ITC Manager |
| `SYSTEM.JOBS_MONITOR` | Monitor worker queue | ITC Manager |
| `SYSTEM.RATE_LIMIT_MANAGE` | Konfigurasi rate limit API | ITC Manager |
| `SYSTEM.ACCOUNT_READINESS` | Cek kesiapan akun | ITC |
| `SYSTEM.API_PARTNER_KEYS` | Kelola API key partner | ITC Manager |

### MASTER — Master Data
| Permission | Deskripsi | Staff | Manager |
|---|---|---|---|
| `MASTER.VIEW` | Akses dashboard Master Data Center | ✓ | ✓ |
| `MASTER.CUSTOMER_VIEW` | Lihat data pelanggan | ✓ | ✓ |
| `MASTER.CUSTOMER_CREATE` | Tambah pelanggan baru | ✓ | ✓ |
| `MASTER.CUSTOMER_EDIT` | Edit data pelanggan | ✓ | ✓ |
| `MASTER.CUSTOMER_DELETE` | Hapus data pelanggan | ✗ | ✓ |
| `MASTER.CUSTOMER_EXPORT` | Export customer CSV | ✓ | ✓ |
| `MASTER.PIC_CUSTOMER_VIEW/CREATE/EDIT/DELETE` | Mapping PIC pelanggan | similar | similar |
| `MASTER.PRODUCT_VIEW/CREATE/EDIT/DELETE` | Master produk | ✓/✓/✓/✗ | ✓/✓/✓/✓ |
| `MASTER.PRODUCT_MEDIA_UPLOAD` | Upload foto/video produk | ✓ | ✓ |
| `MASTER.PRODUCT_PACKAGE_VIEW/CREATE/EDIT/DELETE` | Paket produk | similar | similar |
| `MASTER.MANUFACTURE_VIEW/CREATE/EDIT/DELETE` | Data pabrik | similar | similar |
| `MASTER.VENDOR_VIEW/CREATE/EDIT/DELETE` | Data vendor | similar | similar |
| `MASTER.PRICELIST_SELL_VIEW/CREATE/EDIT/DELETE` | Harga jual | similar | similar |
| `MASTER.PRICELIST_BUY_VIEW/CREATE/EDIT/DELETE` | Harga beli | similar | similar |
| `MASTER.EMPLOYEE_VIEW/CREATE/EDIT/DELETE` | Data karyawan | similar | similar |
| `MASTER.DEPARTMENT_VIEW/CREATE/EDIT/DELETE` | Data departemen | similar | similar |
| `MASTER.COMPANY_BANK_VIEW/CREATE/EDIT/DELETE` | Rekening perusahaan | similar | similar |
| `MASTER.OFFICE_VIEW/CREATE/EDIT/DELETE` | Data kantor/office | similar | similar |
| `MASTER.TAX_VIEW/CREATE/EDIT/DELETE` | Data pajak | similar | similar |
| `MASTER.PAYMENT_TERMS_VIEW/CREATE/EDIT/DELETE` | Termin pembayaran | similar | similar |
| `MASTER.EMAIL_COMPANY_VIEW/CREATE/EDIT/DELETE` | Email perusahaan | similar | similar |
| `MASTER.IMPORT_PRODUCTS` | Import produk CSV | ✓ | ✓ |
| `MASTER.IMPORT_CUSTOMERS` | Import customer CSV | ✓ | ✓ |
| `MASTER.IMPORT_VENDORS` | Import vendor CSV | ✓ | ✓ |

### SALES — Penjualan
| Permission | Deskripsi |
|---|---|
| `SALES.VIEW` | Lihat dashboard & daftar DO |
| `SALES.CREATE` | Buat DO/transaksi baru |
| `SALES.EDIT` | Edit DO sebelum lock |
| `SALES.DELETE` | Hapus/cancel DO |
| `SALES.PRINT` | Print CF/DO |
| `SALES.EXPORT` | Export data sales/KPI |
| `SALES.AUDIT_VIEW` | Lihat audit log DO |
| `SALES.KPI_VIEW` | Lihat KPI & SLA DO |
| `SALES.TASK_WQS` | Task DO tahap gudang |
| `SALES.TASK_SCM` | Task DO tahap SCM |
| `SALES.TASK_ACT` | Task DO tahap ACT |
| `SALES.TASK_FIN` | Task DO tahap Finance |

### PURCHASES — Pembelian
| Permission | Deskripsi |
|---|---|
| `PURCHASES.VIEW` | Lihat dashboard & daftar |
| `PURCHASES.PO_VIEW` | Lihat PO |
| `PURCHASES.PO_CREATE` | Buat PO baru (**hanya PQP**) |
| `PURCHASES.PO_EDIT` | Edit PO |
| `PURCHASES.PO_DELETE` | Hapus PO (high risk) |
| `PURCHASES.PO_APPROVE` | Approve/lock PO (**SCM Manager**) |
| `PURCHASES.PO_PRINT` | Print PO |
| `PURCHASES.GR_VIEW` | Lihat Goods Receipt |
| `PURCHASES.GR_PROCESS` | Input terima barang dari PO |
| `PURCHASES.GR_EDIT` | Edit GR |
| `PURCHASES.GR_DELETE` | Hapus GR |
| `PURCHASES.AP_INVOICE_VIEW/CREATE/EDIT/DELETE` | Invoice AP |
| `PURCHASES.AP_PAYMENT_VIEW/CREATE/EDIT/DELETE` | Pembayaran AP |
| `PURCHASES.FORWARDING_VIEW/CREATE/EDIT/DELETE` | Forwarder/logistik |
| `PURCHASES.IMPORT_CONTROL` | Control tower import |
| `PURCHASES.CEISA_PIB` | Entry PIB/CEISA |
| `PURCHASES.REPORTS_VIEW` | Lihat laporan purchases |
| `PURCHASES.EXPORT` | Export laporan |
| `PURCHASES.ADMIN_GL_AUTO` | GL auto posting (FIN Manager) |
| `PURCHASES.ADMIN_STOCK_UPDATE` | Update stock dari GR (high risk) |

### WQS — Gudang & Stok
| Permission | Deskripsi |
|---|---|
| `WQS.VIEW` | Akses modul WQS |
| `WQS.INCOMING_VIEW/CREATE/EDIT/DELETE` | Penerimaan barang |
| `WQS.PICKING_VIEW/CREATE/EDIT/DELETE` | Picking DO |
| `WQS.ALLOCATION_VIEW/CREATE/EDIT/DELETE` | Alokasi stok |
| `WQS.PR_VIEW/CREATE/EDIT/DELETE/PRINT` | Purchase Request (**WQS membuat, PQP yang approve**) |
| `WQS.DO_TASKS` | Task DO dari modul sales |
| `WQS.TRANSFER_CRUD` | Transfer stok antar kantor (hanya SYS) |
| `WQS.API_INCOMING_PO` | API load item PO ke incoming |

### STOCK — Manajemen Stok
| Permission | Deskripsi |
|---|---|
| `STOCK.VIEW` | Lihat stok (list/summary) |
| `STOCK.ADJUST` | Input penyesuaian stok |
| `STOCK.EDIT` | Edit penyesuaian |
| `STOCK.DELETE` | Hapus penyesuaian (high risk) |
| `STOCK.AUDIT_VIEW` | Lihat audit log stok |

### HRL — Human Resource & Legal
| Permission | Deskripsi |
|---|---|
| `HRL.VIEW` | Akses modul HRL |
| `HRL.REG_ALKES_VIEW` | Lihat registrasi alat kesehatan |
| `HRL.REG_ALKES_EDIT` | Edit reg alkes |
| `HRL.REG_ALKES_DELETE` | Hapus reg alkes |
| `HRL.DOC_VIEW/EDIT/DELETE` | Dokumen HRL |
| `HRL.COMPLIANCE_EXPORT` | Export laporan compliance |
| `HRL.IMPORT_REKENING` | Import rekening bank karyawan |

### HRL_PROCESS — Cuti / izin / dinas (workflow terpisah di RBAC Center)
Kolom `module` = **`HRL_PROCESS`**. Ikon menu / akses modul: salah satu dari `HRL.PROCESS_*` **atau** minimal satu `HRL.REQ_<TIPE>_VIEW`.

**Legacy (semua tipe sekaligus):**

| Permission | Deskripsi |
|---|---|
| `HRL.PROCESS_VIEW` | Lihat semua tipe (tower/list/detail sesuai aturan dept) |
| `HRL.PROCESS_CREATE` | — (disarankan pakai per-tipe CREATE) |
| `HRL.PROCESS_EDIT` | Edit & approve semua tipe |
| `HRL.PROCESS_DELETE` | Hapus semua tipe |

**Per tipe** (`<TIPE>` = `CUTI`, `IZIN`, `LEMBUR`, `PERJADIN`, `PERMINTAAN_KARYAWAN`, `KENAIKAN_GAJI`, `REKRUTMEN`):

| Sufiks | Arti |
|---|---|
| `_VIEW` | Melihat pengajuan tipe itu di tower/detail (selain pemohon sendiri; pemohon selalu lihat miliknya) |
| `_CREATE` | Membuat pengajuan baru tipe itu |
| `_EDIT` | Mengedit draft/reject + **semua aksi approval/reject/Paid** di workflow untuk tipe itu |
| `_DELETE` | Soft delete (sesuai aturan HRL/FIN/manager/pemohon di kode) |

Contoh: `HRL.REQ_CUTI_VIEW`, `HRL.REQ_LEMBUR_CREATE`, …

**Catatan:** Kode lama `HRL.REQUEST_KENAIKAN_GAJI` / `HRL.REQUEST_REKRUTMEN` dan mirror `REG_ALKES_READ|WRITE|EXPORT` **dihapus dari registry**; pakai `HRL.REQ_KENAIKAN_GAJI_CREATE`, `HRL.REQ_REKRUTMEN_CREATE`, dan `HRL.REG_ALKES_{VIEW|EDIT|EXPORT}`. Setelah deploy, jalankan migrasi **`161_rbac_hrl_drop_duplicate_perms.sql`** di NAS agar grant di matrix dipindah ke kode kanonik.

### PAYROLL — Penggajian
| Permission | Deskripsi |
|---|---|
| `PAYROLL.VIEW` | Lihat dashboard payroll |
| `PAYROLL.SETTINGS` | Konfigurasi payroll |
| `PAYROLL.MATRIX_MANAGE` | Import/edit salary matrix |
| `PAYROLL.RUN_CREATE` | Generate payroll run |
| `PAYROLL.RUN_EDIT` | Edit item run |
| `PAYROLL.RUN_DELETE` | Hapus run (high risk) |
| `PAYROLL.RUN_POST` | Lock/post run (finalisasi) |
| `PAYROLL.RUN_PAID` | Set run paid/final |
| `PAYROLL.EXPORT_BANK` | Export file pembayaran bank |
| `PAYROLL.LOANS/LOANS_EDIT/LOANS_DELETE` | Pinjaman/kasbon |
| `PAYROLL.PAYSLIP_VIEW` | Lihat/print payslip |
| `PAYROLL.AUDIT` | Lihat audit payroll |

### ABSENSI — Kehadiran
| Permission | Deskripsi |
|---|---|
| `ABSENSI.VIEW` | Lihat dashboard absensi |
| `ABSENSI.CHECKIN` | Check-in/out |
| `ABSENSI.REQUEST` | Pengajuan izin/sakit/dinas |
| `ABSENSI.REQUEST_EDIT/DELETE` | Edit/hapus pengajuan |
| `ABSENSI.APPROVE` | Approve request (Manager) |
| `ABSENSI.RECAP` | Rekap & laporan absensi |
| `ABSENSI.OFFICE_SETTINGS` | GeoFence/Office settings (ITC) |
| `ABSENSI.ADMIN_USERS/ADMIN_PINS` | Kelola user/PIN absensi |

### DASHBOARD — Dashboard & Laporan
| Permission | Deskripsi | Siapa |
|---|---|---|
| `DASHBOARD.VIEW` | Akses semua dashboard | Manager |
| `DASHBOARD.SALES_VIEW` | Sales Dashboard | CRM, MPR |
| `DASHBOARD.SCM_VIEW` | SCM Dashboard | SCM, WQS, PQP |
| `DASHBOARD.PROCUREMENT_VIEW` | Procurement Dashboard | PQP, SCM |
| `DASHBOARD.BRANCH_VIEW` | Branch Dashboard | BRANCH |
| `DASHBOARD.WAREHOUSE_VIEW` | Warehouse Dashboard | WQS |
| `DASHBOARD.FINANCE_VIEW` | Finance Dashboard | FIN, ACT |
| `DASHBOARD.REGULATORY_VIEW` | Regulatory Dashboard | PQP, HRL |
| `DASHBOARD.QUALITY_VIEW` | Quality Dashboard | PQP, WQS |
| `DASHBOARD.HRL_VIEW` | HRL Dashboard | HRL |
| `DASHBOARD.ITC_VIEW` | ITC Dashboard | ITC |
| `DASHBOARD.ACT_VIEW` | ACT Dashboard | ACT |
| `DASHBOARD.OWNER_VIEW` | Executive Dashboard | Manager |
| `DASHBOARD.OWNER_SUMMARY` | Executive Summary | Manager |
| `DASHBOARD.FINANCE_DETAIL` | Finance Detail | FIN Manager |

### MPR — Marketing & Project
| Permission | Deskripsi |
|---|---|
| `MPR.VIEW` | Akses modul MPR |
| `MPR.PLAN_VIEW/CREATE/EDIT/DELETE` | Plan MPR |
| `MPR.PLAN_APPROVE` | Approve plan (Manager) |
| `MPR.PLAN_IMPORT/EXPORT` | Import/export plan |

### KPI
| Permission | Deskripsi |
|---|---|
| `KPI.VIEW` | Lihat KPI Center |
| `KPI.EDIT` | Edit target KPI (Manager) |
| `KPI.DELETE` | Hapus data KPI (high risk) |

### PQP
| Permission | Deskripsi |
|---|---|
| `PQP.VIEW` | Akses modul PQP |
| `PQP.EDIT` | Edit data quality PQP |
| `PQP.DELETE` | Hapus data quality |
| `PQP.QUALITY_CRUD` | Kelola data QA |

### FIXED_ASSET
| Permission | Deskripsi |
|---|---|
| `FIXED_ASSET.VIEW` | Lihat dashboard |
| `FIXED_ASSET.ASSET_CRUD` | Kelola data aset |
| `FIXED_ASSET.ASSET_EDIT/DELETE` | Edit/hapus aset |
| `FIXED_ASSET.OPERATIONS` | Operasional aset (move/repair/dispose) |
| `FIXED_ASSET.DEPRECIATION_RUN` | Hitung depresiasi |
| `FIXED_ASSET.TAX_ANNUAL` | Pajak tahunan aset |
| `FIXED_ASSET.AUDIT_VIEW` | Audit log aset |

### TOOLS — Tools & Diagnostik
| Permission | Deskripsi |
|---|---|
| `TOOLS.VIEW` | Akses menu Tools |
| `TOOLS.ENTERPRISE_AUDIT_VIEW/EXPORT` | Audit keamanan/konsistensi |
| `TOOLS.BACKUP_MANAGE` | Backup, restore, retention |
| `TOOLS.READINESS_AUDIT` | Audit kesiapan deploy |
| `TOOLS.SECURITY_AUDIT` | Static scan keamanan |
| `TOOLS.REVIEW_KIT` | Review kit workspace |
| `TOOLS.PURCHASES_M2_APPLY` | Apply patch purchases (high risk) |
| `TOOLS.ITC_RESET_PASSWORD` | Reset password user |

---

## Alur Kerja Pembuatan PO (penting!)

```
WQS → buat PR (WQS.PR_CREATE)
         ↓
PQP → lihat PR, buat PO (PURCHASES.PO_CREATE)
         ↓
SCM Manager → approve PO (PURCHASES.PO_APPROVE)
         ↓
WQS/SCM → terima barang GR (PURCHASES.GR_PROCESS)
```

**SCM tidak bisa membuat PO** — hanya approve. Ini sudah dikonfigurasi di mapping default.

---

## Mapping Default per Dept|Role (Baseline)

> Ini adalah baseline awal. SYS dapat mengubah via RBAC Center.

| Dept | MANAGER tambahan | STAFF tambahan |
|---|---|---|
| **ACT** | SALES tasks ACT, AUDIT_VIEW, EXPORT, KPI | SALES tasks ACT |
| **CRM** | CUSTOMER full, PIC full, PRICELIST_SELL full, SALES full, DELETE | CUSTOMER view+create+edit, SALES tanpa DELETE |
| **FIN** | AP Invoice/Payment full, Company Bank full, Tax full, GL Auto, Payroll Paid | AP Invoice/Payment create+edit, Pricelist Beli create+edit |
| **HRL** | Employee full, Dept full, Payroll full, Absensi Approve | Employee create+edit, Payroll View |
| **ITC** | SYSTEM full, TOOLS full, Office/Email full | SYSTEM Security, Tools View, Office/Email create+edit |
| **MPR** | MPR Plan full, Approve | MPR Plan view+create+edit |
| **PQP** | Product/Package/Manufacture full, PO full, GR full | Product/Package/Manufacture create+edit, PO create+edit |
| **SCM** | Vendor full, PO Approve only, GR Process, Forwarding full | Vendor create+edit, PO View, GR Process, Forwarding create+edit |
| **WQS** | Incoming/Picking/Allocation/PR full, Stock Adjust | Incoming/Picking create+edit, PR create+edit, Allocation create |
| **BRANCH** | — | Semua operasional lintas modul, terbatas create+edit |
| **SYS** | Bypass penuh (tidak butuh entry DB) | Bypass penuh |

---

## Cara Mengatur di RBAC Center

1. Login sebagai SYS (admin/superadmin/RizqullahMediskaSYS)
2. Buka `/ERP_RMI_SOFULL/rbac/index.php`
3. **Tab "Dept+Role Matrix"** — atur permission per kombinasi Dept|Role
4. **Tab "User Override"** — atur permission khusus per user (override matrix)
5. Klik permission untuk toggle ALLOW/DENY
6. Perubahan langsung aktif (real-time, tidak perlu restart)

---

## Seed / Reset ke Baseline

Jika perlu reset ke mapping default, jalankan dari NAS (SSH):

```bash
cd /volume4/web/ERP_RMI_SOFULL
php tools/rbac/seed_rbac_all_modules.php --apply --i-understand
```

Seed bersifat **upsert** (tidak menghapus rule custom yang sudah dibuat via UI).

---

## Tips

- **Jangan gunakan CRUD alias** untuk rule baru. Gunakan granular (VIEW/CREATE/EDIT/DELETE).
- **Staff tidak boleh DELETE** — ini prinsip default. Override hanya jika ada kebutuhan bisnis jelas.
- **Approve selalu Manager** — jika Staff perlu approve, konfirmasi ke SYS terlebih dahulu.
- Permission `WQS.TRANSFER_CRUD` untuk transfer stok antar kantor — **hanya SYS** yang boleh.
