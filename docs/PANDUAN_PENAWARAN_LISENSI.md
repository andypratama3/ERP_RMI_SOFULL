# Panduan — Jika Ada yang Berminat dengan ERP RMI SOFULL

Panduan praktis saat ada pihak yang berminat menggunakan/membeli lisensi software ini.

---

## 1. Langkah Hukum & Komersial

| # | Langkah | Keterangan |
|---|---------|------------|
| 1 | **NDA (Non-Disclosure Agreement)** | Tandatangani sebelum demo atau sharing kode |
| 2 | **Perjanjian Lisensi** | Tentukan: lisensi penggunaan, modifikasi, support, harga |
| 3 | **Konsultasi hukum** | Untuk perjanjian resmi, gunakan pengacara |

---

## 2. File yang Boleh Diberikan

### 2.1 Paket Deploy (ZIP)

**Cara generate:**
- Buka `/tools/release/create_clean_deploy_zip.php` (login sebagai ADMIN)
- Klik **Generate ZIP**
- File tersimpan di `exports/deploy/[bulan]/ERP_RMI_SOFULL_deploy_clean_[timestamp].zip`

**Isi ZIP (sudah di-exclude otomatis):**
- Kode sumber (PHP, JS, CSS)
- `vendor/` (Composer)
- `sql/` (migrations, schema)
- `docs/`
- `public/`, `_shared/`, modul (master, sales, stock, dll)
- **Tidak termasuk:** `.env`, `config-db.php`, logs, backups, credential

### 2.2 File Tambahan (Opsional)

| File | Fungsi |
|------|--------|
| `README.md` | Panduan setup dasar |
| `LICENSE` | Syarat lisensi |
| `COPYRIGHT` | Hak cipta |
| `config-db.example.php` | Template config DB (tanpa password) |
| `.env.example` | Template env (jika ada) |
| `docs/ERP_MENU_WORKFLOW_REFERENCE.md` | Referensi menu & workflow |
| `docs/WORKFLOW_MENU_DETAIL.md` | Detail workflow |
| `docs/CEK_KEAMANAN_JANGKA_PANJANG.md` | Panduan keamanan |

---

## 3. File yang JANGAN Diberikan

| File/Folder | Alasan |
|-------------|--------|
| `.env` | Berisi credential, secret |
| `config-db.php` | Password database |
| `storage/logs/*.log` | Bisa berisi data sensitif |
| `storage/backups/*.sql` | Data bisnis |
| `uploads/` | File user (foto, dokumen) |
| `*.bak`, `.env.bak*` | Backup credential |

---

## 4. Checklist Sebelum Kirim

```
□ Generate ZIP via create_clean_deploy_zip.php
□ Pastikan .env dan config-db.php TIDAK ikut (sudah auto-exclude)
□ Sertakan README.md, LICENSE, COPYRIGHT
□ Sertakan config-db.example.php
□ Buat .env.example jika pakai env (tanpa nilai rahasia)
□ Hapus data dummy/sensitif dari SQL jika export schema
□ Verifikasi: unzip di folder kosong, cek tidak ada credential
```

---

## 5. Mode Penawaran Umum

| Mode | Isi yang Diberikan |
|------|---------------------|
| **Demo only** | Screenshot, video, presentasi — tanpa kode |
| **Trial / UAT** | ZIP deploy + env.example + panduan setup — tanpa data produksi |
| **Lisensi penuh** | ZIP + dokumentasi + support (sesuai perjanjian) |

---

## 6. Template Email Singkat

```
Subjek: ERP RMI SOFULL — Paket Trial/Lisensi

Lampiran:
- ERP_RMI_SOFULL_deploy_clean_[date].zip
- README.md
- LICENSE
- config-db.example.php (jika ada)

Keterangan:
- Software proprietary. Lihat LICENSE untuk syarat penggunaan.
- Setup: ikuti README.md. Buat config-db.php dari config-db.example.php.
- Untuk pertanyaan teknis atau lisensi, hubungi [kontak Anda].
```

---

## 7. Kontak di LICENSE

Pastikan LICENSE memuat cara menghubungi Anda untuk permohonan izin/lisensi.

---

*Dokumen ini bersifat panduan. Untuk kepastian hukum, konsultasikan dengan ahli hukum.*
