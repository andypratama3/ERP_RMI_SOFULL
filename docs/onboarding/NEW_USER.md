# New User — Buat User, Assign Dept, Office, Role

**Lokasi lengkap:** [ONBOARDING.md](ONBOARDING.md)

---

## Ringkasan

1. **Buat user:** Master System Login → Tambah User
2. **Assign dept:** ITC, MPR, CRM, SCM, ACT, FIN, HRL, WQS, PQP, BRANCH, SYS
3. **Office/depo scope:** BGR, BDG, BKS, TGR, SLO, SMG, JGY, KAL, SYS
4. **Role:** manager / staff
5. **Reset password:** SYS via Master System Login; ITC via `master/itc_reset_password.php`
6. **MFA:** (jika ada) — konfigurasi di profile user

## Aturan Wajib

- **FIN approval:** Hanya MgrFIN_BGR (dept=FIN, level=MANAGER, office=BGR) + SYS
- **RBAC/Tools:** SYS ONLY. ITC tidak punya akses /tools/* atau /rbac/*
- **Format username:** `{Prefix}{Dept}_{Office}` — MgrCRM_BGR, StaffFIN_BKS
