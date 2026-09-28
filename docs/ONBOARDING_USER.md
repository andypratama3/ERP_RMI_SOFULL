# ONBOARDING USER — ERP_RMI_SOFULL

> **Lokasi lengkap:** [`docs/onboarding/ONBOARDING.md`](onboarding/ONBOARDING.md)  
> **RBAC:** `docs/governance/RBAC_MATRIX_RMI_v1.md`

---

## Buat User Baru

1. Login sebagai **SYS** → `master/master_system_login.php`
2. Klik "Tambah User", isi:

| Field | Isi | Contoh |
|-------|-----|--------|
| Username | unik, konvensi: RoleDept_Office | `StaffCRM_BGR` |
| Dept | ITC, MPR, CRM, SCM, ACT, FIN, HRL, WQS, PQP, BRANCH, SYS | `CRM` |
| Office | BGR, BDG, BKS, TGR, SLO, SMG, JGY, KAL, SYS | `BGR` |
| Role | manager atau staff | `staff` |
| Status | ACTIVE | `ACTIVE` |

---

## Assign Permission (RBAC)

1. Buka **RBAC Center** → `rbac/index.php` (SYS only)
2. Pilih user → assign permission sesuai: `docs/governance/RBAC_MATRIX_RMI_v1.md`

---

## Aturan Khusus

| Aturan | Detail |
|--------|--------|
| **FIN approval** | Hanya `MgrFIN_BGR` + SYS yang approve/pay pengeluaran AP. FIN cabang lain: view saja. |
| **RBAC manage** | SYS only. Permission: `SYSTEM.RBAC_MANAGE`, `SYSTEM.USER_MANAGE` |
| **ITC** | Sama seperti dept lain — tidak ada hak istimewa otomatis. |
| **Tools/ops** | SYS only. Non-SYS → 403. |

---

## Verifikasi Akses

```bash
# Setelah user dibuat, jalankan RBAC matrix check (dari NAS):
./tools/nas/erp.sh php tools/qa/rbac_matrix_http_check.php --strict --write-last
# Artifact: storage/logs/rbac_matrix_http_check_last.json
```

---

## Cabang / Office Baru

1. `master/master_office.php` → Tambah record (code 3 huruf: BGR, BDG, dll.)
2. Update mapping di `_stock_office_helper.php` jika perlu depo default
3. Lihat: [`docs/governance/NEW_BRANCH.md`](governance/NEW_BRANCH.md)

---

## Reset Password

```bash
# Via ITC tool (SYS/ITC):
# tools/itc_reset_password.php
```

---

## Referensi

- [`docs/onboarding/ONBOARDING.md`](onboarding/ONBOARDING.md) — Panduan lengkap
- [`docs/onboarding/NEW_USER.md`](onboarding/NEW_USER.md) — Detail user baru
- [`docs/governance/NEW_BRANCH.md`](governance/NEW_BRANCH.md) — Tambah cabang
