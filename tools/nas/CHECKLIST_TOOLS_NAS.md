# Checklist Tools ERP_RMI_SOFULL — NAS

Panduan menjalankan semua tools di NAS Synology secara berurutan.

---

## Ringkasan Cepat

| # | Tool | CLI | Web | Catatan |
|---|------|-----|-----|---------|
| 1 | Preflight | ✓ | ✓ | DB + storage writable |
| 2 | Doctor | ✓ | - | Health check sistem |
| 3 | Run All Checks | ✓ | ✓ | Smoke HTTP butuh base_url |
| 4 | Backup | ✓ | ✓ | Via backup_now.sh |
| 5 | Migrations | ✓ | - | Hanya saat upgrade schema |
| 6 | UAT Smoke | - | ✓ | Wajib login |
| 7 | Go-Live Health | - | ✓ | Wajib login |
| 8 | Backup Verify | - | ✓ | Wajib login |

---

## Opsi A: Satu Skrip (CLI)

Jalankan semua tools yang bisa di-CLI sekaligus:

```bash
ssh admin@[IP-NAS]
cd /volume4/web/ERP_RMI_SOFULL

chmod +x tools/nas/run_all_tools_nas.sh
sh tools/nas/run_all_tools_nas.sh
```

**Catatan Synology:** Pakai `sh` (bukan `./`) karena NAS memakai ash, bukan bash.

**Dengan base URL** (untuk smoke HTTP — ganti dengan IP NAS Anda):

```bash
sh tools/nas/run_all_tools_nas.sh --base-url "http://[IP-NAS]/ERP_RMI_SOFULL"
```

**Opsi tambahan:**

| Opsi | Keterangan |
|------|------------|
| `--skip-backup` | Lewati backup (lebih cepat) |
| `--skip-checks` | Lewati Run All Checks |
| `--run-migrations` | Jalankan migrations |
| `--base-url URL` | URL ERP untuk smoke test |

**Contoh:**

```bash
# Cepat, tanpa backup & checks
sh tools/nas/run_all_tools_nas.sh --skip-backup --skip-checks

# Full + migrations
sh tools/nas/run_all_tools_nas.sh --base-url "http://[IP-NAS]/ERP_RMI_SOFULL" --run-migrations
```

---

## Opsi B: Manual Step-by-Step

### 1. Preflight Check

```bash
cd /volume4/web/ERP_RMI_SOFULL
php tools/preflight_check.php
```

Cek: DB connection OK, storage writable.

---

### 2. Doctor

```bash
php tools/doctor/run_doctor.php
```

Cek: exit code 0, tidak ada critical fail.

---

### 3. Run All Checks (quick)

**Penting:** Set `SMOKE_BASE_URL` atau `APP_URL` agar smoke HTTP bisa hit web server.

```bash
export SMOKE_BASE_URL="http://[IP-NAS]/ERP_RMI_SOFULL"
php tools/qa/run_all_checks.php --quick
```

Atau tambahkan di `.env`:
```
APP_URL=http://192.168.1.100/ERP_RMI_SOFULL
SMOKE_BASE_URL=http://192.168.1.100/ERP_RMI_SOFULL
ERP_PHP_BIN=/usr/local/bin/php84
```
(Sesuai PANDUAN_NAS_LENGKAP: PHP 8.4)

---

### 4. Backup

```bash
./tools/backup_now.sh --label "manual_$(date +%Y%m%d_%H%M%S)"
```

---

### 5. Migrations (jika perlu)

```bash
./tools/nas/run_all_migrations.sh
```

Hanya jalankan saat ada upgrade schema (modul baru, migration baru).

---

### 6. Tools via Web (wajib login)

Setelah login sebagai Admin:

| Tool | Path |
|------|------|
| UAT Smoke Runner | Tools → UAT Smoke Runner |
| Go-Live Health | Tools → Go-Live Health |
| Backup Manager | Tools → Backup Manager |
| Backup Verify | Tools → Backup Verify |

---

## Urutan Disarankan

1. **Preflight** — pastikan DB & storage OK
2. **Doctor** — health check
3. **Backup** — backup dulu sebelum perubahan
4. **Migrations** — jika ada upgrade
5. **Run All Checks** — validasi (butuh web server jalan)
6. **UAT Smoke** (web) — smoke test lengkap
7. **Go-Live Health** (web) — status go-live

---

## Troubleshooting

| Masalah | Solusi |
|---------|--------|
| Backup "lock exists" | `rm -rf storage/logs/.backup_now.lock` (jika tidak ada backup yang jalan) |
| Permission denied (storage) | `chmod -R 775 storage/` dan `sudo chown -R http:http storage/` |
| Run All Checks gagal (smoke HTTP) | Set `--base-url` atau `SMOKE_BASE_URL` ke URL ERP yang bisa diakses |
| mysqli / "could not find driver" | Aktifkan extension `mysqli` dan `pdo_mysql` di PHP CLI (Web Station → PHP Settings) |
| Doctor lock | Tunggu selesai atau hapus lock di `storage/locks/` |
| Backup zip Permission denied | Pastikan `.env` di-exclude (sudah di backup_now.sh). Exclude `storage/sessions/*`, `storage/cache/*`. |
| Backup "shasum: command not found" | Script memakai openssl/sha256sum (Synology). Deploy versi terbaru. |
| Cron entry MISSING / crontab not found | Jalankan `bash tools/install_daily_backup_2300.sh` via SSH. Atau pakai Synology Task Scheduler (Control Panel). |

Lihat `PANDUAN_NAS_LENGKAP.md` untuk troubleshooting lengkap.
