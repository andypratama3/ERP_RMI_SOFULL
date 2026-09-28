# Business Sign-off — Finalisasi & Verifikasi

## Ringkasan Perubahan

### A) Entry & Handler
- **Entry:** `tools/signoff/business_signoff.php`
- **Bootstrap:** `tools_remote_check`, `helpers`, `auth`, `tools_ui_helpers`, `tools_state_lib`, `tools_access_helpers`, `rmi_layout`, `db`, `_audit_master`
- **Guard:** `tools_require_access`, `require_login`, `require_role(['ADMIN','SUPERADMIN'])`
- **CSRF:** `verify_csrf` / `rmi_csrf_validate` pada GET (token di form) dan POST
- **Output:** `rmi_h()` untuk escape HTML

### B) Storage
- **State:** `storage/logs/business_signoff.last.json` (canonical)
- **Backward compat:** Baca dari `business_signoff.last.json` dulu, fallback `business_signoff_last.json`
- **Evidence:** `storage/uploads/business_signoff/`
- **Filename:** `business_signoff_<YYYYMMDD_HHMMSS>_<random>.<ext>`
- **Validasi upload:** max 10MB, ext: pdf/png/jpg/jpeg, MIME via finfo_file
- **State payload:** signed, signed_at, approver_name, department_role, evidence_file (masked), sha256, request_id, app_env, base_url, notes (masked)

### C) Audit Log
- `BUSINESS_SIGNOFF_SUBMITTED` — saat submit sukses
- `BUSINESS_SIGNOFF_REVOKED` — saat revoke (SUPERADMIN only)

### D) Cutover Gate
- **Script:** `tools/qa/business_signoff_check.php`
- **Step:** `business_signoff` di `run_cutover_checks.php`
- **Env:** `CUTOVER_REQUIRE_BUSINESS_SIGNOFF=1` (default) → wajib signed
- **Cek:** state ada, signed=true, sha256 ada, evidence file exists

### E) Revoke (opsional)
- Tombol "Revoke / Reset sign-off" — hanya SUPERADMIN/SYS
- CSRF protected, konfirmasi JS

---

## Langkah Verifikasi (Jalankan di NAS: `/volume4/web/ERP_RMI_SOFULL`)

```bash
# 1. Lint
php -l tools/signoff/business_signoff.php
php -l tools/tools_state_lib.php
php -l tools/qa/business_signoff_check.php
php -l tools/qa/generate_signoff_verdict.php
php -l tools/index.php
php -l tools/ops/executive_ops_summary.php
php -l tools/qa/run_cutover_checks.php

# 2. Cutover (signoff optional untuk testing)
CUTOVER_REQUIRE_BUSINESS_SIGNOFF=0 php tools/qa/run_cutover_checks.php --write-last

# 3. Cutover (signoff required — akan FAIL jika belum signed)
CUTOVER_REQUIRE_BUSINESS_SIGNOFF=1 php tools/qa/run_cutover_checks.php --write-last

# 4. Smoke tools dashboard
php tools/qa/smoke_tools_dashboard.php --base-url=http://10.10.60.20/ERP_RMI_SOFULL

# 5. Pastikan tidak ada path /Volumes/ di log/state
grep -r "/Volumes/" storage/logs/ 2>/dev/null && echo "FAIL: found /Volumes/" || echo "OK: no /Volumes/"
```

---

## Evidence Checklist

1. `storage/logs/business_signoff.last.json` — setelah submit via UI
2. Evidence file di `storage/uploads/business_signoff/`
3. UI menampilkan Signed: YES dan SHA256
4. Cutover report menunjukkan step `business_signoff` (PASS jika signed, FAIL jika belum)
