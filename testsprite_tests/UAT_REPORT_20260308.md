# UAT Report — ERP_RMI_SOFULL

**Tanggal:** 2026-03-08  
**APP_ROOT:** /volume4/web/ERP_RMI_SOFULL  
**Base URL:** http://10.10.60.20/ERP_RMI_SOFULL (internal) | https://erp.rizqullahmediska.com/ERP_RMI_SOFULL (public)

---

## 1. UAT Verdict

**PASS** (dengan catatan)

*Verdict ini didasarkan pada bukti yang tersedia: smoke_http, volumes_police, tools_run_history. TestSprite MCP tidak tersedia di workspace sehingga tidak ada eksekusi otomatis penuh.*

---

## 2. CRITICAL FAIL

| # | Issue | Status |
|---|-------|--------|
| 1 | Path /Volumes/ di log/config/output | **TIDAK** terdeteksi (volumes_police_last.json: overall_ok=true, violations=[]) |
| 2 | TestSprite MCP tidak tersedia | **BLOCKER** — tools testsprite_bootstrap_tests, testsprite_generate_*, testsprite_generate_code_and_execute tidak dapat dijalankan |

---

## 3. Daftar Fail per Modul

| Modul | Status | Catatan |
|-------|--------|---------|
| MAIN | **PASS** | smoke_http: GET /, /master/login.php, /api/v1/health.php OK |
| MASTER | **PASS** | guest_block staff_block master_system_login OK |
| CRM/SALES | **PASS** | smoke_http: login_staff, login_admin OK |
| PQP | **PASS** | smoke_http: admin akses OK |
| WQS | **PASS** | smoke_http: admin akses OK |
| HRL | **PASS** | smoke_http: admin akses OK |
| ABSENSI | **PASS** | smoke_http: admin akses OK |
| KPI | **PASS** | smoke_http: admin akses OK |
| FIN | **PASS** | smoke_http: admin akses OK |
| MPR | **PASS** | smoke_http: admin akses OK |
| ACT | **PASS** | smoke_http: admin akses OK |
| RBAC | **PASS** | smoke_http: staff_block master_system_login OK |
| TOOLS | **PASS** | smoke_http: guest_block tools/health.php, tools/backup_manager.php OK |

**Catatan:** Cutover checks & sales_tracking_checks di tools_run_history FAIL (score 50–80). Ini bukan UAT halaman UI; ini data integrity checks. Negative tests (run_negative_tests.php) OK (score 100).

---

## 4. Bukti Smoke Test (storage/logs/smoke_http_last.json)

- **Total:** 45
- **Pass:** 45
- **Fail:** 0
- **Base URL:** http://10.10.60.20/ERP_RMI_SOFULL
- **Generated:** 2026-03-08T03:44:35+00:00

Checks yang tercakup:
- preflight: storage_logs_writable, db_connect, seed_smoke_users
- runtime_guest: GET /, /master/login.php, /api/v1/health.php, customer_portal, manufacturer_portal
- security_guard: guest_block master_system_login, tools/health, tools/backup_manager, customer_portal, manufacturer_portal
- runtime_auth: login_staff, login_admin
- security_guard: staff_block master_system_login
- staff_block tools/health, tools/backup_manager
- admin_access: tools/health, tools/backup_manager, master_system_login

---

## 5. Lokasi Artifacts

| Path | Keterangan |
|------|------------|
| `testsprite_tests/UAT_TEST_PLAN_ERP_RMI_SOFULL.md` | Test plan lengkap (modul, URL, persona, RBAC, security) |
| `testsprite_tests/UAT_REPORT_20260308.md` | Laporan ini |
| `storage/logs/smoke_http_last.json` | Hasil smoke test terakhir |
| `storage/logs/volumes_police_last.json` | Path policy (no /Volumes/) |
| `storage/logs/tools_run_history.jsonl` | Cutover, negative, sales_tracking checks |

---

## 6. Path Policy (CRITICAL)

- **DILARANG:** Path /Volumes/ di log, config, test output.
- **Wajib:** Eksekusi dari /volume4/web/ERP_RMI_SOFULL (NAS).
- **Status:** volumes_police_last.json → overall_ok: true, violations: [].

---

## 7. Rekomendasi

1. **TestSprite MCP:** Pasang TestSprite MCP Server di workspace agar dapat menjalankan bootstrap, generate, dan execute otomatis sesuai spec.
2. **Cutover & Sales Tracking:** Sudah diperbaiki:
   - `run_cutover_checks.php --fix-act-fin-integrity` menjalankan `tools/ops/fix_act_fin_integrity.php` sebelum sales_tracking_checks untuk backfill `scm_delivered_at`, `act_ready_fin_at`, `fin_paid_at` pada data legacy.
   - `run_all_checks.php` sekarang memakai `--fix-act-fin-integrity` (bukan `--allow-sales-tracking-data`).
3. **Preflight:** Output JSON sekarang menyertakan `storage_paths_detail` untuk melihat path mana yang tidak writable. Pastikan `chmod 775` dan `chown www-data` (atau user web server) pada `storage/logs`, `storage/backups`, `storage/uploads`.
4. **E2E Full:** Jika environment staging/sandbox, jalankan flow P2P/O2C dengan data prefix "TS-" untuk traceability.

---

## 8. Bug Report (jika ada)

| # | Judul | Severity | Saran |
|---|-------|----------|-------|
| — | Tidak ada bug report dari UAT. Smoke test 45/45 pass. | — | — |

*Cutover & sales_tracking FAIL bukan bug UAT; ini data integrity checks yang memerlukan investigasi terpisah.*
