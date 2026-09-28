# Cek Keamanan ERP RMI SOFULL — Jangka Panjang

Panduan untuk memastikan ERP berjalan aman secara berkelanjutan.

---

## 1. Tools bawaan (akses via web)

Login sebagai **SYS** (admin/superadmin/RizqullahMediskaSYS) lalu buka:

| Tool | URL | Fungsi |
|------|-----|--------|
| **Health** | `/tools/health.php` | Cek DB, storage, backup, cron |
| **Security Audit** | `/tools/security_audit.php` | Scan keamanan kode |
| **Readiness Audit** | `/tools/readiness_audit.php` | Cek kesiapan deploy |
| **Audit Center** | `/tools/audit/audit_center.php` | PHP lint + security scan |
| **Doctor** | `/tools/doctor/index.php` | Preflight, health, smoke, checklist |

---

## 2. Rutinitas cek (bulanan)

| # | Cek | Cara |
|---|-----|------|
| 1 | **Health** | Buka `/tools/health.php` → pastikan DB OK, backup ada |
| 2 | **Security Scan** | Buka `/tools/security_audit.php` → jalankan scan |
| 3 | **Backup** | Pastikan backup otomatis jalan (cron 23:00) |
| 4 | **User** | Cek user tidak aktif di Master System Login |
| 5 | **Password** | Ganti password user default (jika ada) |

---

## 3. Rutinitas cek (setiap 3–6 bulan)

| # | Cek | Cara |
|---|-----|------|
| 1 | **Password DB** | Ganti password DB, update `config-db.php` |
| 2 | **Dependency** | `composer update` (uji dulu di staging) |
| 3 | **PHP** | Cek versi PHP masih didukung |
| 4 | **Log** | Cek `storage/logs/` untuk error tidak biasa |

---

## 4. Hal yang perlu dicek manual

- **Tools hanya diakses internal** — Pastikan `/tools/` tidak bisa diakses dari internet publik (firewall/VPN).
- **HTTPS** — Pastikan web server pakai HTTPS di produksi.
- **`.env` & `config-db.php`** — Tidak ada di Git, tidak ter-expose di web.

---

## 5. Jika ada temuan

- **Security Scan** → Perbaiki item critical/high sesuai rekomendasi.
- **Health FAIL** → Cek DB, storage, backup, cron.
- **Backup gagal** → Cek path, permission, disk space.

---

## 6. URL cepat (NAS)

```
http://[APP_URL]/tools/health.php
http://[APP_URL]/tools/security_audit.php
http://[APP_URL]/tools/audit/audit_center.php
```

Ganti `[APP_URL]` dengan URL ERP Anda (mis. `http://192.168.x.x/ERP_RMI_SOFULL` atau domain Anda).
