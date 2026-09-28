# Notes — Terminologi RMI

> Dokumen ini mencatat definisi dan konvensi istilah di RMI agar tidak salah persepsi.

---

## Vendor vs Manufacturer

### Vendor (master_vendors)

| Aspek | Keterangan |
|-------|------------|
| **PIC** | SCM |
| **Definisi** | Hanya terkait **pengiriman barang / product** (logistik, forwarding, jasa kirim) |
| **Bukan** | Bukan pemasok produk / supplier barang yang dibeli |
| **Tabel** | `master_vendors` |

RMI **tidak membeli produk dari Vendor**. Vendor di RMI = rekanan jasa logistik/forwarding untuk pengiriman & import.

---

## SCM — Proses Pengiriman

SCM mencakup 3 hal:

| No | Jenis | Keterangan |
|----|-------|------------|
| 1 | **Team internal** | Pengiriman dekat & Cito (Urgent) — dilakukan oleh tim RMI sendiri |
| 2 | **Logistik pihak ke-3** | JNE, TIKI, Indah Logistic, dll. Kerjasama dengan RMI untuk **pengiriman barang/produk ke customer** (Sales / Penjualan) |
| 3 | **Forwarding** | Jasa pengiriman barang **import** — pembelian PQP ke manufacturers (dari luar negeri ke gudang RMI) |

**Ringkas:**
- 1 & 2 = Sales / Penjualan (delivery ke RS/customer)
- 3 = Import / P2P (delivery dari manufacturer ke RMI)

---

### Manufacturer (master_manufactures)

| Aspek | Keterangan |
|-------|------------|
| **PIC** | PQP |
| **Definisi** | Pabrikan / manufacture — **RMI membeli produk langsung ke manufacturer** |
| **Tabel** | `master_manufactures` |
| **Digunakan di** | PO, AP, Reg Alkes, dll. |

RMI membeli produk Alkes langsung ke manufacturer (pabrikan), bukan melalui vendor/distributor perantara.

---

## HRL: Pemisahan Legal vs HR

Format doc code: `HRL-{UNIT}-{KATEGORI}-{NOMOR}`

| UNIT | Scope | Contoh |
|------|-------|--------|
| **LEGAL** | Regulasi, compliance, kontrak, Reg Alkes, BPOM | SOP Recall, Form Keluhan, PP Kode Etik, Checklist Pre-Delivery |
| **HR** | SDM, people, operasional HR, document control, training | SOP Document Control, Form Training, PP Aset, Checklist Onboarding |

**Aturan singkat:**
- Reg Alkes, BPOM, recall, keluhan, cold chain, labeling → **LEGAL**
- Kode etik, compliance, NDA, kontrak → **LEGAL**
- Document control, training, cuti, akses, aset → **HR**
- Checklist/SOP operasional non-regulator (vendor onboarding, dll) → **HR** (jika dikelola HRL)

---

## Ringkasan

```
RMI beli produk  →  Manufacturer (PQP)  →  master_manufactures
RMI kirim barang →  Vendor (SCM)         →  master_vendors
                   ├─ Team internal (dekat, Cito)
                   ├─ Logistik pihak ke-3 (JNE, TIKI, dll) — Sales
                   └─ Forwarding — Import dari manufacturer
```

---

*Last updated: 2026-02-25*
