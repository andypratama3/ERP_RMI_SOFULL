# Cara Reset Data ERP — Saat Tim Sudah Siap Production

Panduan reset data ketika tim selesai coba-coba dan siap pakai data bersih untuk production.

---

## Opsi 1: Reset Database ke Fresh (Paling Bersih)

**Langkah:** Drop database → Import ulang schema + seed.

### Via phpMyAdmin

1. Buka **phpMyAdmin** (http://[IP-NAS]/phpMyAdmin)
2. Pilih database `erp_rmi_sofull` (atau `ERP_RMI_SOFULL` sesuai .env)
3. Tab **Operasi** → **Hapus database** (Drop) — **HATI-HATI: semua data hilang**
4. Buat database baru:
   ```sql
   CREATE DATABASE erp_rmi_sofull CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
5. Tab **Impor** → pilih file `sql/ERP_RMI_SOFULL.sql` → **Jalankan**

### Via SSH (NAS)

```bash
cd /volume4/web/ERP_RMI_SOFULL

# Baca config dari .env (sesuaikan jika beda)
# ERP_DB_HOST, ERP_DB_NAME, ERP_DB_USER, ERP_DB_PASS

mysql -h 127.0.0.1 -u root -p -e "DROP DATABASE IF EXISTS erp_rmi_sofull; CREATE DATABASE erp_rmi_sofull CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

mysql -h 127.0.0.1 -u root -p erp_rmi_sofull < sql/ERP_RMI_SOFULL.sql

# Jalankan migration tambahan (jika ada setelah ERP_RMI_SOFULL.sql)
# php tools/nas/run_all_migrations.sh  # jika script ada
```

**Hasil:** Database kosong seperti instalasi baru. Login default: **admin** / **1234** (ganti segera!).

---

## Opsi 2: Backup Dulu → Tim Coba → Restore

Cocok jika ingin **rollback** ke kondisi sebelum tim coba-coba.

### Sebelum tim coba

```bash
cd /volume4/web/ERP_RMI_SOFULL
./tools/backup_now.sh
# Backup tersimpan di storage/backups/ERP_RMI_SOFULL_backup_YYYYMMDD_HHMMSS/
```

### Setelah tim selesai coba (restore ke backup)

```bash
cd /volume4/web/ERP_RMI_SOFULL

# Lihat daftar backup
ls -la storage/backups/

# Restore (dry-run dulu)
./tools/restore_now.sh --from storage/backups/ERP_RMI_SOFULL_backup_YYYYMMDD_HHMMSS --restore-db --dry-run

# Apply restore
./tools/restore_now.sh --from storage/backups/ERP_RMI_SOFULL_backup_YYYYMMDD_HHMMSS --restore-db --apply
```

---

## Opsi 3: Via Web (Tools)

Jika Tools tersedia dan Anda punya akses:

- **Backup Manager** → Buat backup
- **Restore** → Pilih package backup → Restore DB

---

## Checklist Sebelum Reset

- [ ] Pastikan tim sudah selesai uji
- [ ] Backup data penting (jika ada yang ingin disimpan)
- [ ] Catat user/password yang akan dipakai setelah reset
- [ ] Setelah reset: ganti password admin, aktifkan MFA jika perlu

---

## Catatan

- **Opsi 1** = paling bersih, seperti instalasi baru
- **Opsi 2** = rollback ke kondisi backup (bisa berisi data uji)
- File aplikasi (kode) **tidak** ter-reset — hanya database
