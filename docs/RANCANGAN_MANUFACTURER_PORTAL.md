# Rancangan — Manufacturer Portal

**Tujuan:** Memudahkan PQP melakukan Reg Alkes dan mempererat kerjasama antara pabrikan (manufacturer) dengan Rizqullah Mediska Indonesia (RMI).

---

## 0. Konteks: PQP & Reg Alkes

**master_manufactures** = Master data pabrikan (YAXIN, dll). Setiap manufacture punya:
- `manufacture_code`, `manufacture_name`
- PIC: `pic_name`, `pic_email`, `pic_phone` — orang di pabrikan yang dihubungi PQP

**Alur PQP:**
1. PQP cari principal/manufacture (dari master_manufactures)
2. PQP buat **Case Reg Alkes** di Control Tower → pilih manufacture
3. PQP **minta dokumen** ke PIC pabrikan (email/WA/telp)
4. **PIC Manufacturer** kirim dokumen → sebelumnya manual (email attachment, dll)
5. **Manufacturer Portal** = tempat PIC Manufacturer **upload dokumen langsung** yang PQP butuhkan

**Dokumen yang PQP butuhkan dari pabrikan:**

| Jenis | Tabel/Lokasi | Stage | Deskripsi |
|-------|--------------|-------|-----------|
| PKS, LOA, LOA_KBRI | master_manufactures_docs | 4+ | Perjanjian, surat kuasa (per manufacture) |
| REG_DOSSIER | hrl_reg_alkes_case_docs | 7+ | Berkas registrasi (per case) |
| AKSESORIS_LIST | hrl_reg_alkes_case_docs | 7+ | Daftar aksesoris (per case) |
| IFU_ID | hrl_reg_alkes_case_docs | 7+ | IFU Bahasa Indonesia (per case) |
| OEM_PROPOSAL | hrl_reg_alkes_case_docs | 7+ | Jika produk OEM (per case) |
| CATALOG, QUOTATION | hrl_reg_alkes_case_docs | 2–3 | Katalog & penawaran (per case) |

---

## 1. Ringkasan Arsitektur

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      MANUFACTURER PORTAL                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│  [Login] → [Dashboard] → [Case Reg Alkes] → [Upload Dokumen] → [Status]       │
│                                                                              │
│  Auth: manufacturer_portal_users (terpisah dari master_system_login)         │
│  Binding: manufacture_code → master_manufactures                             │
│  Output: hrl_reg_alkes_case_docs, master_manufactures_docs                    │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         ERP RMI (Internal)                                    │
│  PQP: reg_alkes_case.php → review dokumen dari portal → update stage         │
│  Stage 5, 6, 12: PQP minta berkas ke pabrikan → Manufacturer upload via portal│
└─────────────────────────────────────────────────────────────────────────────┘
```

**Prinsip:** Manufacturer tidak pakai `master_system_login`. Portal punya auth sendiri, mirip Customer Portal.

---

## 2. Manfaat

| Pihak | Manfaat |
|-------|---------|
| **PQP** | Tidak perlu bolak-balik email/WA minta dokumen. Manufacturer upload langsung. |
| **Manufacturer** | Upload mandiri, track status case, transparansi progress. |
| **RMI** | Kerjasama lebih profesional, dokumen terpusat, audit trail jelas. |

---

## 3. Alur Reg Alkes (Ringkas)

| Stage | PIC | Aktivitas Manufacturer |
|-------|-----|------------------------|
| 1–4 | PQP, Legal | PKS, LOA (upload ke master_manufactures_docs) |
| 5 | PQP | **Minta berkas registrasi** → Manufacturer upload REG_DOSSIER, dll |
| 6 | PQP | Siapkan pendukung (aksesori, IFU-ID) → Manufacturer bisa upload |
| 7–10 | Legal | Review, submit BPOM |
| 11–12 | PQP | **Revisi diminta** → Manufacturer upload dokumen revisi |
| 13–15 | Legal | Submit revisi, NIE terbit |

**Manufacturer Portal fokus:** Stage 5, 6, 12 — upload dokumen yang diminta PQP.

---

## 4. Database Schema

### 4.1 Tabel `manufacturer_portal_users`

```sql
CREATE TABLE manufacturer_portal_users (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(120) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  phone VARCHAR(50) DEFAULT NULL,

  -- Binding ke manufacture
  manufacture_code VARCHAR(50) NOT NULL,
  manufacture_id INT NULL,

  status VARCHAR(20) NOT NULL DEFAULT 'active',
  last_login_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_username (username),
  KEY idx_manufacture_code (manufacture_code),
  KEY idx_manufacture_id (manufacture_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- `manufacture_code` → wajib ada di `master_manufactures`
- Satu manufacture bisa punya banyak user (mis. PIC Reg, PIC Legal)

### 4.2 Tabel `hrl_reg_alkes_cases` — Tambahan Kolom

```sql
ALTER TABLE hrl_reg_alkes_cases
  ADD COLUMN source VARCHAR(30) DEFAULT 'pqp' COMMENT 'pqp|portal',
  ADD COLUMN portal_user_id INT DEFAULT NULL COMMENT 'FK manufacturer_portal_users.id';
```

Untuk tracking case yang dokumennya diupload via portal.

### 4.3 Tabel `hrl_reg_alkes_case_docs` — Tambahan Kolom

```sql
ALTER TABLE hrl_reg_alkes_case_docs
  ADD COLUMN uploaded_via VARCHAR(20) DEFAULT 'erp' COMMENT 'erp|portal',
  ADD COLUMN portal_user_id INT DEFAULT NULL;
```

Untuk audit: dokumen diupload oleh PQP (erp) atau manufacturer (portal).

---

## 5. Fitur Portal Manufacturer

### 5.1 Login
- Username + password
- Rate limit (pakai LoginThrottle seperti Customer Portal)
- Lupa password: hubungi PQP RMI

### 5.2 Dashboard
- Nama manufacture
- Jumlah case aktif (status OPEN)
- Case yang menunggu upload (stage 5, 6, 12)
- Link ke daftar case

### 5.3 Daftar Case
- Hanya case dengan `manufacture_code` = manufacture user login
- Kolom: Case Code, Produk, Stage, Status, Aksi (Detail, Upload)

### 5.4 Detail Case + Upload Dokumen
- Lihat info case: produk, stage, deadline revisi (jika stage 11)
- Upload dokumen sesuai `doc_type` yang diminta:
  - **Case docs:** REG_DOSSIER, AKSESORIS_LIST, IFU_ID, OEM_PROPOSAL (jika OEM), CATALOG, QUOTATION
  - **Manufacture docs:** PKS, LOA, LOA_KBRI (upload ke master_manufactures_docs)
- Setelah upload → PQP bisa review & lanjut stage

### 5.5 Riwayat Upload
- Daftar dokumen yang sudah diupload (read-only)

---

## 6. Fitur ERP (PQP)

### 6.1 Master Manufacturer Portal Users
- Kelola user portal per manufacture (tambah, reset password, aktif/nonaktif)
- Dari Master Manufactures: tombol "Buat Portal" untuk manufacture yang belum punya user

### 6.2 Reg Alkes Case
- Badge "Portal" jika ada dokumen diupload via portal
- Notifikasi/alert jika manufacturer upload dokumen baru (opsional fase 2)

---

## 7. Keamanan

- CSRF pada semua form
- `rmi_h()` untuk output escaping
- Upload: validasi ekstensi (pdf, doc, docx, xlsx, jpg, png), limit size (mis. 10MB)
- Auth terpisah: manufacturer tidak bisa akses ERP internal

---

## 8. Migrations

| No | File | Deskripsi |
|----|------|-----------|
| 140 | `140_manufacturer_portal_users.sql` | Tabel manufacturer_portal_users |
| 141 | `141_reg_alkes_portal_source.sql` | Kolom source, portal_user_id di hrl_reg_alkes_cases & case_docs |
| 142 | `142_seed_manufacturer_portal_demo.sql` | User demo (opsional) |

---

## 9. URL & Akses

- **URL Portal:** `https://[domain]/ERP_RMI_SOFULL/manufacturer_portal/login.php`
- **SOP:** `docs/SOP_MANUFACTURER_PORTAL.md` (dibuat saat implementasi)

---

## 10. Fase Implementasi

| Fase | Scope |
|------|-------|
| **Fase 1** | Login, dashboard, daftar case, upload case docs (REG_DOSSIER, IFU_ID, dll) |
| **Fase 2** | Upload manufacture docs (PKS, LOA), notifikasi PQP |
| **Fase 3** | Chat/komentar per case (opsional) |
