# Panduan Lengkap ERP_RMI_SOFULL — 100% di NAS Synology

**Prinsip:** Semua berjalan di NAS. Tidak perlu laptop untuk operasi sehari-hari.

---

## BAGIAN 1 — Persiapan Awal (Sekali saja)

### Step 1.1: Pastikan Package Terinstall

Buka **DSM → Package Center**, pastikan sudah install:

| Package | Status |
|---------|--------|
| Web Station | ✓ |
| Apache HTTP Server 2.4 | ✓ |
| PHP 8.4 | ✓ |
| MariaDB 10 | ✓ |
| phpMyAdmin | ✓ |

### Step 1.2: Aktifkan PHP Extensions

**Web Station → Script Language Settings → PHP 8.4 → Edit**

Centang: `mysqli`, `pdo_mysql`, `zip`, `mbstring`, `gd`, `curl`, `openssl`, `fileinfo`, `json`, `session`, `iconv`

Klik **OK**.

### Step 1.3: Cek MariaDB

- **MariaDB 10** sudah running
- Password root: `RmiHome@2025` (atau yang kamu set)

---

## PENTING: Jumlah Tabel

| File | Tabel | Keterangan |
|------|-------|------------|
| ERP_RMI_SOFULL.sql | **68** | Base schema (cukup untuk operasi dasar) |
| + migrations 073-114 | **~110+** | GL, bank, chat, CRM, KPI, mobile auth, API Partner Keys, dll. |
| **Total full** | **~179** | Jalankan migrations setelah import base |

---

## BAGIAN 2 — Upload & Extract (Sekali saja)

### Step 2.1: Upload File Deploy

1. Buka **File Station**
2. Masuk ke folder **web** (path: `/volume4/web/`)
3. Upload file **ERP_RMI_SOFULL_deploy_*.zip** atau **.tar.gz**

### Step 2.2: Extract via SSH

1. **DSM → Control Panel → Terminal & SNMP** → Enable SSH (port 22)
2. Buka Terminal di laptop (hanya untuk setup awal ini)
3. SSH ke NAS:
   ```
   ssh admin@[IP-NAS]
   ```
4. Extract:
   ```bash
   cd /volume4/web
   
   # Buat folder dulu
   mkdir -p ERP_RMI_SOFULL
   
   # Jika pakai TAR.GZ (lebih stabil):
   tar -xzf ERP_RMI_SOFULL_deploy_*.tar.gz -C ERP_RMI_SOFULL
   
   # Atau jika pakai ZIP:
   unzip -o ERP_RMI_SOFULL_deploy_*.zip -d ERP_RMI_SOFULL
   ```
5. Cek isi:
   ```bash
   ls /volume4/web/ERP_RMI_SOFULL/
   ```
   Harus ada: `index.php`, `config.php`, `app/`, `sql/`, `storage/`

---

## BAGIAN 3 — Konfigurasi (Sekali saja)

### Step 3.1: Buat File .env

```bash
cd /volume4/web/ERP_RMI_SOFULL

cat > .env << 'ENVFILE'
APP_ENV=production
APP_DEBUG=false
TOOLS_ALLOW_REMOTE=1

ERP_PHP_BIN=/usr/local/bin/php84
ERP_DB_HOST=127.0.0.1
ERP_DB_PORT=3306
ERP_DB_NAME=ERP_RMI_SOFULL
ERP_DB_USER=root
ERP_DB_PASS=RmiHome@2025
ENVFILE

chmod 600 .env
```

**Alternatif:** Jika `.env` tidak terbaca, buat file kosong `tools/.allow_remote` untuk mengizinkan akses Tools dari LAN:
```bash
touch tools/.allow_remote
```

### Step 3.2: Set Permission

```bash
chmod -R 775 storage/ uploads/ exports/
```

### Step 3.3: Buat Database

**Penting:** `ERP_RMI_SOFULL.sql` dibuat dari MySQL 8.0 (collation `utf8mb4_0900_ai_ci`). MariaDB 10.2/10.3 di Synology **tidak mendukung** collation itu. Pakai file yang sudah dikonversi.

**Opsi A — Konversi dulu (disarankan):**
```bash
cd /volume4/web/ERP_RMI_SOFULL
./tools/nas/convert_sql_for_mariadb.sh
# Hasil: sql/ERP_RMI_SOFULL_mariadb.sql
```
Upload `ERP_RMI_SOFULL_mariadb.sql` ke NAS, lalu import file itu.

**Opsi B — Konversi di NAS via SSH:**
```bash
cd /volume4/web/ERP_RMI_SOFULL
sed 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' sql/ERP_RMI_SOFULL.sql > sql/ERP_RMI_SOFULL_mariadb.sql
```

**Import:**
1. Buka **http://[IP-NAS]/phpmyadmin**
2. Login: **root** / **RmiHome@2025**
3. Tab **SQL**, jalankan:
   ```sql
   CREATE DATABASE IF NOT EXISTS erp_rmi_sofull 
     CHARACTER SET utf8mb4 
     COLLATE utf8mb4_unicode_ci;
   ```
4. Tab **Import** → pilih `sql/ERP_RMI_SOFULL_mariadb.sql` (atau `ERP_RMI_SOFULL.sql` jika MariaDB 10.6+)
5. Klik **Go**
6. **(Opsional)** Untuk ~179 tabel (modul GL, Chat, CRM, KPI): jalankan `tools/nas/run_all_migrations.sh` via SSH

---

## BAGIAN 4 — Web Station Setup

### Step 4.1: Konfigurasi Portal

1. **Web Station → Web Portal**
2. Pilih **Default server** → **Edit**
3. **Document root:** `/volume4/web`
4. **PHP:** PHP 8.4
5. Simpan

### Step 4.2: (Opsional) Buat Portal Khusus ERP

1. **Create** → **Create new**
2. **Hostname:** `erp` atau kosongkan
3. **Document root:** `/volume4/web/ERP_RMI_SOFULL`
4. **PHP:** PHP 8.4
5. Simpan

---

## BAGIAN 5 — Akses & Login

### Step 5.1: Buka di Browser

```
http://[IP-NAS]/ERP_RMI_SOFULL/
```

atau

```
https://[IP-NAS]:5001/ERP_RMI_SOFULL/
```

### Step 5.2: Login Pertama

- **Username:** admin
- **Password:** 1234

**Wajib:** Ganti password segera!  
**Master Data → System Login → Edit user admin**

---

## BAGIAN 5.5 — Run All Tools (Checklist)

Untuk menjalankan semua tools secara berurutan via CLI:

```bash
cd /volume4/web/ERP_RMI_SOFULL
chmod +x tools/nas/run_all_tools_nas.sh
sh tools/nas/run_all_tools_nas.sh --base-url "http://[IP-NAS]/ERP_RMI_SOFULL"
```

Lihat **`tools/nas/CHECKLIST_TOOLS_NAS.md`** untuk panduan lengkap dan opsi.

---

## BAGIAN 6 — Upgrade ke Depan (100% di NAS)

**Tidak perlu laptop.** Semua dari NAS.

### Step 6.1: Upload File Upgrade

1. Dapatkan file upgrade (ZIP/TAR.GZ) — dari tim dev atau backup
2. Upload ke `/volume4/web/` via **File Station**
3. SSH ke NAS

### Step 6.2: Backup Dulu

```bash
cd /volume4/web
cp -r ERP_RMI_SOFULL ERP_RMI_SOFULL_backup_$(date +%Y%m%d)
```

### Step 6.3: Extract Overwrite

```bash
cd /volume4/web

# Extract ke folder temp
mkdir -p temp_upgrade
tar -xzf ERP_RMI_SOFULL_deploy_*.tar.gz -C temp_upgrade
# atau: unzip -o ERP_RMI_SOFULL_deploy_*.zip -d temp_upgrade

# Copy ke folder aktif (jangan overwrite .env!)
cp -r temp_upgrade/* ERP_RMI_SOFULL/
# atau rsync: rsync -av --exclude='.env' temp_upgrade/ ERP_RMI_SOFULL/

rm -rf temp_upgrade
```

### Step 6.4: Jalankan Migration (untuk dapat ~179 tabel)

**Base SQL hanya 68 tabel.** Untuk modul GL, Chat, CRM, KPI, dll. perlu migrations:

```bash
cd /volume4/web/ERP_RMI_SOFULL
chmod +x tools/nas/run_all_migrations.sh
./tools/nas/run_all_migrations.sh
```

Atau manual:
```bash
cd /volume4/web/ERP_RMI_SOFULL
for f in sql/migrations/*.sql; do
  mysql -u root -p'RmiHome@2025' erp_rmi_sofull < "$f" 2>/dev/null || true
done
```

### Step 6.5: Cek

Buka browser → pastikan ERP jalan normal.

---

## BAGIAN 7 — Troubleshooting

### Semua URL 404 Not Found

Jika **semua** halaman (termasuk index.php, login, dll) mengembalikan "The requested URL was not found on this server":

#### 1. Cek Document Root & Path

**Via SSH:**
```bash
# Cek folder web di NAS (bisa volume1 atau volume4)
ls /volume1/web/ 2>/dev/null || ls /volume4/web/ 2>/dev/null

# Pastikan ERP_RMI_SOFULL ada dan berisi index.php
ls -la /volume4/web/ERP_RMI_SOFULL/index.php
# atau
ls -la /volume1/web/ERP_RMI_SOFULL/index.php
```

**Via File Station:** Buka folder `web` → harus ada subfolder `ERP_RMI_SOFULL` → di dalamnya ada `index.php`, `master/`, `config.php`, dll.

#### 2. URL yang Benar

- **Default server:** `http://[IP-NAS]/ERP_RMI_SOFULL/` (pakai nama folder di path)
- **Portal khusus:** Jika buat portal dengan hostname `erp` dan docroot `/volume4/web/ERP_RMI_SOFULL`, akses via `http://erp/` atau `http://[IP-NAS]/` (tergantung konfigurasi portal)

**Penting:** Nama folder di path harus sama persis dengan nama folder di server (case-sensitive di Linux).

#### 3. Cek Web Station

1. **DSM → Web Station → General** → pastikan Web Service **Enabled**
2. **Web Station → Web Portal** → Default server:
   - Document root harus `/volumeX/web` (X = 1, 2, 3, 4 sesuai volume kamu)
   - PHP: pilih PHP 8.x
3. Jika pakai **portal terpisah** untuk ERP:
   - Hostname harus benar
   - Document root: `/volumeX/web/ERP_RMI_SOFULL` (path lengkap ke folder ERP)

#### 4. Test Sederhana

Buat file `info.php` di root ERP via File Station atau SSH:

```bash
echo '<?php phpinfo();' > /volume4/web/ERP_RMI_SOFULL/info.php
```

Buka: `http://[IP-NAS]/ERP_RMI_SOFULL/info.php`

- **Jika info.php tampil:** PHP jalan, path benar. Kemungkinan masalah di `.env` atau DB.
- **Jika 404:** Document root salah atau path folder salah. Cek lagi Web Station → Document root dan pastikan folder `ERP_RMI_SOFULL` ada di dalamnya.

**Hapus info.php setelah selesai:** `rm /volume4/web/ERP_RMI_SOFULL/info.php`

#### 5. AllowOverride (jika pakai .htaccess)

Beberapa setup Synology mematikan .htaccess. Jika tetap 404 meski path benar, coba tanpa .htaccess:

```bash
mv /volume4/web/ERP_RMI_SOFULL/.htaccess /volume4/web/ERP_RMI_SOFULL/.htaccess.bak
```

Lalu coba akses lagi. Jika jalan, masalah di konfigurasi Apache (AllowOverride).

---

### Halaman Blank

```bash
cd /volume4/web/ERP_RMI_SOFULL
sed -i 's/APP_DEBUG=false/APP_DEBUG=true/' .env
```
Refresh browser → lihat error. Setelah fix, kembalikan:
```bash
sed -i 's/APP_DEBUG=true/APP_DEBUG=false/' .env
```

### WQS Incoming: "Kolom po_id / po_item_id belum tersedia"

Jika halaman WQS Incoming menampilkan error merah tentang kolom `wqs_incoming.po_id` atau `wqs_incoming_items.po_item_id`:

```bash
cd /volume4/web/ERP_RMI_SOFULL
mysql -u root -p'RmiHome@2025' erp_rmi_sofull < sql/migrations/102_wqs_incoming_po_columns.sql
```

### Jobs Monitor: "Table 'erp_rmi_sofull.jobs' doesn't exist"

Jika halaman Jobs Monitor menampilkan error tabel `jobs` tidak ditemukan:

```bash
cd /volume4/web/ERP_RMI_SOFULL
mysql -u root -p'RmiHome@2025' erp_rmi_sofull < sql/migrations/103_jobs_table.sql
```

### Jalankan Semua Migration (disarankan)

Untuk modul lengkap (GL, Chat, CRM, KPI, Jobs, WQS, dll.), jalankan semua migrations:

```bash
cd /volume4/web/ERP_RMI_SOFULL
./tools/nas/run_all_migrations.sh
```

### DB Connection Error

- Cek `.env` → user/password benar?
- Cek MariaDB running: **Package Center → MariaDB 10 → Status**

### Storage Writable FAIL (Go-Live Health)

Jika **Go-Live Health** menampilkan **Writable: FAIL** untuk storage/logs, storage/backups, storage/uploads:

```bash
cd /volume4/web/ERP_RMI_SOFULL

# Opsi 1: chmod (coba dulu)
chmod -R 775 storage/ uploads/ exports/

# Opsi 2: Jika masih FAIL, cek user web server
# Synology Web Station biasanya pakai user 'http'
ps aux | grep -E 'httpd|apache|nginx' | head -3

# Opsi 3: chown ke user web (sesuaikan 'http' jika beda)
sudo chown -R http:http storage/ uploads/ exports/

# Opsi 4: Last resort (kurang aman, hanya untuk dev/staging)
chmod -R 777 storage/ uploads/ exports/
```

Setelah fix, refresh halaman **Tools → Go-Live Health**.

### Sync System / Auto-Recovery Error (tools/index.php)

Jika klik **Sync System / Auto-Recovery** muncul error putih atau `tools/index.php:410`:

- Pastikan sudah **deploy versi terbaru** yang include fix `require_once tools_exec_helpers.php`
- Atau jalankan Doctor manual via SSH:
  ```bash
  cd /volume4/web/ERP_RMI_SOFULL
  php tools/doctor/run_doctor.php
  ```

### Cron Entry MISSING / crontab command not found

Jika halaman **Autobackup Scheduler** menampilkan **Cron Entry: MISSING** atau **ERROR: crontab command not found**:

**Opsi 1 — Install via SSH (user login):**
```bash
cd /volume4/web/ERP_RMI_SOFULL
bash tools/install_daily_backup_2300.sh
```
Script sudah memakai path penuh `/usr/bin/crontab` jika PATH terbatas (web/Synology).

**Opsi 2 — Synology Task Scheduler (jika crontab tidak tersedia):**
1. Buka **Control Panel → Task Scheduler**
2. Create → Scheduled Task → User-defined script
3. General: nama "ERP Backup 23:00", user "admin" (atau user yang punya akses)
4. Schedule: Daily, 23:00
5. Task Settings → User-defined script:
   ```bash
   /bin/bash /volume4/web/ERP_RMI_SOFULL/tools/backup_now.sh --label auto_2300
   ```
6. Simpan dan enable

**Penting:** Baris cron (`0 23 * * * ...`) **tidak boleh dijalankan di shell**. Itu harus ditambah ke crontab via `crontab -e` atau script di atas. Jika di-paste di shell akan muncul `0: command not found`.

### Backup "lock exists" / Backup sudah berjalan

Jika backup gagal dengan **"Backup already running (lock exists)"** padahal tidak ada backup yang jalan:

```bash
cd /volume4/web/ERP_RMI_SOFULL
rm -rf storage/logs/.backup_now.lock
```

Lock ini sisa dari backup sebelumnya yang terhenti/crash. Setelah dihapus, backup bisa dijalankan lagi.

### Backup "shasum: command not found"

Synology/Linux memakai `openssl` atau `sha256sum`, bukan `shasum` (macOS). Script `backup_now.sh` memprioritaskan `openssl` (umum di Synology). Pastikan deploy versi terbaru.

### UAT Smoke / Backup Script run FAIL — Permission denied (zip)

Jika **UAT Smoke Runner** menampilkan **FAIL** pada "Backup script run" dengan detail `zip warning: Permission denied`:

- Script backup sudah mengecualikan `.env` (chmod 600) agar zip tidak gagal saat dibaca user web.
- Pastikan `storage/backups` dan `storage/logs` writable oleh user web (http):
  ```bash
  chmod -R 775 /volume4/web/ERP_RMI_SOFULL/storage
  sudo chown -R http:http /volume4/web/ERP_RMI_SOFULL/storage
  ```
- Jika masih gagal, cek apakah ada file lain yang tidak readable oleh http (mis. `.env.local`, `*.pem`).

### Permission Denied (umum)

```bash
chmod -R 775 /volume4/web/ERP_RMI_SOFULL/storage
chmod -R 775 /volume4/web/ERP_RMI_SOFULL/uploads
```

---

## Ringkasan Path Penting

| Item | Path |
|------|------|
| Root ERP | `/volume4/web/ERP_RMI_SOFULL` |
| Config | `/volume4/web/ERP_RMI_SOFULL/.env` |
| SQL | `/volume4/web/ERP_RMI_SOFULL/sql/` |
| Logs | `/volume4/web/ERP_RMI_SOFULL/storage/logs/` |

---

**Setelah setup ini selesai, operasi harian 100% dari NAS. Laptop tidak diperlukan.**
