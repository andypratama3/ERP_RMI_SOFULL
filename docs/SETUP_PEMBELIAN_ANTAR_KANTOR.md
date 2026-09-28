# Setup Pembelian Antar Kantor — Panduan Lengkap

> Setup data master agar flow **Pembelian Antar Kantor via Sales DO** bisa berjalan.

## Persiapan

- [ ] Master Office sudah ada (BGR, BKS, BDG, dll.)
- [ ] Migration 132 sudah dijalankan (`internal_office_code` di `master_manufactures`)

## Setup Otomatis (Opsional)

### Opsi 1: Migration SQL

```bash
mysql -u root -p erp_rmi_sofull < sql/migrations/133_seed_pembelian_antar_kantor.sql
```

### Opsi 2: Script PHP

```bash
# Via CLI (di NAS)
cd /volume4/web/ERP_RMI_SOFULL
php tools/setup/seed_pembelian_antar_kantor.php
```

Atau via web: **Tools** → "Seed Pembelian Antar Kantor" (perlu login Admin).

## Checklist Setup

### 1. Customer Internal (Kantor sebagai Penerima)

Customer ini dipakai saat **BKS** buat Sales DO ke **BGR** — BGR = customer penerima.

| Kantor | Customers Code | Customers Name | Office |
|--------|----------------|----------------|--------|
| BGR | BGR-INT | Kantor BGR (Internal) | BGR |
| BKS | BKS-INT | Kantor BKS (Internal) | BKS |
| BDG | BDG-INT | Kantor BDG (Internal) | BDG |
| *(sesuaikan kantor lain)* | | | |

**Langkah:**

1. Buka **Master Data** → **Master Customers**
2. Klik **Tambah** (atau Edit jika sudah ada)
3. Isi:
   - **Customers Code**: `BGR-INT` (atau `BGR` jika singkat)
   - **Customers Name**: `Kantor BGR (Internal)`
   - **Office**: pilih **BGR**
   - **Category**: `Internal` atau `Kantor`
   - **Status**: `active`
4. Simpan
5. Ulangi untuk kantor lain (BKS-INT, BDG-INT, dll.)

### 2. Manufacture Kantor Internal (Kantor sebagai Supplier)

Manufacture ini dipakai saat **BGR** buat PO ke **BKS** — BKS = supplier.

| Kantor | Manufacture Code | Manufacture Name | Kantor Internal |
|--------|------------------|------------------|-----------------|
| BKS | BKS | Kantor BKS | BKS |
| BDG | BDG | Kantor BDG | BDG |
| BGR | BGR | Kantor BGR | BGR |
| *(sesuaikan kantor lain)* | | | |

**Langkah:**

1. Buka **Master Data** → **Master Manufactures**
2. Klik **Tambah** (atau Edit jika sudah ada)
3. Isi:
   - **Manufacture Code**: `BKS` (atau `KANTOR-BKS`)
   - **Manufacture Name**: `Kantor BKS`
   - **Kantor Internal**: pilih **BKS**
   - **Status**: `active`
4. Simpan
5. Ulangi untuk kantor lain (BDG, BGR, dll.)

> **Catatan:** Stock kantor supplier berkurang saat **pick Sales DO**, bukan saat Incoming.

## Verifikasi

1. **Customer internal** ada di Master Customers → cari `BGR-INT`, `BKS-INT`, dll.
2. **Manufacture internal** ada di Master Manufactures → filter "Kantor Internal" terisi
3. **Flow test** (opsional):
   - BGR buat PR → PO ke manufacture "Kantor BKS"
   - BKS buat Sales DO (customer = BGR-INT)
   - BKS pick DO → stock BKS berkurang
   - BGR terima Incoming → stock BGR bertambah

## Referensi

- [PEMBELIAN_ANTAR_KANTOR.md](PEMBELIAN_ANTAR_KANTOR.md) — flow lengkap
- [STOCK_TRANSFER_EVIDENCE.md](STOCK_TRANSFER_EVIDENCE.md) — transfer (Admin only)
