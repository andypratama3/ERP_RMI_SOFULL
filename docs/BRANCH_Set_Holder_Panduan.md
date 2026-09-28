# Panduan Set Holder (Mapping Akun ke Employee)

Dokumen ini menjelaskan cara mengatur **Holder** pada akun sistem—yaitu mapping antara username login dengan `employee_code` di `master_employees`. Holder digunakan untuk identifikasi pemegang akun, audit trail, absensi, dan modul lain yang membutuhkan data karyawan.

---

## 1. Konsep Holder

| Istilah | Keterangan |
|---------|------------|
| **Holder** | Pemegang akun—karyawan yang secara resmi memegang username tersebut |
| **holder_employee_code** | Kode karyawan di `master_employees.employee_code` |
| **Set Holder** | Menetapkan/mengganti pemegang akun |
| **Clear Holder** | Melepas mapping (holder_employee_code = NULL) |

---

## 2. Cara Set Holder (Manual)

1. Login sebagai **ADMIN** atau **SUPERADMIN**
2. Buka **Master → System Login** (`master/master_system_login.php`)
3. Cari user yang ingin diatur, klik **Edit**
4. Di section **Holder Employee (Akun Jabatan)**:
   - Isi **Employee Code** (harus ada di `master_employees`)
   - Opsional: isi **Catatan**
   - Klik **Set Holder**
5. Untuk melepas holder: klik **Clear Holder**

---

## 3. Import Holder via CSV (Massal)

Untuk mapping banyak akun sekaligus, gunakan fitur **Import Holder CSV**.

### 3.1 Format CSV

**Header wajib:**
- `username` — username login (harus sudah ada di `master_system_login`)
- `holder_employee_code` — kode karyawan di `master_employees.employee_code`

**Header opsional:**
- `note` — catatan untuk audit

### 3.2 Template CSV

Gunakan file template: `TEMPLATES/master_system_login_holder_assign_template.csv`

```csv
username,holder_employee_code,note
StaffBRANCH_KAL,KAL250101,Holder Depo Kalimantan
StaffBRANCH_BGR,BGR250102,Holder Depo Bogor
```

### 3.3 Aturan Import

- **username** harus sudah ada di sistem
- **holder_employee_code** harus valid di `master_employees`
- Untuk **clear holder**: kosongkan kolom `holder_employee_code`
- Baris dengan username tidak ditemukan akan di-skip
- Encoding file: UTF-8 (disarankan)

### 3.4 Langkah Import

1. Buka `master/master_system_login.php`
2. Di panel **Import Holder CSV**, pilih file CSV
3. Klik **Import**
4. Cek hasil dan audit log

---

## 4. Khusus Akun BRANCH (Depo)

Untuk akun **BRANCH** (karyawan tunggal di kantor/depo):

1. Pastikan employee sudah ada di `master_employees` dengan `office_code` sesuai depo
2. Buat akun dengan department=BRANCH, level=STAFF, office_code=depo
3. Set holder ke `employee_code` karyawan tersebut
4. Verifikasi akses: login → Dashboard Center → semua card operasional (Sales, Stock, Purchases, dll.)

---

## 5. Verifikasi & Audit

- **Account Readiness**: `master/account_readiness.php` — cek akun yang belum punya holder
- **Audit Log**: `uploads/audit_logs/audit_master_system_login.log`
- **Handover History**: tabel `master_system_login_handover` mencatat perubahan holder

---

## 6. Referensi File

| File | Fungsi |
|------|--------|
| `TEMPLATES/master_system_login_holder_assign_template.csv` | Template CSV import holder |
| `master/master_system_login.php` | Halaman manage user + Set Holder + Import CSV |
| `master/account_readiness.php` | Cek kelengkapan akun |
