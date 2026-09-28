# ERP RMI SOFULL

PHP ERP untuk **Rizqullah Mediska Indonesia** (RIZKIMED).

---

## ⚠️ PENTING: Setup Tim — Agar Edit Terlihat

**Jika tim edit file tapi perubahan tidak muncul di web:** kemungkinan buka folder **lokal** (salah path).

**Solusi:** Buka project dari **share NAS** (Finder → RMI-2025 → web → ERP_RMI_SOFULL). Path harus `/Volumes/web/ERP_RMI_SOFULL`.

**Cek path:** `php tools/cek_workspace_path.php`  
**Panduan lengkap:** `docs/SETUP_TIM_EDIT_TERLIHAT.md`

**CLI di NAS:** Gunakan `./tools/nas/erp.sh php tools/xxx.php` agar memakai PHP dengan pdo_mysql (ERP_PHP_BIN dari .env). Lihat `docs/PHP_CLI_PDO_MYSQL_SYNOLOGY.md`.

---

## Panduan pengguna (per modul)

- **Indeks UI:** `/docs/modules_hub.php` (login) — kartu per modul. Jika ada **`panduan_in_app`** di `docs/modules/registry.json`, tombol utama mengarah ke halaman panduan **dalam ERP** (contoh: `purchases/panduan.php`); **Markdown** = ringkasan pelengkap.
- **Help Center:** `/docs/help_center.php` — kartu **PANDUAN ERP — PER MODUL** + pintasan monitoring & sesi.
- **Sumber teks:** `docs/modules/*.md` + `docs/modules/registry.json` — cara menambah modul / halaman `panduan.php`: `docs/modules/README.md`.

## Struktur Utama

```
├── _shared/          # Helpers, bootstrap, env
├── master/            # Auth, RBAC, login
├── config.php        # DB (mysqli), h()
├── config-db.php     # DB credentials (gitignore)
├── sales/             # Sales, DO, CRM
├── purchases/         # PO, GR, AP
├── stock/             # WQS, stock opname
├── dashboards/        # Finance, SCM, HRL, ITC, dll
├── api/               # Mobile API v1
├── tools/             # QA, smoke, backup, ops
├── storage/           # Logs, backups, state
└── sql/               # Migrations, schema
```

## Setup

1. **Database**
   - Copy `config-db.example.php` → `config-db.php`
   - Sesuaikan host, port, name, user, pass

2. **Env (opsional)**
   - Buat `.env` di root
   - Contoh: `APP_URL`, `APP_ENV`, `TOOLS_ALLOW_REMOTE`
   - **Email notifikasi:** `ENABLE_EMAIL_ALERTS=1` — lihat `docs/SETUP_EMAIL.md`

3. **Web server**
   - Document root ke folder project (atau subfolder `/ERP_RMI_SOFULL`)
   - PHP 8.1+ dengan mysqli, PDO, json, curl

## Konfigurasi URL (Tools / Smoke)

Tools QA dan smoke test memakai base URL dari env:

- `APP_URL` — URL aplikasi (contoh: `http://10.10.60.20/ERP_RMI_SOFULL`)
- `TOOLS_BASE_URL_INTERNAL` — prioritas tertinggi untuk tools
- `SMOKE_BASE_URL` — fallback smoke

Default: `http://127.0.0.1/ERP_RMI_SOFULL` (jika env kosong).

## Baselines (RBAC, Stock, Ops, MFA, Perf)

ERP punya beberapa baseline dengan makna berbeda. Lihat **`docs/BASELINES.md`** untuk glosarium, detail, dan checklist deploy.

| ID | Nama | Lokasi |
|----|------|--------|
| RBAC_DEFAULT | RBAC Default | `rbac/index.php` |
| STOCK_SNAPSHOT | Stock Snapshot | `stock/wqs_stock.php` |
| OPS_HARDENING | Ops Hardening Snapshot | Tools → Ops Hardening |
| MFA_PHASE | MFA Policy Phase | `master/mfa_policy.php` |
| PERF_BASELINE | Perf Baseline | `tools/perf/perf_baseline.php` |

## Coding Standards

Lihat `.cursor/rules/coding-standards.mdc`:

- PDO + prepared statements
- `rmi_h()` untuk output escaping
- CSRF, validasi input, auth

## Customer Portal (B2B)

Customer (mis. Hermina Purchasing) bisa order mandiri via portal:

- **URL:** `/customer_portal/login.php`
- **Flow:** Login → Katalog → Cart → Checkout → DO otomatis ke CRM
- **Kelola user:** Master Data → Customer Portal Users
- **Demo:** `hermina_demo` / `password` (setelah seed migration 138)
- **Fitur:** Filter Riwayat Order (status, tanggal), Multi-office (pilih kantor saat checkout), Email notifikasi ke CRM

**Migrations:** `136_customer_portal_users.sql`, `137_sales_do_portal_source.sql`, `138_seed_customer_portal_demo.sql`, `139_customer_portal_master_mpr_link.sql`, `150_sales_do_portal_docs.sql`  
**Rancangan:** `docs/RANCANGAN_CUSTOMER_PORTAL.md`  
**SOP:** `docs/SOP_CUSTOMER_PORTAL.md`

## Manufacturer Portal (Reg Alkes)

Pabrikan bisa upload dokumen registrasi mandiri via portal:

- **URL:** `/manufacturer_portal/login.php`
- **Flow:** Login → Dashboard → Case Reg Alkes → Upload Dokumen
- **Kelola user:** Master Data → Manufacturer Portal Users
- **Demo:** `yaxin_demo` / `password` (setelah seed migration 142, manufacture YAXIN harus ada)

**Migrations:** `140_manufacturer_portal_users.sql`, `141_reg_alkes_portal_source.sql`, `142_seed_manufacturer_portal_demo.sql`  
**Rancangan:** `docs/RANCANGAN_MANUFACTURER_PORTAL.md`  
**SOP:** `docs/SOP_MANUFACTURER_PORTAL.md`

### RFQ (Request for Quotation)

PQP buat RFQ, manufacturer submit quotation via portal, PQP bandingkan harga & export.

- **URL PQP:** `/purchases/pqp_rfq.php`
- **URL Manufacturer:** `/manufacturer_portal/rfq.php`
- **Fitur:** Export CSV/Excel, notifikasi email, chat/komentar, attachment, filter, currency conversion, audit log

**Migrations:** `151_pqp_rfq_quotations.sql`, `152_pqp_rfq_extras.sql`, `153_rfq_config_seed.sql`  
**UAT:** `docs/UAT_RFQ.md`

## API Partner (Eksternal)

Untuk partner eksternal, gunakan API Key. Kelola di **Master Data → API Partner Keys** (SYS only).

- **Auth:** Header `X-API-Key` atau `Authorization: Bearer <key>`
- **H2H Order:** `POST /api/v1/partner/order_create.php` — terima PO dari Hermina/sistem eksternal → create DO. Lihat `docs/PERSIAPAN_H2H_PO_HERMINA.md`
- **Base URL:** `/api/v1/partner/`
- **Sample:** `GET /api/v1/partner/health.php` — cek koneksi
- **Environment:** Production key hanya valid di `APP_ENV=production`; Development key valid di local/staging (tidak di production)

**Indeks & kontrak API (dokumentasi saja):** `docs/API_INDEX.md` · internal + chat: `docs/API_INTERNAL_CHAT.md` · OpenAPI Mobile: `docs/governance/OPENAPI_MOBILE.yaml` · Partner: `docs/governance/OPENAPI_PARTNER.yaml` · Postman H2H: `docs/api/postman_partner_h2h_collection.json` · deprecation non-`/v1/`: `docs/governance/API_DEPRECATION_NON_V1.md` · format error: `docs/governance/API_ERROR_ENVELOPES.md`.

## Tools

- `/tools/index.php` — Dashboard tools
- `/tools/qa/` — Smoke, contract check, cutover, negative tests
- `/tools/backup_*` — Backup & restore
- **UI Test Plan:** `docs/TESTPLAN_UI_FRONTEND.md` — Front-end test spec (RBAC, entry pages, O2C, WQS, CSRF)

### Autobackup Scheduler (23:00 WIB)

**Setup cron (otomatis):**
```bash
cd /volume4/web/ERP_RMI_SOFULL
bash tools/install_daily_backup_2300.sh
```

**Setup cron (manual):**
```bash
crontab -e
# Tambahkan baris berikut (jangan jalankan di shell langsung):
# CRON_TZ=Asia/Jakarta
# 0 23 * * * cd /volume4/web/ERP_RMI_SOFULL && /bin/bash tools/backup_now.sh --label auto_2300 >> storage/logs/backup_daily_2300.log 2>&1
```

**Verifikasi:** Buka `/tools/backup_schedule.php` → Enable 23:00 WIB → Run Test Now.

**Migration 145 (Vendor dedup):** Hapus vendor duplikat FOR001→FOR008, FOR004→FOR010. Jalankan `php tools/run_migration_145.php` (CLI) atau buka `/tools/run_migration_145.php` (Admin). **Migration 149 (Vendor index multi-cabang):** `php tools/run_migration_149.php`.

---

## Langkah Pengecekan (Verification)

**Jalur kanonik & duplikasi (transparansi):** `docs/internal/CANONICAL_PATHS_AND_DUPLICATES.md` — helper, bootstrap, panduan Dashboard, RBAC, folder arsip.

**RBAC tidak berfungsi / 403 setelah login:** `docs/internal/RBAC_TROUBLESHOOTING.md` (department user, tabel matrix, sync RBAC Center, env `RMI_RBAC_POLICY_STRICT`).

Setelah perbaikan, gunakan langkah berikut untuk memastikan perubahan berjalan benar.

**Baselines (RBAC Default, Stock Snapshot, Ops Hardening, MFA Phase, Perf):** Lihat `docs/BASELINES.md` bagian Verifikasi.

**Diagram alur akses (login × RBAC):** `docs/internal/ACCESS_DECISION_FLOW.md` · SVG `docs/officepack/diagrams/DGM_Access_Decision_RBAC.svg` (sumber `DGM_Access_Decision_RBAC.dot`) · regenerasi: `dot -Tsvg -o docs/officepack/diagrams/DGM_Access_Decision_RBAC.svg docs/officepack/diagrams/DGM_Access_Decision_RBAC.dot`.

**Struktur Organisasi (web + JSON, SYS-only):**
1. Login sebagai SYS → `master/org_structure_edit.php` — simpan JSON (CSRF); cek `system_audit_logs` module `docs` action `UPDATE`.
2. Buka `docs/link/struktur_organisasi.php` — konten mengikuti `storage/config/org_structure.json` (fallback ke `_shared/org_structure.default.json`).
3. User non-SYS: halaman editor 403; halaman dokumen tetap bisa dibuka (login).
4. Pastikan NAS bisa menulis `storage/config/` dan `storage/backups/org_structure/` (backup rotasi 30 file).

**Panduan Dashboard (satu URL kanonik):**
1. Hub Dashboard Center: **`/dashboards/panduan.php`** saja (isi `dashboards/_panduan_dashboard_hub.inc.php`). `panduan_index.php` / `panduan_dashboard_center.php` = redirect 301 ke sana (bookmark lama).
2. `php tools/gen_panduan_bundle.php` — registry panduan untuk `dashboards/index.php` memakai URL `dashboards/panduan.php` (tidak ganda dengan `panduan_index`); stub tidak menimpa file kustom.

**Dokumentasi API:** Buka `docs/API_INDEX.md` — OpenAPI Mobile/Partner validasi dengan editor Swagger; Postman: import `docs/api/postman_partner_h2h_collection.json`.

**RBAC policy + cutover gate (NAS `/volume4/web/ERP_RMI_SOFULL` only):**
1. `php tools/qa/_shared/url_join_edge_test.php` — harus exit 0 (URL join tanpa `//` di path).
2. `php tools/qa/base_path_guard.php --strict` — `ok=true`; log lama berisi `/Volumes/` di `storage/logs/*` boleh ter-sanitize otomatis (lihat `sanitized_legacy_logs_count` di artifact).
3. `php tools/qa/run_cutover_checks.php --strict --write-last --base-url='http://10.10.60.20/ERP_RMI_SOFULL'` — overall pass (set base URL sesuai env).
4. **Smoke public URL (Cloudflare/WAF):** smoke memakai **User-Agent + header browser**. **Default:** suite **public dijalankan penuh** (target `layers.public.fail=0`). Diagnosa: `php tools/qa/smoke_public_probe.php --write-last` → `storage/logs/smoke_public_probe_last.json`. Dokumentasi: `docs/internal/PUBLIC_SMOKE_AND_WAF.md`. **Sementara** jika edge memblokir CLI: `SMOKE_AUTO_SKIP_PUBLIC_EDGE=1` (opt-in). Lewati publik sepenuhnya: `SMOKE_SKIP_PUBLIC=1`.
5. Dokumentasi model: `docs/internal/RBAC_POLICY.md`.

**RBAC runtime (model sederhana):**
1. **Dept / role / level** user + **permission** di RBAC Center (`can()`) = dasar akses. Panduan singkat: `docs/internal/RBAC_SIMPLE_SETUP.md`.
2. `require_rbac()` menegakkan **level + method +** aturan manager/cash_out dari `_shared/rbac_policy.php`. Filter **`depts` di policy default OFF** — aktifkan **`RMI_RBAC_POLICY_DEPT=1`** hanya jika ingin lapisan dept per route tambahan.
3. Untuk URL di `config/page_registry.php`, gate **can()** lewat `rmi_page_registry_guard_try()` — tidak digandakan lewat `rule['perms']` policy.
4. Log penolakan: `storage/logs/rbac_apply.log` + audit; detail di body 403: `APP_DEBUG` atau `RMI_RBAC_VERBOSE_DENY=1` (lihat `docs/internal/RBAC_RUNTIME_LAYERS.md`).

**RBAC orphan prune (registry DB vs config):**
1. `php tools/rbac_diff_config_db.php` — pastikan daftar “Di DB tapi TIDAK di config” sesuai ekspektasi (sisa legacy).
2. `php tools/rbac/prune_rbac_orphan_permissions.php --dry-run` lalu `--execute` (atau RBAC Center → Sync → **Hapus orphan registry**).
3. Ulangi diff — seharusnya tidak ada lagi orphan; jumlah aktif DB ≈ jumlah baris `config/rbac_permissions.php`.

**Funnel (CRM Leads, Sales DO, Reg Alkes, Import/PO):**
1. Cek kesiapan: `./tools/nas/erp.sh php tools/cek_funnel_ready.php` — migration 147, stage log count (atau buka `/tools/cek_funnel_ready.php` via browser)
2. Buka `/sales/sales_dashboard.php` → CRM Leads card: conversion rate + avg days; Sales DO Summary: DO-to-payment %, bottleneck
3. Buka `/hrl_reg_alkes/reg_alkes_control_tower.php` → funnel chart (count per stage 1–15) + avg days per stage (setelah migration 147)
4. Buka `/dashboards/funnels.php` → ringkasan semua funnel
5. Buka `/dashboards/owner/exec_summary.php` → tile Import/PO: funnel PO→PIB→GR→AP
6. API: `GET /api/v1/internal/funnels_summary.php?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD` (login required)
7. **Reg Alkes time-in-stage:** Jalankan migration 147: `./tools/nas/erp.sh php tools/run_migration_147.php` (buat tabel `hrl_reg_alkes_case_stage_log`). Data avg days akan terisi setelah case berpindah stage.
8. **Checklist lengkap:** `docs/VERIFIKASI_FUNNEL_CHECKLIST.md`

**Monitoring & controlling (audit + log + pintasan O2C/P2P):**
1. Login sebagai user dengan **SYSTEM.AUDIT_LOG_VIEW** (atau SYS/ITC default).
2. Buka **Menu → SYSTEM → Monitoring** atau `/master/monitoring_center.php` — cek kartu statistik, tabel file log, pintasan.
3. Buka **Panduan** (tombol di halaman) atau `/docs/monitoring_guide.php` — pastikan konten `MONITORING_AND_CONTROL.md` tampil.
4. Smoke: `php tools/smoke_http.php` — baris `monitoring_center_page` harus OK.
5. **Error global:** exception tidak tertangkap harus muncul di `storage/logs/app-*.log` dan `*_errors.log` (redaksi password/token). Opsional: set `RMI_UNHANDLED_ERROR_AUDIT_DB=1` di `.env` agar error yang sama juga masuk `system_audit_logs` (modul `app_error`).
6. **Audit MFA / API key:** setelah enable/disable MFA atau buat API partner key baru, cek **Audit Log** untuk baris `mfa_settings` / `api_partner_keys` (CREATE tanpa raw key di deskripsi).

**Layout HP & tablet (unified `rmi_header`):**
1. Buka sembarang halaman yang memakai `_shared/rmi_layout.php` di **Chrome/Safari mobile** atau devtools responsive (≤575px, 768px).
2. Pastikan **judul + breadcrumb** tidak memaksa scroll horizontal; **topbar** bertumpuk rapi (aksi di baris kedua di tablet; di HP tombol ikon ±44px, teks “Menu/Help/Manual” tersembunyi, **aria-label** tetap).
3. **Kenyamanan:** bilah aksi punya **pemisah + bayangan** ringan; di HP **latar grup** untuk tombol topbar; **Menu** offcanvas = link tinggi (~48px); **form** `min-height` sentuh + **font 16px** (kurangi zoom iOS); **paginate** DataTables lebih besar di HP.
4. Buka **☰ Menu** — offcanvas menu lebar nyaman di HP; tabel lebar punya **scroll** dalam wrapper (DataTables filter/search full width di HP).
5. Styles: `_shared/rmi.css` (blok RESPONSIVE); markup topbar: `_shared/rmi_layout.php`.

**Nav Manager (landing + sidebar):**
1. Akses: user dengan **SYSTEM.CONFIG_MANAGE**, **SYSTEM.USER_MANAGE**, atau **MASTER.ADMIN_CENTER**; session **privileged** (SYS/ADMIN/SUPERADMIN) lolos semua permission.
2. `/master/nav_manager.php?tab=landing` — simpan override → `_shared/nav_overrides.json` harus writable di NAS; gagal simpan menampilkan `error=1`, bukan pesan sukses palsu.
3. **Landing setelah login:** semua role memakai **`auth_post_login_landing_path()`** (= `auth_landing_path_for_dept` + Nav Manager). Default dept **SYS** = Dashboard Center (`/dashboards/index.php`). Ingin buka Master System Login dulu → atur preset di Nav Manager untuk dept user tersebut.

**RBAC Center (`/rbac/index.php`):**
1. **Akses:** `SYSTEM.RBAC_MANAGE` atau `SYSTEM.RBAC_VIEW` — mutasi hanya **MANAGE**.
2. **Policy route:** `_shared/rbac_policy.php` membolehkan URL `/rbac/*` untuk user login STAFF/MANAGER/SYS (semua dept); gate permission tetap di `rbac/*.php`. **`/tools/*` tetap SYS-only.**
3. **Fitur:** preview diff sebelum simpan matrix, simulasi permission efektif per user, audit `SAVE_MATRIX` (+/−), panel **Health/QA** (CLI NAS + link web opsional ke `tools/rbac_diff_config_db.php`; `rbac_coverage_check` = CLI only).
4. **Detail & checklist:** `docs/changelog/RBAC_CENTER_P0P1P2.md`.
5. **Tampilan:** header berisi alur singkat (Dept/Role → matrix → simpan), tombol ke master departemen berlabel **Master Dept**, panel Health memakai blok CLI terformat, kolom sumber simulasi efektif memakai badge **Matrix / Override / Matrix + override** (bukan kelas kategori permission). Tab **Staff vs Manager** di matrix mengelompokkan permission (heuristik) + pintasan Allow/Clear per blok — `rbac/panduan.php` (set permission).
6. **Master System Login → RBAC Center:** `/master/master_system_login.php` — kolom **RBAC** (semua baris yang memenuhi syarat) dan tombol **Matrix RBAC (Staff vs Manager)** saat edit user. Membuka tab baru ke `/rbac/index.php` dengan `dept_code`, `role_code`, `rbac_layout=staff_mgr`, dan `effective_user` (simulasi efektif). Link hanya jika user login punya `SYSTEM.RBAC_VIEW` atau `SYSTEM.RBAC_MANAGE`. Tanpa link untuk: dept kosong; akun privileged (SYS/ADMIN/SUPERADMIN) dengan dept bukan **SYS** (matrix tidak dipakai untuk bypass).
7. **HRL vs HRL Process di matrix:** kode permission tetap `HRL.PROCESS_*`, tetapi kolom `module` registry = **`HRL_PROCESS`** sehingga blok **HRL Process** terpisah dari **HRL** (dokumen/reg alkes) — selaras menu sidebar. DB existing: jalankan `sql/migrations/159_rbac_hrl_process_module_split.sql` dan/atau **Sync Permissions** di RBAC Center.
8. **RBAC legacy → kanonik (DB):** deploy kode + config → **Sync Permissions** → jalankan **`161_rbac_hrl_drop_duplicate_perms.sql`** (duplikat HRL) lalu **`162_rbac_merge_legacy_mirror.sql`** (mirror underscore / `PAYROLL.RUN_*` / dll. — sumber `config/rbac_legacy_merge_map.php`). Detail: `docs/RBAC_LEGACY_MERGE.md`.
9. **Orphan registry (DB > config):** Sync Full **additive** — tidak menghapus permission lama di DB. Setelah migrasi + review, rapikan dengan RBAC Center → tab **Sync** → **Hapus orphan registry**, atau CLI: `php tools/rbac/prune_rbac_orphan_permissions.php --dry-run` lalu `--execute`. Cek selisih: `php tools/rbac_diff_config_db.php`.
10. **HRL Process per tipe pengajuan:** permission `HRL.REQ_<TIPE>_{VIEW|CREATE|EDIT|DELETE}` (7 tipe × 4) untuk kontrol halus; tanpa `HRL.PROCESS_*`, user masih bisa akses modul jika punya minimal satu `…_VIEW`. **Sync Permissions** mendaftarkan kode baru; baseline matrix lama tetap memakai `HRL.PROCESS_*` (kompatibel).
11. **Katalog RBAC lengkap per modul:** `docs/RBAC_CATALOG_BY_MODULE.md` (semua baris dari `config/rbac_permissions.php`, dikelompokkan per `module` + klasifikasi VIEW/CREATE/EDIT/DELETE/legacy). Regenerate setelah mengubah registry: `php tools/rbac/build_rbac_catalog_md.php`. **Standar CRUD:** `docs/RBAC_CRUD_STANDARD.md` · helper `_shared/rbac_crud.php`.

**Panduan per modul ERP:**
1. Buka `/docs/modules_hub.php` atau **Help Center** → kartu **PANDUAN ERP — PER MODUL**.
2. Klik satu modul → `docs_view` menampilkan file `docs/modules/*.md`.
3. **Master Data Center** → kartu **Panduan per modul ERP** mengarah ke hub yang sama.

**Final gate (NAS `/volume4/web/ERP_RMI_SOFULL`):**
1. Set base URL tanpa trailing slash: `TOOLS_BASE_URL_INTERNAL=http://10.10.60.20/ERP_RMI_SOFULL` dan opsional `TOOLS_BASE_URL_PUBLIC=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL`.
2. `php tools/qa/run_cutover_checks.php --strict --write-last` — `base_path_guard` + **`base_path_guard_final`** (step terakhir) gagal jika CWD/repo berisi jejak `/Volumes/` di `tools/` / artefak `storage/logs`, atau `realpath(APP_ROOT)` ≠ persis `/volume4/web/ERP_RMI_SOFULL`.
3. **RBAC matrix HTTP dual + action matrix:** `php tools/qa/rbac_matrix_http_dual.php --strict --write-last` lalu `php tools/qa/rbac_action_matrix.php --strict --write-last` (POST-only; artefak `rbac_action_matrix_last.{internal,public}.json`, `rbac_action_matrix_last.summary.md`). Wrapper lama: `php tools/qa/rbac_action_smoke.php` = dual + matrix. **Registry endpoint mutasi (heuristik):** `php tools/qa/action_endpoints_registry.php --write-last` → `action_endpoints_registry_last.json`. Inventaris menu: `php tools/qa/rbac_action_inventory.php --write-last`.
4. **Smoke HTTP** jalan **internal + public** (`smoke_http_last.internal.json` / `.public.json`); kegagalan dengan `http_code=301` dilabeli **URL_JOIN_BUG** (bukan leak RBAC). URL: `tools/_shared/url.php` (`url_join` → `rmi_canonical_url_join`) dan **`tools/_lib/tools_http.php`** (`tools_url_join` → canonical join untuk `contract_check` / QA).
5. Ringkasan manusia: `storage/logs/final_gate_summary_last.md` (+ `.json`); **satu halaman:** `storage/logs/final_gate_onepage_last.md`. Probe publik vs internal: `php tools/qa/smoke_public_probe.php --write-last`.
6. Reset password user internal: `master/itc_reset_password.php` memerlukan **`SYSTEM.USER_MANAGE`**; audit DB memakai action **`PASSWORD_CHANGED`**.

**INSIGHT REAL (read-only DB + logs, NAS):**
1. `./tools/nas/erp.sh php tools/qa/insight_real.php` — generates `storage/logs/insight_real_last.json`, `insight_real_last.md`, `insight_real_do_backlog.csv`, `insight_real_query_log.md`; menulis audit `INSIGHT_REAL_RUN` ke `system_audit_logs` (kecuali `--no-audit`).
2. Web SYS-only: `/tools/qa/insight_real_web.php` (POST + CSRF).

**Chat konteks DO/PO/AP:**
1. Link `?context=DO|PO|AP:<id>` hanya **membaca** DB; jika channel sudah ada → redirect ke `?cid=…`; jika belum → halaman menampilkan form **Buka chat** (POST + CSRF) untuk membuat channel + pesan sistem.
2. Tombol admin di UI chat (export, ACL, dll.) memakai gate **`auth_is_sys_tier()`** (session **SYS / ADMIN / SUPERADMIN**, selaras tools & RBAC privileged).
3. Mention PIC pada pesan sistem: **username** + nama — nama prioritas **`master_employees.employee_name`** (via `holder_employee_code` di login), fallback **`master_system_login.full_name`**. Aktif/nonaktif & format di **Chat → Admin Settings** (`context_include_employee_name`, `context_employee_name_format`).
4. Header pesan biasa (channel/DM/thread/pin): tampil **`Username (Nama Karyawan)`** bila API mengirim `sender_display_name` (sumber nama sama seperti poin 3). Cek: user punya **`holder_employee_code`** yang cocok **`master_employees`** aktif, atau **`full_name`** terisi di login.

**Panduan in-app per dashboard (ERP UI):**
1. Buka `/dashboards/index.php` → **📚 Panduan** (topbar dan bar cepat) → `/dashboards/panduan.php` (indeks + pintasan ke tiap area).
2. **Funnel:** `/dashboards/funnels.php` → **📚 Panduan** → `/dashboards/panduan_funnels.php`.
3. **Finance / Detail:** `/dashboards/finance/ar_ap_cash_dashboard.php` dan `/dashboards/finance/dashboard_detail.php` → **📚 Panduan** → `/dashboards/finance/panduan.php`.
4. **Warehouse, Quality, Regulatory, ACT, ITC, Executive Summary, SCM, Branch:** tombol **📚 Panduan** di header mengarah ke `dashboards/<area>/panduan.php` (gaya konsisten dengan `dashboards/branch/panduan.php`).
5. **HRL dashboard:** **📚 Panduan** → redirect ke `/hrl/panduan.php` (satu sumber dengan modul HRL).
6. **Procurement (kartu dashboard):** `/dashboards/procurement/panduan.php` → redirect ke `/purchases/panduan.php`.
7. **Sales:** `/sales/sales_dashboard.php` → **📚 Panduan** → `/sales/panduan.php`.

**Sesi login (cookie + idle + regenerate):**
1. `session_regenerate_id` setelah sukses: `auth_session_mark_login_complete()` — dipakai `master/login.php` dan `master/mfa_verify.php` (bukan hanya `auth.php`).
2. Idle timeout server: default **30 menit** user biasa, **60 menit** untuk SYS bucket (`level`/`role` SYS atau `department` SYS). Env: `RMI_SESSION_IDLE_SECONDS`, `RMI_SESSION_IDLE_SYS_SECONDS`, `RMI_SESSION_GC_MAXLIFETIME`.
3. Dokumentasi: `docs/SESSION_POLICY.md`.

**Audit keseluruhan (jangka panjang & konsistensi FE/BE):** Lihat `docs/AUDIT_KESELURUHAN_ERP_RMI_SOFULL_2026.md`.
**Validasi RBAC VIEW konsisten:** jalankan `php tools/qa/rbac_coverage_check.php` lalu pastikan hasil `ok: true` dan semua grup permission memiliki pasangan `*.VIEW`.
**Sinkronisasi RBAC config ↔ DB:** jalankan `php tools/rbac_diff_config_db.php` dan pastikan tidak ada output `Di config tapi TIDAK di DB`.
**Pengelompokan RBAC (VIEW vs lainnya):** jalankan `php tools/rbac_permission_groups.php` (opsional `--format=json` / `--format=md`) dan cek artefak di `storage/logs/rbac_permission_groups.last.*`.
**Detail RBAC per kategori/modul/file:** jalankan `php tools/rbac_permission_detail.php` (opsional `--format=json` / `--format=md`) dan cek artefak di `storage/logs/rbac_permission_detail.last.*`.
**Kesehatan koneksi DB (PDO MySQL):** jalankan `php tools/dev/php_driver_check.php` lalu pastikan `pdo_mysql` terdeteksi dan kredensial `config-db.php`/`.env` dapat dipakai oleh `db_pdo()`.

**KPI — kebijakan SYS & SLA DO:**
1. **Dua lapisan:** RBAC (`KPI.VIEW`, dll.) = buka halaman; **mutasi KPI** = level **SYS** lewat `_shared/rmi_sys_gate.php` (`rmi_is_sys_session()`, `auth_is_sys()`) dan `kpi_can_manage()`.
2. Mutasi (import, snapshot, sync, input office/employee/purchases/stock, **simpan SLA DO**) hanya SYS — bukan gate utama `KPI.EDIT` / permission generik semata.
3. Halaman **KPI Purchases / Stock / Office / Employee**: non-SYS *mode baca saja*; **Lihat** + export; form/impor/sync hanya SYS.
4. SLA menit DO di `system_config` group `KPI_DO_SLA` (bukan query string). Migration opsional: `sql/migrations/158_kpi_do_sla_policy_minutes.sql`.
5. Auto-fill `?auto=1` (Office/Employee) hanya SYS.
6. Dokumentasi: `docs/KPI_SYS_GOVERNANCE.md`, review RBAC: `docs/RBAC_PERIODIC_REVIEW.md`.

**FINAL GATE (URL join + base path + ringkasan):**
1. Di NAS: `cd /volume4/web/ERP_RMI_SOFULL`
2. `.env`: `TOOLS_BASE_URL_INTERNAL=http://10.10.60.20/ERP_RMI_SOFULL` dan publik `TOOLS_BASE_URL_PUBLIC=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL` (tanpa trailing slash).
3. `php tools/qa/base_path_guard.php --strict --write-last` → `ok: true`, tidak ada jejak mount terlarang di `tools/` / `storage/logs/`, APP_ROOT tepat `/volume4/web/ERP_RMI_SOFULL`.
4. `php tools/qa/rbac_matrix_http_dual.php --strict --write-last` dan `php tools/qa/rbac_action_matrix.php --strict --write-last` → `ok: true` (publik bisa di-skip dengan `RBAC_MATRIX_SKIP_PUBLIC=1`; matrix POST auto-skip publik jika snapshot publik tidak ada).
5. `php tools/qa/smoke_http.php --strict --write-last` → PASS.
6. `php tools/qa/run_cutover_checks.php --strict --write-last` → `overall_ok: true`.
7. Baca `storage/logs/final_gate_onepage_last.md` atau `final_gate_summary_last.md`.

### 0. Cek path workspace (edit terlihat?)

```bash
php tools/cek_workspace_path.php
```

Jika path = `/Volumes/web/ERP_RMI_SOFULL` dan share terhubung → edit langsung ke NAS.  
Jika path = lokal (mis. ~/Projects) → harus deploy manual.

### 1. Base URL terpusat (`tools_default_base_url`)

**Cek fungsi:**
```bash
cd /path/to/ERP_RMI_SOFULL
php -r "require 'tools/tools_state_lib.php'; echo tools_default_base_url() . PHP_EOL;"
```
- Tanpa env: harus `http://127.0.0.1/ERP_RMI_SOFULL`
- Dengan `APP_URL=http://10.10.60.20/ERP_RMI_SOFULL` di .env: harus URL tersebut

**Cek smoke HTTP (jika server jalan):**
1. Buka `/tools/qa/smoke_http_web.php`
2. Isi base URL sesuai environment
3. Klik Run — pastikan tidak error dan hasil sesuai ekspektasi

### 2. Mobile Quality Gate

**Cek basic mobile smoke (auth/me):**
```bash
cd /path/to/ERP_RMI_SOFULL
APP_BASE_URL=http://127.0.0.1:8080/ERP_RMI_SOFULL php tools/qa/mobile_api_smoke.php
```
- Pastikan server ERP jalan (php -S atau web server)
- Sesuaikan APP_BASE_URL dengan URL aktual (port, path)
- Harus keluar: `PASS: mobile API smoke checks` atau `PASS: mobile API smoke checks with warning`

**Via UI:** Buka `/tools/qa/mobile_quality_gate.php`, isi APP_BASE_URL, centang "Use QA credentials" dan isi MOBILE_QA_USER + MOBILE_QA_PASS untuk master policy smoke, lalu Run.

**Troubleshooting negative auth FAIL:**
- Pastikan server ERP benar-benar jalan saat Run (cek di terminal lain: `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8000/api/v1/mobile/auth/me.php` → harus 401)
- Jika pakai `php -S 127.0.0.1:8000`, gunakan URL `http://127.0.0.1:8000` (tanpa /ERP_RMI_SOFULL)
- Jika FAIL, output sekarang menampilkan URL, HTTP code, dan body untuk debug

### 3. XSS helper (`h()` → `rmi_h()`)

Tools QA memakai `h()` sebagai alias `rmi_h()` (null-safe, ENT_SUBSTITUTE).

**Cek:**
1. Buka `/tools/qa/smoke_http_web.php` (atau tools/qa lain)
2. Pastikan halaman tidak error dan output ter-render dengan benar
3. Coba input karakter khusus: `<script>`, `"` — harus ter-escape

### 4. Login & User (master_system_login)

**Hanya memakai `master_system_login`.**

- Login, Chat, KPI, Absensi, RBAC — semua pakai `master_system_login`
- Pastikan user ada di `master_system_login` dengan `department` dan `role` yang benar

### 5. API Partner Keys

**Cek:**
1. Login sebagai SYS (admin / superadmin / RizqullahMediskaSYS)
2. Buka Master Data → API Partner Keys
3. Tambah partner (pilih Environment: Production atau Development), simpan API key yang ditampilkan
4. Test: `curl -H "X-API-Key: <key>" https://your-erp/api/v1/partner/health.php` → harus `{"ok":true}`
5. **Environment:** Di production (`APP_ENV=production`) hanya Production key valid; di local/staging hanya Development key valid

**Migration 114:** Jika upgrade dari schema lama, jalankan `sh tools/nas/run_all_migrations.sh` (pakai env `ERP_DB_NAME`, `ERP_DB_USER`, `ERP_DB_PASS` atau default dari config-db.example).

### 6. Security (PDO, XSS, CSRF)

- PDO: cari `$conn->query("... $var ...")` — tidak boleh ada interpolasi user input
- XSS: output dinamis pakai `rmi_h()` atau `h()`
- CSRF: form POST harus punya `csrf_token()` dan `verify_csrf()`

### 7. RBAC Enterprise (RBAC_RMI_COMPLETE_ENTERPRISE)

**Dokumen:** `docs/governance/RBAC_MATRIX_RMI.md`

**Verifikasi (jalankan di NAS via wrapper):**
```bash
cd /volume4/web/ERP_RMI_SOFULL
./tools/nas/erp.sh php tools/rbac/seed_rmi_rbac.php
./tools/nas/erp.sh php tools/qa/rbac_coverage_check.php
./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --write-last --strict
```

**Evidence wajib:**
- `storage/logs/rbac_seed_last.json` — hasil seed RBAC
- `storage/logs/rbac_coverage_last.json` — skor 100 jika tidak ada CRITICAL findings
- `storage/logs/cutover_checks.last.json` — hasil cutover checks

### 8. PWA & Install di Android

**Verifikasi file PWA:**
```bash
cd /path/to/ERP_RMI_SOFULL
php tools/qa/smoke_pwa.php
```
- Harus keluar: `"fail":0` (semua 16 checks pass)
- Hasil tersimpan di `storage/logs/smoke_pwa_last.json`

**Regenerate ikon PNG (jika perlu):**
```bash
php tools/pwa/generate_icons.php
```

**Uji install di Android:**
1. Buka ERP di Chrome Android (HTTPS)
2. Menu ⋮ → **Add to Home Screen** atau **Install app**
3. Untuk SCM Tracker: buka `/sales/scm_tracker_mobile.php` → Add to Home Screen

### 9. Customer Portal

**Verifikasi:**
1. Jalankan migrations 136–139 di NAS
2. Buka `/customer_portal/login.php` → harus tampil form login
3. Login dengan `hermina_demo` / `password` (setelah migration 138)
4. Cek: Katalog, Keranjang, Checkout, Riwayat Order berjalan normal
5. Di ERP: Master Data → Customer Portal Users → kelola user, reset password
6. Di Sales DO: badge "Portal" muncul untuk order dari portal

**SOP:** `docs/SOP_CUSTOMER_PORTAL.md`

### 10. Manufacturer Portal

**Verifikasi:**
1. Jalankan migrations 140–142
2. Jalankan installer `hrl_reg_alkes/_sql/master_manufactures_docs_install.sql` jika tabel belum ada
3. Buka `/manufacturer_portal/login.php` → form login tampil
4. Login `yaxin_demo` / `password` (manufacture YAXIN harus ada)
5. Cek: Dashboard, Case Reg Alkes, Upload dokumen berjalan normal
6. Di Reg Alkes Case: tombol "Kelola" → `master/manufactures_docs.php?code=...`; download PKS/LOA/LOA_KBRI via `reg_alkes_case.php?id=...&dl_manu=...`
7. Di ERP: Master Data → Manufacturer Portal Users → kelola user
8. Di Reg Alkes Control Tower: badge "Portal" pada case dengan dokumen dari portal

**SOP:** `docs/SOP_MANUFACTURER_PORTAL.md`

### 11. RFQ (Request for Quotation)

**Verifikasi:**
1. Jalankan migrations 151, 152, 153 di NAS:
   ```bash
   cd /volume4/web/ERP_RMI_SOFULL
   ./tools/nas/erp.sh php tools/run_migration_151.php
   ./tools/nas/erp.sh php tools/run_migration_152.php
   ./tools/nas/erp.sh php tools/run_migration_153.php
   ```
2. Set `ENABLE_EMAIL_ALERTS=1` di `.env` untuk notifikasi
3. **Email di NAS:** Install Mail Server + SMTP relay — lihat `docs/SETUP_EMAIL_SYNOLOGY_NAS.md`
4. Pastikan `master/uploads/manufactures/` writable (`chmod -R 755`)
5. PQP: Purchases → RFQ → buat RFQ (status Open) → cek comparison, export CSV/Excel
6. Manufacturer Portal: menu RFQ → submit quotation (multi-currency, attachment)
7. Cek email PQP saat quotation submit; cek komentar & audit log

**UAT:** `docs/UAT_RFQ.md`

### 12. Help F1 & SOP/Manual (setara Sales)

**Verifikasi konsistensi modul dengan Sales:**
1. Buka halaman Sales (mis. `/sales/sales_do.php`) → tekan F1 → pastikan SOP CRM + CRM Manual + diagram O2C muncul
2. Buka halaman Purchases (mis. `/purchases/purchases_po.php`) → tekan F1 → pastikan SOP PQP + PQP Manual (Procurement) muncul
3. Buka `/purchases/purchases_gr.php` → F1 → SOP WQS + WQS Manual + diagram WQS Gudang (user WQS)
4. Buka `/purchases/purchases_ceisa_pib.php` → F1 → SOP ACT + ACT Manual
5. Manual PQP harus punya section 2.1 Procurement (purchases_*) dan 2.2 Reg Alkes
6. Manual WQS harus punya wqs_do_tasks, purchases_gr, stock_update_from_gr di Akses & Navigasi

**Verifikasi SOP detail & konsisten:**
```bash
php tools/dev/verify_help_map.php
```
- Semua entri di `help_sop_map.json` harus punya diagram, sop, manual
- File SOP/Manual yang direferensikan harus ada di `docs/officepack/dept_training/`
- SOP modul (CRM, FIN, ACT, SCM, WQS, MPR) harus punya: metadata table, Ruang Lingkup lengkap, SOP Detail per Halaman untuk task spesifik
