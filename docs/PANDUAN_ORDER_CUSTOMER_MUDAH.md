# Panduan — Mempermudah Customer Melakukan Pemesanan

**Skenario:** Hermina Group (PIC: Purchasing) memesan ke RMI (PIC: Staff CRM)

---

## Alur Saat Ini

```
Hermina Purchasing  →  (telp/email/WA?)  →  RMI Staff CRM  →  Input manual di sales_do.php
```

**Kendala:** Staff CRM harus input manual setiap order. Customer tidak bisa order langsung.

---

## Opsi Solusi (dari paling sederhana)

### Opsi 1: Template Order + Import (Paling Cepat)

**Langkah:**
1. Buat template Excel/CSV untuk order (customer, item, qty, alamat)
2. Hermina Purchasing isi template → kirim ke Staff CRM (email/WA)
3. Staff CRM import ke ERP → auto-create DO draft

**Yang perlu dibuat:**
- Template order (format standar)
- Fitur import di `sales_do.php` atau halaman terpisah

**Pro:** Cepat, tidak perlu development besar  
**Kekurangan:** Tetap semi-manual (Hermina isi form, CRM import)

---

### Opsi 2: Form Order Web (Link Khusus per Customer)

**Langkah:**
1. Buat halaman `sales/order_request.php` — form order tanpa login
2. Form butuh: token/link unik per customer (Hermina dapat link khusus)
3. Customer isi: produk, qty, alamat, PIC
4. Submit → simpan ke tabel `order_requests` atau `sales_do` status DRAFT
5. Staff CRM dapat notifikasi → review & konfirmasi → ubah status ke proses

**Yang perlu dibuat:**
- `sales/order_request.php` (form)
- Tabel `order_requests` atau pakai sales_do dengan status REQUEST
- Token/link unik per customer (aman dari abuse)

**Pro:** Customer order mandiri, CRM hanya review  
**Kekurangan:** Perlu development, keamanan token

---

### Opsi 3: Customer Portal (Login B2B)

**Langkah:**
1. Buat role/user khusus untuk customer (mis. `CUSTOMER_HERMINA`)
2. Login Hermina Purchasing → akses portal terbatas
3. Portal: katalog produk, cart, checkout → create DO
4. Staff CRM dapat notifikasi, bisa review jika perlu

**Yang perlu dibuat:**
- Modul `customer_portal/` atau `sales/customer_order.php`
- User/role per customer
- Tampilan katalog + cart (bisa pakai pricelist per customer)

**Pro:** Full self-service, profesional  
**Kekurangan:** Development cukup besar

**Rancangan lengkap:** Lihat [docs/RANCANGAN_CUSTOMER_PORTAL.md](RANCANGAN_CUSTOMER_PORTAL.md)

---

### Opsi 4: API Order / Host-to-Host (H2H)

**Langkah:**
1. Buat endpoint `POST /api/v1/partner/order_create.php`
2. Hermina (atau AFYA) kirim PO via API dengan API Key
3. ERP terima → validasi → create DO otomatis
4. Response: do_code, status

**Yang perlu dibuat:**
- `api/v1/partner/order_create.php`
- Dokumentasi API untuk Hermina
- API Key untuk Hermina (Master Data → API Partner Keys)

**Pro:** Otomatis, integrasi sistem ke sistem (Host-to-Host)  
**Kekurangan:** Hermina harus punya sistem yang bisa hit API

**Persiapan lengkap:** Lihat [docs/PERSIAPAN_H2H_PO_HERMINA.md](PERSIAPAN_H2H_PO_HERMINA.md)

---

### Opsi 5: Integrasi dengan AFYA

Jika Hermina pakai AFYA Better HIS:
- AFYA (rumah sakit) → API → ERP RMI
- Order dari sistem rumah sakit langsung masuk ke ERP

**Langkah:** Ikuti `docs/PANDUAN_INTEGRASI_AFYA.md`

---

## Rekomendasi Berdasarkan Kesiapan

| Kesiapan Hermina | Rekomendasi |
|------------------|-------------|
| **Hanya Excel/email** | Opsi 1 — Template + Import |
| **Bisa akses web, belum ada sistem** | Opsi 2 — Form order dengan link |
| **Mau login sendiri** | Opsi 3 — Customer portal |
| **Sudah punya sistem (AFYA, dll)** | Opsi 4 atau 5 — API / integrasi |

---

## Langkah Implementasi (Opsi 1 — Paling Cepat)

1. **Buat template CSV order:**
   - Kolom: `customers_code`, `office_code`, `do_date`, `customer_pic`, `customer_phone`, `shipping_address`, `sku`, `qty`, `unit_price`, `note`

2. **Buat halaman import:**
   - `sales/order_import.php` — upload CSV → parse → create DO draft
   - Atau tambah fitur import di `sales_do.php`

3. **SOP untuk Hermina:**
   - Download template
   - Isi data
   - Kirim ke Staff CRM (email)
   - CRM import → konfirmasi ke Hermina

---

## Mapping PIC

| Pihak | PIC | Peran |
|-------|-----|-------|
| **Hermina** | Purchasing | Input order, kirim ke RMI |
| **RMI** | Staff CRM | Terima order, import/review, proses DO |

---

*Pilih opsi sesuai kebutuhan dan kemampuan teknis kedua pihak.*
