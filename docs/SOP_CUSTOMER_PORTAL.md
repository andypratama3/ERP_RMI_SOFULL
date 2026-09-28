# SOP — Customer Portal

**Untuk:** Staff CRM, PIC Hermina (Purchasing), Admin RMI

---

## 1. Untuk Staff CRM (RMI)

### 1.1 Setup User Portal Baru

1. Login ke ERP → **Master Data** → **Customer Portal Users**
2. **Cara A (dari PIC):**
   - Buka **Master User / PIC Customers**
   - Filter customer (mis. Hermina Depok)
   - Klik **"Buat Portal"** di baris PIC Purchasing
   - Form terisi otomatis → isi **Username** & **Password**
   - Klik **Tambah**
3. **Cara B (manual):**
   - Buka **Customer Portal Users** → form Tambah
   - Pilih **Customer**, isi Username, Password, Nama, Email, Telepon, Office
   - Atau pilih dari dropdown **"Atau dari PIC"** untuk auto-fill
4. Berikan **username + password** ke PIC Hermina (aman: WA/telepon, jangan email plain)

### 1.2 URL Portal untuk Customer

```
https://[domain]/ERP_RMI_SOFULL/customer_portal/login.php
```

Bookmark di device yang disediakan untuk Hermina.

### 1.3 Proses Order dari Portal

1. Order dari portal otomatis masuk ke **Sales DO** (status: crm_to_wqs)
2. **Email notifikasi** ke CRM (jika `ENABLE_EMAIL_ALERTS=1` dan `ALERT_EMAIL_TO` di .env)
3. Di **Sales Dashboard** → alert biru jika ada order portal hari ini
4. Di **Sales DO** → badge **"Portal"** pada DO dari portal
5. Proses seperti DO biasa: WQS → SCM → ACT → FIN

### 1.4 Reset Password

- **Customer Portal Users** → baris user → isi password baru → **Reset**

---

## 2. Untuk PIC Hermina (Purchasing)

### 2.1 Login

1. Buka URL portal (dari CRM)
2. Masukkan **Username** dan **Password**
3. Klik **Masuk**

### 2.2 Order Produk

1. **Katalog** → cari produk (nama/SKU) → pilih qty → **Tambah**
2. **Keranjang** → cek item → **Checkout**
3. Isi **Alamat pengiriman**, PIC, Telepon → **Buat Order**
4. Order selesai → bisa **Print DO** atau **Order Lagi**

### 2.3 Riwayat Order

- **Riwayat Order** → lihat daftar DO
- **Filter:** Status (crm_to_wqs, wqs_to_scm, dll), Tanggal (dari–sampai)
- Klik **Detail** untuk melihat isi order
- **Print DO** untuk cetak

### 2.4 Multi-office (Checkout)

- Jika RMI punya beberapa kantor (BGR, BDG, dll), dropdown **Kantor RMI Tujuan** muncul saat checkout
- Pilih kantor yang akan memproses order

### 2.5 Lupa Password

- Hubungi Staff CRM RMI untuk reset password

---

## 3. Upload Dokumen Pendukung (Fase 3)

Setelah order dibuat, customer bisa upload dokumen (PO, Surat Pesanan, dll) di halaman **Detail Order**:
1. Buka **Riwayat Order** → klik **Detail** pada order
2. Section **Dokumen Pendukung** → pilih jenis, file, keterangan → **Upload**
3. Staff CRM melihat dokumen di **Sales DO** → **Detail** (View)

**Migration 150:** `./tools/nas/erp.sh php tools/run_migration_150.php` (buat tabel sales_do_portal_docs)

---

## 4. Checklist Go-Live

- [ ] Migration 136, 137, 138, 139, 150 dijalankan
- [ ] Minimal 1 user portal per cabang Hermina
- [ ] Produk di master_products status active
- [ ] Pricelist (jika ada) sudah diisi
- [ ] URL portal di-bookmark di device Hermina
- [ ] CRM paham flow: DO portal = proses normal

---

## 4. Kontak

- **RMI Staff CRM:** [isi nomor/email]
- **IT Support:** [isi nomor/email]
