# SQL Migrations — Schema & Data

Migrations dijalankan **berurutan** (urut nomor). Gunakan `run_all_migrations.sh`.

## File Penting (Dashboard/SLA)

| No | File | Tabel/Fungsi |
|----|------|--------------|
| 059 | `059_sales_do.sql` | sales_do (flow_status, act_due_date, fin_due_date) |
| 060 | `060_sales_do_audit.sql` | sales_do_audit (audit log DO) |

## File Penting (HRL Docs)

| No | File | Fungsi |
|----|------|--------|
| 135 | `135_hrl_docs_sop_mpr.sql` | Seed SOP MPR ke hrl_docs |

## Cara Jalankan

```bash
# Semua migrations
./tools/nas/run_all_migrations.sh

# Satu file (phpMyAdmin atau CLI)
mysql -u root -p erp_rmi_sofull < sql/migrations/059_sales_do.sql
```

## Konvensi

- **NNN_nama.sql** — NNN = urutan (001, 002, ... 135)
- **CREATE TABLE** — schema baru
- **INSERT** — seed data (idempotent pakai INSERT IGNORE jika bisa)

## Catatan

- **Duplikat nomor**: Ada beberapa file dengan nomor sama (001, 003, 060, 072, 099). Urutan eksekusi mengikuti `sort -V` (001_header sebelum 001_master_products_min, dll).
- **Celah nomor**: 103, 107, 108, 109, 111 tidak dipakai (boleh dipakai untuk migration baru).
