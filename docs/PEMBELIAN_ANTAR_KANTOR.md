# Pembelian Antar Kantor (via Sales DO)

## Ringkasan

Semua **Kantor & Depo** melakukan pembelian antar kantor via **PO + Sales DO**. Transfer hanya untuk ADMIN/SUPERADMIN/SYS.

Flow: BGR beli dari BKS → BKS buat Sales DO (BGR = customer) → BKS pick DO (stock BKS berkurang) → BGR terima Incoming (stock BGR bertambah).

## Setup

> **Panduan lengkap:** [SETUP_PEMBELIAN_ANTAR_KANTOR.md](SETUP_PEMBELIAN_ANTAR_KANTOR.md)

### 1. Customer Internal (Kantor sebagai Penerima)

BGR, BDG, BKS, dll. yang bisa menerima dari kantor lain harus ada di **Master Customers**:

1. Buka **Master Customers**
2. Tambah customer:
   - **Customers Code**: BGR-INT (atau BGR)
   - **Customers Name**: Kantor BGR (Internal)
   - **Office**: BGR
3. Ulangi untuk kantor lain (BDG-INT, BKS-INT, dll.)

### 2. Manufacture "Kantor Internal" (Kantor sebagai Supplier)

1. Buka **Master Manufactures**
2. Tambah manufacture:
   - **Manufacture Code**: BKS (atau KANTOR-BKS)
   - **Manufacture Name**: Kantor BKS
   - **Kantor Internal**: pilih **BKS**
3. Simpan

## Flow Pembelian Antar Kantor

| Langkah | Pihak | Aksi |
|---------|-------|------|
| 1 | BGR | Buat PR → PO ke manufacture "Kantor BKS" |
| 2 | BKS | Buat **Sales DO** (customer = BGR-INT, office = BKS) |
| 3 | BKS | Pick DO → foto kartu stok → Set READY SCM → **stock BKS berkurang** |
| 4 | BGR | Terima via **WQS Incoming** (PO code) → **stock BGR bertambah** |

Semua tercatat: DO, Picking, Incoming, foto kartu stok.

## Transfer (Admin Only)

Modul **WQS Transfer** hanya untuk ADMIN/SUPERADMIN/SYS. Kantor & Depo gunakan Pembelian (PO + Sales DO).

## Migration

```bash
mysql -u root -p erp_rmi_sofull < sql/migrations/132_master_manufactures_internal_office.sql
```
