# ONBOARDING — User Baru, Office/Depo Baru

Panduan menambah user baru dan office/depo baru di ERP_RMI_SOFULL.

---

## 1. User Baru

### 1.1 Tambah User

1. Login sebagai SYS (atau user dengan `SYSTEM.USER_MANAGE`)
2. Buka **Master System Login**: `master/master_system_login.php`
3. Klik "Tambah User"
4. Isi:
   - **Username** — unik, lowercase (contoh: `StaffCRM_BGR`)
   - **Password** — minimal 8 karakter
   - **Dept** — pilih dari: ITC, MPR, CRM, SCM, ACT, FIN, HRL, WQS, PQP, BRANCH, SYS
   - **Office** — pilih dari master office (BGR, BDG, BKS, TGR, SLO, SMG, JGY, KAL, SYS)
   - **Role** — manager atau staff
   - **Status** — ACTIVE

### 1.2 Assign Permission (RBAC)

1. Buka **RBAC Center**: `rbac/index.php`
2. Pilih user atau dept
3. Assign permission sesuai matrix: `docs/governance/RBAC_MATRIX_RMI_v1.md`
4. Sinkronkan: jalankan sync jika ada tombol

### 1.3 Aturan Khusus

| Aturan | Keterangan |
|--------|------------|
| **FIN approval** | Hanya `MgrFIN_BGR` + SYS yang boleh approve/pay pengeluaran. MgrFIN cabang lain: view only. |
| **RBAC management** | Hanya SYS. Permission: `SYSTEM.RBAC_MANAGE`, `SYSTEM.USER_MANAGE` |
| **ITC** | Default = dept biasa. Tidak otomatis dapat hak khusus. SYS yang assign jika perlu. |

### 1.4 Verifikasi

- User login → cek menu sesuai permission
- Staff: tidak boleh akses halaman manager-only
- FIN: hanya MgrFIN_BGR yang bisa approve AP payment

---

## 2. Office / Depo Baru

### 2.1 Tambah Office di Master

1. Buka **Master Office**: `master/master_office.php` (atau modul master data terkait)
2. Tambah record:
   - **Code** — 3 huruf (contoh: `JGY` untuk Yogyakarta)
   - **Name** — nama lengkap
   - **Type** — depo/cabang/ho sesuai kebutuhan
   - **Status** — ACTIVE

### 2.2 Office Code yang Valid

| Code | Keterangan |
|------|------------|
| BGR | Bogor (depo utama) |
| BDG | Bandung |
| BKS | Bekasi |
| TGR | Tangerang |
| SLO | Solo |
| SMG | Semarang |
| JGY | Yogyakarta |
| KAL | Kalimantan / cabang lain |
| SYS | System |

**Default office** saat kosong: **BGR** (bukan HO). Lihat `stock/_stock_office_helper.php`.

### 2.3 Update User ke Office Baru

1. Edit user di `master/master_system_login.php`
2. Ubah **Office** ke office baru
3. Pastikan permission RBAC tetap sesuai (beberapa permission scoped by office)

### 2.4 Stock / WQS

Jika office baru dipakai untuk stock/WQS:

- Cek `wqs_stock_default_office()` di `stock/_stock_office_helper.php`
- Default fallback: BGR
- Pastikan transaksi stock memakai office_code yang benar

---

## 3. Checklist Onboarding User

- [ ] User dibuat di master_system_login
- [ ] Dept + Office + Role di-set
- [ ] Permission di-assign via RBAC Center
- [ ] FIN: jika manager FIN, pastikan hanya MgrFIN_BGR yang approve (kecuali SYS override)
- [ ] User bisa login dan akses menu sesuai role
- [ ] Smoke/RBAC matrix: `run_cutover_checks --strict` tetap PASS

---

## 4. Checklist Onboarding Office

- [ ] Office ditambah di master_office
- [ ] Code 3 huruf, unique
- [ ] User yang perlu di-assign ke office baru
- [ ] Stock/WQS: cek default office logic jika relevan

---

*Update: Final Hardening Execution.*
