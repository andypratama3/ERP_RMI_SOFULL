# Panduan Instalasi ERP di Synology NAS

**Path default:** `/volume4/web/ERP_RMI_SOFULL` (sesuaikan di `synology_setup.sh` jika beda)

## File di Folder Ini

| File | Fungsi |
|------|--------|
| `synology_setup.sh` | Setup permission, .env, .htaccess setelah extract |
| `synology_disable_disk_check.sh` | Nonaktifkan peringatan drive Unverified (opsional) |

---

## Langkah Instalasi (Ringkas)

### 1. Upload & Extract

- Upload **TAR.GZ** atau **ZIP** deploy ke `/volume4/web/ERP_RMI_SOFULL/`
- Extract (via File Station atau SSH)

### 2. Jalankan Setup Script via SSH

```bash
ssh admin@[IP-NAS]

cd /volume4/web/ERP_RMI_SOFULL
chmod +x tools/nas/synology_setup.sh

# Edit dulu password DB di script jika perlu
nano tools/nas/synology_setup.sh   # ubah DB_PASS="..."

./tools/nas/synology_setup.sh
```

### 3. Buat Database (phpMyAdmin)

Buka http://[IP-NAS]/phpMyAdmin → SQL tab, jalankan:

```sql
CREATE DATABASE ERP_RMI_SOFULL CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'erp_user'@'localhost' IDENTIFIED BY 'GantiPasswordIni123!';
GRANT ALL ON ERP_RMI_SOFULL.* TO 'erp_user'@'localhost';
FLUSH PRIVILEGES;
```

Lalu **Import** → pilih `sql/ERP_RMI_SOFULL.sql`

### 4. Buka Aplikasi

http://[IP-NAS]/ERP_RMI_SOFULL/

Login: **admin** / **1234** → ganti password segera!

---

## Opsional: Hilangkan Peringatan Drive Unverified

```bash
ssh admin@[IP-NAS]
cd /volume4/web/ERP_RMI_SOFULL
chmod +x tools/nas/synology_disable_disk_check.sh
./tools/nas/synology_disable_disk_check.sh
sudo reboot
```
