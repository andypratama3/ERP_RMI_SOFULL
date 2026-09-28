# TestSprite / UAT Artifacts — ERP_RMI_SOFULL

**APP_ROOT:** /volume4/web/ERP_RMI_SOFULL  
**Source of truth:** docs/ERP_MENU_WORKFLOW_REFERENCE.md

## Isi

| File | Keterangan |
|------|------------|
| UAT_TEST_PLAN_ERP_RMI_SOFULL.md | Test plan lengkap: modul, URL, persona (GUEST/STAFF/ADMIN), RBAC, security, flow P2P/O2C |
| UAT_REPORT_20260308.md | Laporan UAT dengan verdict, CRITICAL FAIL, daftar fail per modul |

## Eksekusi

- **Wajib:** Jalankan dari NAS (`cd /volume4/web/ERP_RMI_SOFULL`).
- **Smoke:** `php tools/smoke_http.php` → output ke `storage/logs/smoke_http_last.json`.
- **Path policy:** `php tools/qa/volumes_police.php` → pastikan `overall_ok: true`.
- **Cutover (dengan fix ACT/FIN):** `php tools/qa/run_cutover_checks.php --fix-act-fin-integrity --write-last`
- **Fix ACT/FIN integrity (standalone):** `php tools/ops/fix_act_fin_integrity.php` — backfill scm_delivered_at, act_ready_fin_at, fin_paid_at untuk data legacy.

## TestSprite Regression Suite

**CRITICAL:** Harus dijalankan dari NAS. Jika path mengandung `/Volumes/` → CRITICAL FAIL, STOP.

```bash
# Dari NAS
cd /volume4/web/ERP_RMI_SOFULL
php tools/qa/testsprite_regression.php
```

Output:
- `storage/logs/testsprite_last_summary.json`
- `storage/logs/testsprite_last_summary.md`
- `storage/logs/testsprite_last_evidence/` (folder bukti)

**Gate parser** (exit 0 = pass, 2 = fail):
```bash
php tools/qa/testsprite_gate.php
```

Credentials: `TS_ADMIN_USER`, `TS_ADMIN_PASS`, `TS_STAFF_USER`, `TS_STAFF_PASS` atau fallback `SMOKE_*` / default smoke_admin, smoke_staff.

## TestSprite MCP

Jika TestSprite MCP terpasang, jalankan berurutan:

1. testsprite_bootstrap_tests (projectPath=/volume4/web/ERP_RMI_SOFULL, type=frontend)
2. testsprite_generate_code_summary
3. testsprite_generate_standardized_prd
4. testsprite_generate_frontend_test_plan
5. testsprite_generate_code_and_execute (NON-DESTRUCTIVE, RBAC enforced)
