# PATCH_DIFF_LAST_POLICE_VOLUMES_RBAC

**Tanggal:** 2026-03-04  
**Scope:** Polisi Terakhir NAS-Only + RBAC All Modules V1

---

## File Changed

### A) Workspace Lock & Safe I/O

| File | Reason |
|------|--------|
| `tools/_shared/workspace_lock.php` | **NEW** — tools_get_app_root(), tools_assert_workspace_lock(), tools_is_forbidden_path(), tools_guard_forbidden_path() |
| `storage/.app_root_lock` | **NEW** — Lock marker: /volume4/web/ERP_RMI_SOFULL |
| `tools/_shared/safe_io.php` | **NEW** — tools_safe_write_file(), tools_safe_write_json(), tools_safe_append_jsonl() dengan path guard |
| `tools/tools_state_lib.php` | ts_write_json() delegasi ke tools_safe_write_json bila safe_io tersedia |

### A3) Volumes Guard

| File | Reason |
|------|--------|
| `tools/qa/volumes_guard.php` | **NEW** — QA check: block /Volumes, CRITICAL FAIL jika ada |
| `tools/qa/run_cutover_checks.php` | volumes_guard step pertama, stop-early jika fail |
| `tools/nas/run_all_tools_auto.sh` | volumes_guard sebelum run_all_checks, exit 3 jika fail |

### B) RBAC All Modules

| File | Reason |
|------|--------|
| `docs/governance/RBAC_ALL_MODULES_V1.md` | **NEW** — Daftar role & permission codes V1 |
| `tools/rbac/seed_rbac_all_modules.php` | **NEW** — Seeder idempotent, --dry-run default, --apply --i-understand |
| `tools/qa/rbac_smoke.php` | **NEW** — RBAC smoke: guest→302/401, staff→403, admin→200 |
| `tools/qa/run_cutover_checks.php` | rbac_smoke step setelah smoke_http |

### B3) Enforce Guards

- **Skipped (minimal scope):** Modul bootstrap guard tidak di-rewrite. Halaman existing sudah punya require_login/require_permission di banyak tempat. Full enforcement bisa fase berikutnya.

---

## Evidence Output

- `storage/logs/volumes_guard_last.json` — dihasilkan saat run di NAS (ok=true)
- `storage/logs/rbac_seed_last.json` — dihasilkan oleh seed_rbac_all_modules.php
- `storage/logs/rbac_smoke_last.json` — dihasilkan oleh rbac_smoke.php

---

## Runbook

```bash
cd /volume4/web/ERP_RMI_SOFULL || exit 2

# 1) Lint
php -l tools/_shared/workspace_lock.php
php -l tools/_shared/safe_io.php
php -l tools/qa/volumes_guard.php
php -l tools/rbac/seed_rbac_all_modules.php
php -l tools/qa/rbac_smoke.php

# 2) Volumes guard (PASS di NAS)
php tools/qa/volumes_guard.php --write-last || exit 3

# 3) Seed RBAC dry-run
php tools/rbac/seed_rbac_all_modules.php --dry-run --write-last || exit 4

# 4) Apply RBAC (production only, explicit)
APP_ENV=production php tools/rbac/seed_rbac_all_modules.php --apply --i-understand --write-last || exit 5

# 5) Full cutover
php tools/qa/run_cutover_checks.php --write-last --strict || exit 6
```

---

## Gate PASS Final

- volumes_guard ok=true
- rbac_smoke overall_ok=true
- run_cutover_checks overall_ok=true
