# Troubleshooting: Reset Password & Login Gagal

## Masalah: Reset password berhasil tapi tetap tidak bisa login (contoh: StaffWQS)

### Penyebab Umum & Langkah Perbaikan

#### 1. **Status user bukan ACTIVE**
- **Gejala:** Pesan "Akun dinonaktifkan, hubungi admin."
- **Cek:** Di `master/master_system_login.php` → cari user StaffWQS → kolom **Status** harus `ACTIVE`.
- **Perbaikan:** Klik Edit user → ubah Status ke `ACTIVE` → Simpan.

#### 2. **User ter-soft-delete (deleted_at terisi)**
- **Gejala:** Pesan "Akun dinonaktifkan, hubungi admin."
- **Cek:** Jalankan di DB:
  ```sql
  SELECT id, username, status, deleted_at FROM master_system_login WHERE username LIKE '%StaffWQS%';
  ```
  Jika `deleted_at` tidak NULL → user dihapus.
- **Perbaikan:** Restore user:
  ```sql
  UPDATE master_system_login SET deleted_at = NULL WHERE username = 'StaffWQS';
  ```

#### 3. **Password hash tidak tersimpan benar**
- **Gejala:** Pesan "Kredensial tidak valid."
- **Cek:** Pastikan reset password dilakukan dari halaman yang benar:
  - **SYS/Admin:** `master/master_system_login.php` → Edit user → Reset Password
  - **ITC Manager:** `master/itc_reset_password.php` (hanya untuk dept tertentu, bukan SYS/FIN Manager)
- **Perbaikan:** Reset ulang dari `master/master_system_login.php` (login sebagai admin/superadmin):
  1. Buka `/ERP_RMI_SOFULL/master/master_system_login.php`
  2. Cari user StaffWQS
  3. Klik Edit (ikon pensil)
  4. Klik "Reset Password"
  5. Masukkan password baru (min 8 karakter, disarankan kombinasi huruf+angka)
  6. Simpan

#### 4. **Username case-sensitive / typo**
- **Gejala:** "Kredensial tidak valid" atau "User tidak ditemukan"
- **Cek:** Username di form login harus **persis** sama dengan di DB (huruf besar/kecil).
- **Perbaikan:** Cek di `master_system_login` kolom `username` — gunakan exact match saat login.

#### 5. **Login throttle (terlalu banyak gagal)**
- **Gejala:** "Login gagal. Coba lagi beberapa menit."
- **Penyebab:** Terlalu banyak percobaan login gagal dari IP/username yang sama.
- **Perbaikan:** Tunggu 5–15 menit, atau hapus record di tabel `auth_login_attempts` (jika ada):
  ```sql
  DELETE FROM auth_login_attempts WHERE username = 'StaffWQS' OR ip_address = 'IP_ANDA';
  ```

#### 6. **MFA wajib tapi belum diaktifkan**
- **Gejala:** "Akun Anda wajib MFA sesuai kebijakan."
- **Perbaikan:** Aktifkan MFA via admin/security setting, atau minta SYS bypass sementara.

---

## Langkah Cepat untuk StaffWQS

1. **Login sebagai SYS** (admin/superadmin/SmokeSYS_SYS).
2. Buka **Master System Login:** `/ERP_RMI_SOFULL/master/master_system_login.php`
3. Cari **StaffWQS** (filter/search).
4. Pastikan:
   - Status = **ACTIVE**
   - deleted_at = kosong (jika kolom tampil)
5. Klik **Edit** → **Reset Password** → masukkan password baru (misal: `StaffWQS123`) → Simpan.
6. **Logout** dari SYS, lalu login dengan:
   - Username: `StaffWQS` (sesuai DB)
   - Password: yang baru di-set

---

## Jika Masih Gagal: Cek via SQL

```sql
-- Cek data user StaffWQS
SELECT id, username, status, deleted_at, 
       CASE WHEN password_hash IS NULL OR password_hash = '' THEN 'KOSONG' ELSE 'ADA' END AS pwd_status
FROM master_system_login 
WHERE username LIKE '%StaffWQS%';
```

- `pwd_status` harus **ADA**
- `status` harus **ACTIVE**
- `deleted_at` harus **NULL**

---

## Reset via SQL (darurat)

Jika UI reset tidak berfungsi, SYS bisa reset langsung di DB:

```sql
-- Ganti 'PasswordBaru123' dengan password yang diinginkan
UPDATE master_system_login 
SET password_hash = '$2y$10$...' 
WHERE username = 'StaffWQS';
```

**Catatan:** Nilai `password_hash` harus dari `password_hash('PasswordBaru123', PASSWORD_DEFAULT)` di PHP. Lebih aman gunakan UI reset.

---

## Referensi

- Login logic: `master/login.php`
- Denial reason: `_shared/login_policy.php` → `rmi_login_denial_reason()`
- Reset password (SYS): `master/master_system_login.php` (op=reset_password)
- Reset password (ITC): `master/itc_reset_password.php`
