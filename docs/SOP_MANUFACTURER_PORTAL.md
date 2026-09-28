# SOP — Manufacturer Portal

**Untuk:** PQP, PIC Pabrikan (Reg Alkes), Admin RMI

---

## 0. Ringkasan Alur (Flow Baru)

### Fase 1: Penawaran Kerjasama (Manufacturer-initiated)
1. **PQP** daftarkan manufacture di Master Manufactures & buat user portal
2. **PIC Manufacturer** login → **Penawaran Kerjasama** → upload katalog, quotation, proposal, company profile
3. **PQP** review di Master Manufactures / Manufactures Docs → jika setuju, lanjut buat case

### Fase 2: Setelah Kesepakatan
4. **PQP** buat Case Reg Alkes di Control Tower → pilih manufacture
5. **PIC Manufacturer** login → lihat case → upload dokumen case (REG_DOSSIER, IFU_ID, dll) + PKS, LOA, LOA_KBRI
6. **PQP** review dokumen → lanjut stage

**Dokumen dari pabrikan:**
- **Penawaran (tanpa case):** CATALOG, QUOTATION, PENAWARAN, COMPANY_PROFILE, PKS, LOA, LOA_KBRI
- **Per case:** REG_DOSSIER, IFU_ID, AKSESORIS_LIST, OEM_PROPOSAL, CATALOG, QUOTATION

---

## 1. Untuk PQP (RMI)

### 1.1 Setup User Portal Baru

1. Login ke ERP → **Master Data** → **Manufacturer Portal Users**
2. Form Tambah:
   - Pilih **Manufacture** (pabrikan)
   - Isi **Username** & **Password** (min 6 karakter)
   - Isi Nama, Email, Telepon (opsional)
3. Klik **Tambah**
4. Berikan **username + password** ke PIC pabrikan (aman: WA/telepon)

### 1.2 URL Portal untuk Pabrikan

```
https://[domain]/ERP_RMI_SOFULL/manufacturer_portal/login.php
```

### 1.3 Proses Reg Alkes dengan Portal

1. Buat **Case Reg Alkes** di Control Tower (manufacture harus sudah terdaftar)
2. Berikan akses portal ke PIC pabrikan (Manufacturer Portal Users)
3. Pabrikan login → lihat case → **upload dokumen** (REG_DOSSIER, IFU_ID, AKSESORIS_LIST, dll)
4. Di **Control Tower** → badge **"Portal"** pada case yang ada dokumen dari portal
5. PQP review dokumen → lanjut stage sesuai SOP Reg Alkes

### 1.4 Reset Password

- **Manufacturer Portal Users** → baris user → isi password baru → **Reset**

---

## 2. Untuk PIC Pabrikan

### 2.1 Login

1. Buka URL portal (dari PQP)
2. Masukkan **Username** dan **Password**
3. Klik **Masuk**

### 2.2 Penawaran Kerjasama (tanpa case)

1. **Penawaran Kerjasama** (menu atau Dashboard)
2. Upload **Katalog**, **Quotation**, **Proposal Penawaran**, **Company Profile**
3. PQP review di ERP → jika setuju, PQP buat case Reg Alkes

### 2.3 Upload Dokumen Reg Alkes (per case — setelah ada case)

1. **Case Reg Alkes** → klik **Detail & Upload**
2. Pilih **Tipe Dokumen** (REG_DOSSIER, IFU_ID, AKSESORIS_LIST, OEM_PROPOSAL, CATALOG, QUOTATION, dll)
3. Pilih **File** (PDF, DOC, XLS, JPG, ZIP)
4. Klik **Upload**

### 2.3 Upload Dokumen Pabrikan (PKS/LOA/LOA_KBRI)

Untuk case stage 4 ke atas, di halaman Detail Case terdapat card **Dokumen Pabrikan**:
- Pilih tipe: **PKS**, **LOA**, atau **LOA_KBRI**
- Upload file → tersimpan di `master_manufactures_docs` (terlihat di ERP Reg Alkes Case)

### 2.4 Riwayat Upload

- Di halaman Detail Case → tabel "Dokumen Terupload" menampilkan semua file yang sudah diupload

### 2.5 Lupa Password

- Hubungi PQP RMI untuk reset password

---

## 3. Checklist Go-Live

- [ ] Migration 140, 141, 142 dijalankan
- [ ] Manufacture (YAXIN, dll) ada di Master Manufactures
- [ ] Minimal 1 user portal per manufacture
- [ ] URL portal diberikan ke PIC pabrikan
- [ ] PQP paham flow: case → pabrikan upload → review → lanjut stage

---

## 4. Kontak

- **RMI PQP:** [isi nomor/email]
- **IT Support:** [isi nomor/email]
