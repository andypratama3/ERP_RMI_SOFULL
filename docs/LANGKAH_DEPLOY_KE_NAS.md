# Langkah Memindahkan File / Deploy ke /volume4/web/ERP_RMI_SOFULL

Panduan singkat untuk menyinkronkan perubahan dari workspace (Mac) ke NAS.

---

## Konteks

| Lokasi | Path | Keterangan |
|--------|------|-------------|
| **Workspace (Mac)** | `/Volumes/web/ERP_RMI_SOFULL` | Edit file via Cursor |
| **Web server (NAS)** | `/volume4/web/ERP_RMI_SOFULL` | Aplikasi berjalan di sini |

---

## Opsi 1: SMB Mount (Paling Sederhana)

Jika `/Volumes/web` di Mac adalah **mount SMB** ke share NAS (RMI-2025 → web):

- Edit di Cursor **langsung mengubah file di NAS**
- Tidak perlu deploy manual
- Pastikan file sudah **Save** (Ctrl+S / Cmd+S)

**Cek:** Buka Finder → Connect to Server → `smb://RMI-2025/web` → pastikan folder yang dibuka sama dengan yang di edit di Cursor.

---

## Opsi 2: Script sync_to_nas.sh (Paling Praktis)

Jalankan dari **Terminal Mac** (bukan dari SSH session):

```bash
cd /Volumes/web/ERP_RMI_SOFULL
./scripts/sync_to_nas.sh
```

Script akan rsync seluruh project ke `RizqullahMediska@10.10.60.20:/volume4/web/ERP_RMI_SOFULL/`.

**Custom:**
```bash
./scripts/sync_to_nas.sh /Volumes/web/ERP_RMI_SOFULL RizqullahMediska 10.10.60.20
```

---

## Opsi 3: rsync Manual (Sinkron File)

Untuk sinkronkan satu file atau seluruh folder dari Mac ke NAS:

### 3a. Satu file

```bash
# Dari Mac (Terminal)
rsync -avz /Volumes/web/ERP_RMI_SOFULL/master/master_vendors.php \
  RizqullahMediska@10.10.60.20:/volume4/web/ERP_RMI_SOFULL/master/
```

### 3b. Satu folder (mis. master)

```bash
rsync -avz /Volumes/web/ERP_RMI_SOFULL/master/ \
  RizqullahMediska@10.10.60.20:/volume4/web/ERP_RMI_SOFULL/master/
```

### 3c. Seluruh project (exclude storage, exports, node_modules)

```bash
rsync -avz --exclude='storage/logs/' --exclude='storage/backups/' \
  --exclude='exports/' --exclude='node_modules/' --exclude='.git/' \
  /Volumes/web/ERP_RMI_SOFULL/ \
  RizqullahMediska@10.10.60.20:/volume4/web/ERP_RMI_SOFULL/
```

**Catatan:** Default `10.10.60.20`. Bisa ganti ke `RMI-2025` atau `RMI-2025.local` jika perlu.

---

## Opsi 4: SCP (Copy File)

```bash
# Satu file
scp /Volumes/web/ERP_RMI_SOFULL/master/master_vendors.php \
  RizqullahMediska@10.10.60.20:/volume4/web/ERP_RMI_SOFULL/master/

# Beberapa file
scp /Volumes/web/ERP_RMI_SOFULL/master/master_vendors.php \
    /Volumes/web/ERP_RMI_SOFULL/_shared/helpers.php \
  RizqullahMediska@10.10.60.20:/volume4/web/ERP_RMI_SOFULL/master/
```

---

## Opsi 5: Deploy ZIP (Release)

Untuk deploy penuh atau ke environment lain:

1. **Buat ZIP** dari Tools Dashboard:
   - Buka: `https://erp.rizqullahmediska.com/ERP_RMI_SOFULL/tools/`
   - Atau lokal: `http://localhost/ERP_RMI_SOFULL/tools/`
   - Jalankan: **Create Clean Deploy ZIP**

2. **Atau via CLI** (di NAS):

```bash
ssh RizqullahMediska@10.10.60.20
cd /volume4/web/ERP_RMI_SOFULL
php tools/release/create_clean_deploy_zip.php
```

3. ZIP tersimpan di: `exports/deploy/YYYY-MM/ERP_RMI_SOFULL_deploy_clean_*.zip`

4. **Upload ke NAS** (jika buat dari Mac):

```bash
scp /Volumes/web/ERP_RMI_SOFULL/exports/deploy/2026-03/ERP_RMI_SOFULL_deploy_clean_*.zip \
  RizqullahMediska@10.10.60.20:/volume4/web/ERP_RMI_SOFULL/exports/deploy/2026-03/
```

5. **Extract di NAS**:

```bash
ssh RizqullahMediska@10.10.60.20
cd /volume4/web/ERP_RMI_SOFULL
unzip -o exports/deploy/2026-03/ERP_RMI_SOFULL_deploy_clean_*.zip -d .
```

---

## Setup SSH (Agar Tanpa Password)

```bash
# Generate key (jika belum punya)
ssh-keygen -t ed25519 -C "your@email.com"

# Copy ke NAS
ssh-copy-id RizqullahMediska@10.10.60.20
```

Setelah itu, `rsync` dan `scp` bisa langsung tanpa ketik password.

---

## Setelah Deploy

1. **Clear browser cache:** Ctrl+Shift+R (Windows) / Cmd+Shift+R (Mac)
2. **PHP opcache** (jika perlu): restart web server di NAS
   - DSM → Package Center → Web Station → restart
   - Atau: `sudo systemctl restart nginx` (jika pakai nginx)

---

## Verifikasi

```bash
# Cek di NAS
ssh RizqullahMediska@10.10.60.20
grep -n "emptyTable" /volume4/web/ERP_RMI_SOFULL/master/master_vendors.php
```

Jika muncul baris `language: { emptyTable: 'Belum ada data vendors.' }` → deploy berhasil.

---

## Ringkasan Cepat

| Kebutuhan | Perintah |
|-----------|----------|
| Edit satu file, sudah mount SMB | Save saja |
| Deploy cepat (semua file) | `./scripts/sync_to_nas.sh` |
| Deploy satu file | `rsync` atau `scp` |
| Deploy banyak file | `rsync` dengan exclude |
| Deploy full release | Create ZIP → upload → extract |
