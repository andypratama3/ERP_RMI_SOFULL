# Checklist / SOP Admin: Membuat User BRANCH (Depo)

Panduan langkah demi langkah untuk membuat dan mengaktifkan akun **BRANCH** (karyawan tunggal di kantor/depo yang mengerjakan semua proses: sales, warehouse, procurement, finance, dll.).

---

## Prasyarat

- [ ] Login sebagai **ADMIN** atau **SUPERADMIN**
- [ ] Migration **106**, **107**, **108**, **109** sudah dijalankan (BRANCH dept, MFA policy, RBAC seed)
- [ ] Data `master_employees` dan `master_office` sudah lengkap
- [ ] Kantor/depo target sudah ada di `master_office`

---

## Langkah 1: Pastikan Departemen BRANCH Tersedia

1. Buka **Master → Departemen** (atau cek `master_departements`)
2. Pastikan ada baris dengan `dept_code = 'BRANCH'` dan `level_type = 'Staff'`
3. Jika belum: jalankan migration `sql/migrations/107_seed_departemen_standar.sql`

---

## Langkah 2: Buat/Buatkan Employee

1. Buka **Master → Employees** (`master/master_employees.php`)
2. Pastikan karyawan depo sudah ada dengan:
   - `employee_code` unik (contoh: `BGR250101`)
   - `office_code` = kode kantor/depo (contoh: `bgr`, `kal`, `jgy`)
   - Status aktif

---

## Langkah 3: Buat Akun Login

1. Buka **Master → System Login** (`master/master_system_login.php`)
2. Klik **Tambah User** (atau gunakan **Seed Akun** jika tersedia untuk BRANCH)
3. Isi:
   - **Username**: mis. `StaffBRANCH_BGR` (ikuti konvensi `Staff{DEPT}_{OFFICE}`)
   - **Full Name**: nama lengkap karyawan
   - **Role**: `STAFF`
   - **Level**: `STAFF`
   - **Department**: `BRANCH`
   - **Office Code**: kode kantor (mis. `bgr`)
   - **Status**: `ACTIVE`
4. Set password awal (default sementara atau kirim reset link)
5. Simpan

---

## Langkah 4: Set Holder

1. Di halaman yang sama, cari user yang baru dibuat
2. Klik **Edit**
3. Di section **Holder Employee (Akun Jabatan)**:
   - Isi **Employee Code** = `employee_code` dari Langkah 2
   - Klik **Set Holder**

**Alternatif massal:** gunakan Import Holder CSV (lihat `docs/BRANCH_Set_Holder_Panduan.md`)

---

## Langkah 5: Verifikasi Akses

1. Logout, login dengan akun BRANCH yang baru
2. Pastikan redirect ke **Dashboard Center** (`/dashboards/index.php`)
3. Cek card yang tampil:
   - [ ] Sales (DO, CRM Leads)
   - [ ] Stock (WQS, PR View)
   - [ ] Purchases
   - [ ] Finance
   - [ ] HRL
   - [ ] Master Data
   - [ ] MPR
   - [ ] Fixed Asset
4. Uji akses ke modul utama:
   - [ ] Sales DO
   - [ ] Stock / WQS
   - [ ] Purchases
   - [ ] KPI Dashboard (jika relevan)

---

## Langkah 6: Dokumentasi & Audit

- [ ] Catat username dan office di daftar akun BRANCH
- [ ] Cek `master/account_readiness.php` — pastikan akun tidak ada warning
- [ ] Audit log: `uploads/audit_logs/audit_master_system_login.log`

---

## Ringkasan Cepat

| Langkah | Aksi |
|---------|------|
| 1 | Pastikan dept BRANCH ada |
| 2 | Employee ada di master_employees |
| 3 | Buat user (BRANCH, STAFF, office) |
| 4 | Set holder → employee_code |
| 5 | Tes login & akses |
| 6 | Dokumentasi |

---

## Referensi

- `docs/BRANCH_Set_Holder_Panduan.md` — Panduan Set Holder & Import CSV
- `TEMPLATES/master_system_login_holder_assign_template.csv` — Template CSV holder
- `_shared/rbac.php` — Konfigurasi permission BRANCH|STAFF
