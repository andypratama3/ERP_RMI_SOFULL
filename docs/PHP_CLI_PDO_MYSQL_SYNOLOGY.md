# PHP CLI — Enable pdo_mysql (Synology NAS)

**Masalah:** `php tools/xxx.php` dari CLI mengembalikan `could not find driver` (PDO MySQL tidak aktif).

**Penyebab:** PHP CLI memakai `php.ini` berbeda dari PHP web. Ekstensi `pdo_mysql` mungkin hanya aktif untuk web.

---

## Solusi Cepat (Disarankan)

**Selalu gunakan `erp.sh`** agar otomatis memakai PHP yang benar (dari `ERP_PHP_BIN` di .env):

```bash
cd /volume4/web/ERP_RMI_SOFULL
./tools/nas/erp.sh php tools/rbac_diff_config_db.php
./tools/nas/erp.sh php tools/qa/rbac_coverage_check.php
```

`erp.sh` akan memakai `ERP_PHP_BIN` dari `.env` (mis. `/usr/local/bin/php84`) saat arg pertama adalah `php`.

---

## 1. Cek Status

```bash
# Ekstensi yang terload
php -m | grep -i pdo
php -m | grep -i mysql

# Lokasi php.ini
php --ini
```

Jika `pdo_mysql` tidak muncul → perlu diaktifkan.

---

## 2. Synology NAS

### Opsi A: Edit php.ini

1. Cari `php.ini` yang dipakai CLI:
   ```bash
   php --ini
   ```
2. Edit (mis. `/usr/local/php/etc/php.ini` atau `/volume1/@appstore/PHP*/usr/local/php/etc/php.ini`)
3. Pastikan ada (uncomment jika ada `;`):
   ```ini
   extension=pdo_mysql
   ```
   atau
   ```ini
   extension=mysqli
   extension=pdo_mysql
   ```
4. Restart PHP jika perlu (tergantung setup Synology)

### Opsi B: Package Center

- Buka **Package Center** → **PHP**
- Cek pengaturan PHP: pastikan opsi **MySQL** / **MariaDB** aktif
- Beberapa paket PHP Synology memisahkan ekstensi per modul

### Opsi C: Symlink / Alternatif PHP

Jika ada beberapa versi PHP:

```bash
# Cek path
which php
/usr/local/bin/php -m | grep pdo_mysql
```

Pakai binary PHP yang sudah include pdo_mysql.

---

## 3. Verifikasi

```bash
cd /volume4/web/ERP_RMI_SOFULL
php tools/audit/verify_data_source.php
```

**Expected:** Connection OK, bukan "could not find driver".

---

## 4. Fallback: Verifikasi via Web

Jika CLI tetap gagal, verifikasi bisa lewat browser:

```
https://10.10.60.20/ERP_RMI_SOFULL/tools/audit/verify_data_source.php
```

PHP web biasanya punya pdo_mysql aktif. Config (config-db.php) sama untuk web dan CLI.

---

## 5. Dampak jika CLI Tidak Jalan

| Script | Dampak |
|--------|--------|
| backup_now.sh | Backup cron 23:00 bisa gagal jika pakai PHP inline untuk config |
| smoke_http.php | Smoke test via CLI gagal |
| run_cutover_checks.php | Pre-deploy check gagal |
| verify_data_source.php | Audit CLI gagal (pakai web) |

**Prioritas:** Pastikan backup cron jalan. Cek log: `storage/logs/backup_daily_2300.log`
