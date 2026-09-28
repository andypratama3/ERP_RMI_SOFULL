# SQL — ERP_RMI_SOFULL

Struktur folder SQL untuk schema, migrations, seeds, dan utility scripts.

---

## Struktur Folder

```
sql/
├── README.md                    # Dokumen ini
├── ERP_RMI_SOFULL.sql           # Dump full (MySQL)
├── ERP_RMI_SOFULL_mariadb.sql   # Dump full (MariaDB)
├── erp_rmi_sofull.sql           # Dump alternatif
├── migrations/                  # Schema + seed (urut nomor)
│   ├── 001_header.sql           # Session settings
│   ├── 059_sales_do.sql         # Tabel sales_do
│   ├── 060_sales_do_audit.sql   # Tabel sales_do_audit
│   ├── 135_hrl_docs_sop_mpr.sql # Seed SOP MPR ke hrl_docs
│   └── ...
├── seeds/                       # Seed data opsional (run manual)
│   ├── README.md
│   ├── 002_master_products_seed_min.sql
│   └── 030_rbac_roles_seed.sql
└── utils/                       # Script utility (run manual)
    ├── drop_system_users.sql
    └── restore_admin_access.sql
```

**Catatan:** File `drop_system_users.sql` dan `restore_admin_access.sql` di root `sql/` sudah deprecated — gunakan `sql/utils/`.

---

## Urutan Setup Database

### 1. Database Baru (Full)

```bash
# Di NAS: cd /volume4/web/ERP_RMI_SOFULL

# 1. Import base (~68 tabel)
mysql -u root -p erp_rmi_sofull < sql/ERP_RMI_SOFULL_mariadb.sql

# 2. Jalankan semua migrations
./tools/nas/run_all_migrations.sh

# 3. (Opsional) Jalankan seeds
./tools/nas/run_seeds.sh
```

### 2. Hanya Migration Tertentu (phpMyAdmin)

1. Pilih database `erp_rmi_sofull`
2. Tab SQL → Paste isi file → Jalankan

| File | Tabel/Fungsi |
|------|--------------|
| `migrations/059_sales_do.sql` | Tabel sales_do (flow_status, act_due_date, fin_due_date) |
| `migrations/060_sales_do_audit.sql` | Tabel sales_do_audit (audit log DO) |
| `migrations/135_hrl_docs_sop_mpr.sql` | Seed SOP MPR ke hrl_docs |

---

## Migrations vs Seeds

| Folder | Isi | Cara jalankan |
|--------|-----|---------------|
| **migrations/** | CREATE TABLE, ALTER, INSERT (schema + data) | `run_all_migrations.sh` atau manual |
| **seeds/** | INSERT saja (data awal opsional) | `run_seeds.sh` atau manual |
| **utils/** | DROP, UPDATE (utility one-off) | Manual di phpMyAdmin |

---

## Referensi

- `tools/nas/run_all_migrations.sh` — Jalankan semua migrations
- `tools/nas/run_seeds.sh` — Jalankan seeds
- `tools/nas/PANDUAN_NAS_LENGKAP.md` — Panduan setup NAS
