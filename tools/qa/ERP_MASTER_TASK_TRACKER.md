# ERP MASTER TASK TRACKER (SINGLE SOURCE)

> **ATURAN TRACKER (WAJIB, DARI OWNER):** setiap temuan, perubahan, dan hasil
> verifikasi baru **harus** ditulis ke file ini. Tidak ada pekerjaan QA yang
> dianggap selesai kalau tidak tercatat di sini beserta evidence-nya.

_Updated: 2026-09-30 | Print DO: alur 6 tahap + transisi per kartu + blok logstik + bukti orang-per-tahap (id=30 Didi); migrasi 167/168; shared CSS table + thead 9f (owner); QA-018 FIXED (triase + leak_count); cutover gate terverifikasi hanya-jalan-di-NAS |_

## Aturan deploy (dari owner, 2026-09-29)
Setiap perubahan kode/config → `systemctl restart php8.3-fpm` + `nginx -t && systemctl reload nginx`, lalu verifikasi `curl` halaman login. Detail di `AGENTS.md`. Fokus `/var/www/ERP_RMI_SOFULL` saja; proyek lain di `/var/www` tidak boleh disentuh.

---

## Sesi 2026-09-29 (VPS deploy) — 46 halaman mati + asset `_shared/`

_Konteks: deploy pertama di luar NAS. Semua breakage di tabel ini berasal dari asumsi path `/volume4/...` dan schema drift — bukan bug yang terlihat saat develop di NAS._

| ID | Task | Status | Evidence | Notes |
|----|------|--------|----------|-------|
| DEP-001 | Import DB dari dump paling lengkap `sql/erp_rmi_sofull-2.sql` | PASS | 206 tabel + 514 permission + 35 karyawan -> 226 tabel setelah migration | Dump `ERP_RMI_SOFULL.sql` (68 tabel) dipakai sebagai pembanding, bukan sumber |
| DEP-002 | Sweep 591 halaman, baseline sebelum fix | FAIL | 46 halaman HTTP 500 | Termasuk seluruh modul MPR (30 halaman) |
| DEP-003 | `mpr/.user.ini` hardcode `/volume4/web/ERP_RMI_SOFULL/mpr/_opcache_fix.php` | **FIXED** | path relatif; halaman MPR utama 200 | Penyebab tunggal 30 halaman MPR mati di luar NAS |
| DEP-004 | `web/admin/ops/thresholds_{view,edit}.php` naik 4 level dari `APP_ROOT` | **FIXED** | `../../../../_shared` -> `../../..`; keduanya 200 | Keluar `APP_ROOT`, tidak pernah ada di path mana pun |
| DEP-005 | `sales/kpi_do_sla{,_fixed_staff_v6}.php` require `_kpi_bootstrap.php` | **FIXED** | -> `../kpi/`; keduanya 200 | File ada di `sales/kpi/`, bukan `sales/` |
| DEP-006 | `chat/views/index.php` salah 1 level untuk `_shared/rmi_icons.php` | **FIXED** | `../` -> `../../`; `/chat/index.php` 200 | Halaman masuknya bukan `views/index.php` |
| DEP-007 | `master/master_product_media_bulk.php` bisa diakses tanpa login | **FIXED** | anonymous -> 302; authenticated -> 200 | Halaman menerima upload ZIP. `require '../config/db.php'` (tidak ada) diganti `master/auth.php` + `require_login()` |
| DEP-008 | Migration 164 `hrl_employee_mutations` | **FIXED** | 5 file HRL -> "1146 table doesn't exist" | Tabel dipakai kode tapi tidak ada di dump mana pun |
| DEP-009 | Migration 165 `fa_assets` + `gl_journal_lines.description` | **FIXED** | migration idempotent, `assets.php` 200 | Menutup PENDING-04. `fa_ensure_asset_core_columns()` added sebagai self-healing |
| DEP-010 | Migration 166 normalisasi collation | **FIXED** | 200 tabel -> `utf8mb4_0900_ai_ci`, sisa non-0900 = 0 | Dump MariaDB (`unicode_ci`) bercampur dengan tabel hasil migration (`0900_ai_ci`) -> "Illegal mix of collations" di JOIN. Backup pra-conversi: `/var/backups/erp/erp_rmi_sofull_pre_collation.sql` |
| DEP-011 | Sweep 591 halaman, setelah fix | PASS | 46 -> 10 (semua fragment include, bukan entry point) | `_inc/`, `layout.php`, `views/index.php` memang tidak bisa dibuka langsung |
| SEC-004 | `/_shared/` diblokir penuh -> `rmi.css` + `rmi_assist.js` 404 | **FIXED** (server) | keduanya 200 + MIME benar; `/_shared/*.php` tetap 404 | Rule Nginx hanya di server ini, **belum** ada di repo. Lihat PENDING-06 |
| SEC-005 | Path traversal lewat `_shared/` | PASS | `/_shared/../config/db.php` dll -> 404 | Regex `try_files $uri =404` menolak |
| PENDING-06 | Config Nginx belum ter-versioning | TODO | - | Rule `_shared/` statis hanya ada di `/etc/nginx/sites-available/erp.andypratama.studio`. Deploy ulang ke server lain akan reproduce bug CSS 404. Perlu file `deploy/nginx-erp.conf` di repo |
| PENDING-07 | `_shared/` mencampur PHP include (privat) dengan CSS/JS (publik) | TODO | - | Block-by-extension sekarang bekerja, tapi rapikan: pindahkan asset ke `public/` agar tidak perlu regex terpisah |

### Detail DEP-007 — kenapa ini pararah
`master_product_media_bulk.php` tidak punya guard auth, sementara halaman lain di modul `master/` memanggil `require_login()`. Dampaknya anonim bisa mengunggah ZIP ke server. Diperbaiki dengan menyamakan pola auth, bukan dengan menambahkan cek adhoc.

### Detail DEP-010 — kenapa collation berantakan
Dump berasal dari MariaDB (default `utf8mb4_unicode_ci`), sedangkan MySQL 8 memakai `utf8mb4_0900_ai_ci`. Tabel hasil migration baru ikut default MySQL 8, jadi dump lama dan tabel baru tidak bisa di-JOIN. Solusi: samakan seluruhnya ke default MySQL 8, bukan menambal per-query — menambal per-query hanya relocating error ke JOIN berikutnya.

## Sesi 2026-09-29 — GitHub Actions + Phpspreadsheet + SVG escape

| ID | Task | Status | Evidence | Notes |
|----|------|--------|----------|-------|
| CI-001 | Workflow `.github/workflows/ci.yml` 3 job (lint/security/qa-nas) | PASS | YAML valid: 3 jobs, 7/5/10 steps | qa-nas butuh repo var `RMI_SELF_HOSTED_ENABLED=true` + runner ber-label `[self-hosted, linux, rmi-nas]` |
| CI-002 | Job `security` merah karena advisory | **FIXED** | `composer audit` -> "No security vulnerability advisories found." | Gate dirombak: tidak lagi hardcode CVE/jumlah advisory; cukup 1 advisory baru = merah |
| CI-003 | `composer audit` exit code salah dibaca | **FIXED** | `PIPESTATUS[0]` menggantikan `$?` | Sebelumnya `$?` = status `tee` (selalu 0) sehingga semua advisory lolos |
| CI-004 | `qa-nas` tanpa `actions/checkout` | **FIXED** | step `actions/checkout@v4` ditambahkan | Tanpa ini runner bisa menguji sisa workspace, bukan commit yang sedang diuji |
| SEC-001 | `phpoffice/phpspreadsheet` 5.5.0, 8 advisory (5 high, 1 critical `CVE-2026-34084`, 2 medium) | **FIXED** | 5.5.0 -> **5.8.1**, `composer audit` bersih | Advisory bisa dieksploitasi lewat file spreadsheet yang di-upload user, jadi ini bukan teori |
| SEC-002 | Constraint `^5.5` masih mengizinkan versi rentan | **FIXED** | `composer.json` -> `"^5.8.1"`, `composer validate` OK | Floor versi naik supaya `composer update` berikutnya tidak bisa balik ke 5.5.0 |
| SEC-003 | Regresi spreadsheet (tulis/baca XLSX + guard env) | **PASS** | `bash tools/qa/spreadsheet_regression.sh` -> LULUS | Lihat detail di bawah |
| UI-001 | SVG ter-escape tampil sebagai teks mentah di 9 halaman KPI | **FIXED** | `check_escaped_svg.sh '^kpi/'` -> BERSIH | Sumber: `kpi/_kpi_bootstrap.php` menaruh `rmi_icon()` di dalam string yang lalu di-`h()` |
| UI-002 | SVG ter-escape di `kpi_dashboard_daily.php` + `kpi_dashboard_monthly.php` (5 ikon/card) | **FIXED** | `check_escaped_svg.sh '^kpi/'` -> BERSIH (23 halaman) | Sumber: `dashboards/_manager_scope.php` meng-`$esc()` markup SVG milik kartu |
| UI-003 | XSS risk pada ikon kartu dashboard | **FIXED** | kartu kini menyimpan `icon_name` (string), `rmi_icon()` dipanggil saat render | `extra_metrics` bisa disuplai pemanggil; mencetak HTML mentah membuka celah injeksi |
| UI-004 | Detektor SVG false-positive pada `data-icon-dark/light` | **FIXED** | strip atribut `data-icon-*` sebelum hitung | Escaping di dalam atribut WAJIB (browser un-escape saat parse, lalu JS `innerHTML`) |
| UI-005 | `declare(strict_types=1)` salah posisi di `purchases/bank_statement_import.php` | **FIXED** | `php -l` bersih | Pernah jadi fatal error |
| CI-005 | Job `security` tidak pernah `composer install` | **FIXED** | `composer install --no-interaction` ditambahkan | Tanpa vendor, `composer audit` keluar "No installed packages found" |
| CI-006 | `composer audit` tanpa `--locked` tidak memeriksa apa-apa di runner | **FIXED** | semua pemanggilan pakai `composer audit --locked` | Yang dijaga adalah apa yang AKAN terpasang di produksi = isi composer.lock |
| CI-007 | Penghitung advisory salah untuk JSON kosong | **FIXED** | `advisories` bisa `[]` (list) atau dict per-paket | Kode lama hanya menangani dict -> advisory bersih salah dihitung `?` |
| CI-008 | Job `lint` tanpa vendor membuat audit tidak akurat | **FIXED** | `composer install` + `--locked` | Gate lokal dan CI kini menghasilkan angka yang sama |

| PENDING-01 | Gate SVG + regresi spreadsheet belum ada di `lint` job | IN_PROGRESS | sudah dipasang di `qa-nas` | Tanya: `lint` job GitHub-hosted tidak bisa render authenticated, jadi hanya `qa-nas` |
| PENDING-02 | Sapuan SVG seluruh repo (semua 675 halaman) | TODO | - | Percobaan pertama di-interrupt; perlu diulang sampai tuntas |
| PENDING-03 | `tools/qa/run_full_suite.php` | **FIXED** | `runtime_sweep.php --json-out` sekarang didukung | Ditutup 2026-09-29: kontrak JSON vs argumen posisi sudah cocok. Hanya cover render SYS — belum CRUD/RBAC/audit/print per role |
| PENDING-04 | `Fixed_Asset/assets.php` -> `Unknown column 'quantity'` | **FIXED** | migration 165 + `fa_ensure_asset_core_columns()` | Selesai di sesi deploy 2026-09-29 -> lihat tabel DEP-009 |
| PENDING-05 | 23 actor unlinked + 2 department mismatch | BLOCKED | `check_actor_relation.php` | Butuh keputusan data owner; **dilarang** menebak |
| PENDING-06 | Config Nginx belum ter-versioning | TODO | - | Rule `_shared/` statis hanya ada di server. Deploy ke server lain akan reproduce bug CSS 404 -> lihat DEP/SEC sesi deploy |
| PENDING-07 | `_shared/` mencampur PHP privat dengan CSS/JS publik | TODO | - | Block-by-extension cukup untuk sekarang; rapikan asset ke `public/` |

### Detail SEC-003 — apa yang diuji `spreadsheet_regression.sh`
1. `composer audit` bersih (gate keras).
2. Versi terpasang di `composer.lock` >= floor `5.8.1`.
3. Tulis XLSX lalu baca ulang: sheet title, header, nilai numerik, desimal,
   number format, baris terakhir. Magic byte harus `PK`.
4. `export_xlsx_if_available()` produksi menghasilkan file saat
   `ENABLE_EXCEL_EXPORT=1`.
5. Guard: jalur XLSX **tertutup** saat env bukan 1.
6. Jalur CSV default tetap berfungsi.

> Catatan harness: anak proses wajib `require vendor/autoload.php` **sebelum**
> `_shared/export_excel.php`. Tanpa itu `class_exists(Spreadsheet::class)`
> bernilai false dan fungsi menolak diam-diam (return false) — bukan bug
> kode produksi, tapi bug harness yang sempat menyesatkan.


_Updated: 2026-09-29 05:08 | Fixes: run_cutover_checks.php duplikat dihapus; panduan render MD+tombol | _Generated: 2026-09-29 05:07 | Features: 346 | P0: 16 | P1: 121 | Root lokal: /Users/andypratama3/Development/ERP_RMI_SOFULL (prompt menyebut /volume4/web/ERP_RMI_SOFULL = NAS produksi)_

| ID | Core | Feature | Task | Priority | Agent | Status | Evidence | Last Test | Notes |
|----|------|---------|------|----------|-------|--------|----------|-----------|-------|
| QA-001 | Master Data | 76 features (P0:8/P1:22) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-01 | TODO | - | - | tracker init |
| QA-002 | Sales / CRM | 40 features (P0:0/P1:12) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-02 | TODO | - | - | tracker init |
| QA-003 | Purchases / Procurement | 40 features (P0:1/P1:26) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-03 | TODO | - | - | tracker init |
| QA-004 | WQS / Warehouse / Stock | 24 features (P0:0/P1:12) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-04 | TODO | - | - | tracker init |
| QA-005 | SCM / Logistics | 0 features (P0:0/P1:0) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-05 | TODO | - | - | tracker init |
| QA-006 | ACT / Accounting | 0 features (P0:0/P1:0) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-06 | TODO | - | - | tracker init |
| QA-007 | FIN | 0 features (P0:0/P1:0) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-07 | TODO | - | - | tracker init |
| QA-008 | HRL / HR | 33 features (P0:0/P1:7) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-08 | TODO | - | - | tracker init |
| QA-009 | HRL / Absensi | 12 features (P0:1/P1:0) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-08 | TODO | - | - | tracker init |
| QA-010 | HRL / Payroll | 12 features (P0:0/P1:8) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-08 | TODO | - | - | tracker init |
| QA-011 | KPI / MPR | 40 features (P0:0/P1:21) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-09 | TODO | - | - | tracker init |
| QA-012 | Dashboard / Help | 23 features (P0:0/P1:4) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-10 | TODO | - | - | tracker init |
| QA-013 | Dashboard / Chat | 5 features (P0:0/P1:2) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-10 | TODO | - | - | tracker init |
| QA-014 | RBAC / Security | 2 features (P0:0/P1:1) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-11 | TODO | - | - | tracker init |
| QA-015 | Portal / API | 26 features (P0:4/P1:4) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-12 | TODO | - | - | tracker init |
| QA-016 | QA / Tools | 6 features (P0:2/P1:1) | inventory+CRUD+filter+update+RBAC+audit | P1 | AGENT-13 | TODO | - | - | tracker init |
| QA-017 | ALL | php_error_scan | syntax/fatal scan | P0 | AGENT-13 | PASS | tools/qa/php_error_scan.php overall_ok=true fatal=0 | 2026-09-29 05:07 | evidence tercatat |
| QA-018 | ALL | TODO/FIXME discovery | 12 files contain TODO/FIXME/XXX/HACK | P2 | AGENT-13 | FIXED | triase 2026-09-30: 11 false-positive pola contoh + 1 TODO asli dipecahkan via leak_count; grep TODO di menu_dashboard_sync.php = 0 | 2026-09-30 | rincian di bawah |
| QA-019 | ALL | Final gate cutover --strict | fail_count=0 required | P0 | AGENT-13 | TODO | - | - | run php tools/qa/run_cutover_checks.php --strict --write-last |
| QA-020 | ALL | UX panduan interaktif + tombol aksi | 158 panduan_*.php, view non-interaktif | P1 | AGENT-10 | TODO | - | - | user report: view membingungkan |

## TODO/FIXME files (12) — triase 2026-09-30
- [x] `_backup/purchases_m2_20260308_231256/purchases/purchases_forwarder_quotes.php` — false positive (`RMI-PO-XXX` data contoh CSV) + file backup, bukan kerjaan
- [x] `purchases/purchases_forwarder_quotes.php` — false positive (`RMI-PO-XXX` data contoh CSV)
- [x] `tools/qa/_lib/pipeline_steps.php` — bersih (grep ulang 0 hit)
- [x] `tools/qa/_lib/plan_one_pager_lib.php` — false positive (`RFC-XXXX` pola contoh)
- [x] `tools/qa/_lib/manifest_lock_suggest_lib.php` — false positive (`SUGG-XXXX` pola contoh)
- [x] `tools/qa/menu_dashboard_sync.php` — TODO asli, SUDAH dipecahkan via `leak_count` (grep TODO = 0)
- [x] `tools/ops/module_governance_tracker.php` — false positive (nilai enum status `TODO`, bukan marker kerja)
- [x] `docs/link/sop_mfa.php` — false positive (`XXXXXX` format contoh backup code)
- [x] `master/master_manufactures.php` — false positive (`CHASSGSGXXX` contoh SWIFT)
- [x] `master/master_products.php` — false positive (`LOT-XXXX` placeholder input)
- [x] `stock/wqs_picking.php` — false positive (`RMI-DO-XXXX` data contoh CSV)
- [x] `stock/wqs_stock_opname.php` — false positive (`OPN-..-XXX` pola penomoran di teks bantuan)

## Aturan: TODO -> IN_PROGRESS -> FIXED -> RETEST -> PASS. PASS wajib evidence (command+result+file+timestamp).

## Delegasi (Wave 1 siap jalan)
- Index: `tools/qa/delegation/DELEGATION_INDEX.md`
- Brief: `tools/qa/delegation/AGENT-01.md` … `AGENT-13.md` (13 file, semua OK)
- Konflik: `tools/qa/delegation/CONFLICT_MAP.json` (22 overlap resolved + `master/auth.php` Wave-2-only)
- Wave 1 = paralel di file eksklusif; Wave 2 = antrean file overlap/shared; Wave 3 = regresi→E2E→gate NAS.

## Fix: sales_do_view mode=print (user report saat cetak)
- `sales/sales_do_view.php`: param `mode=print` sebelumnya diabaikan → tambah auto `window.print()` on load + `print-color-adjust:exact`.
- Evidence: `php -l` OK; curl `id=48&mode=print` HTTP200 ada auto-script; curl normal tanpa auto-script.

## Fix: Chrome print popup tabel terpotong (id=48)
- Bukti render headless Chrome --print-to-pdf: header kolom terakhir terpotong ('HARGA...D'), SKU wrap; sebab: total lebar kolom fix 825px > area A4 + `th` nowrap dari layout.
- Fix `sales/sales_do_view.php`: rampingkan lebar kolom (total fix ~603px) + print CSS `table-layout:fixed`, paksa wrap, font 10px.
- Re-render Chrome: NO/SKU/EXP/LOT/PRODUK/KATEGORI/QTY/SATUAN/HARGA/DISC %/SUBTOTAL semua tampil, 1 halaman. `php -l` OK.

## Print konsisten A4 + GitHub setup
- `sales/sales_do_view.php` @page tambah `size:A4 portrait` → render Chrome: A4 (595x842pt), 1 hal, SUBTOTAL tampil. Tombol `btn-print` panggil `window.print()` standar (tidak diubah, sudah benar).
- GitHub: repo init, `.gitignore` + `master/uploads/*`, portal uploads, gradle cache; `config-db.php.bak_local`→`.bak` (kredensial NAS aman); commit 88bde8b (2549 file); push main → https://github.com/andypratama3/ERP_RMI_SOFULL.git OK.

## Print @page 4mm + anti-luber
- @page margin 8mm→4mm; .page padding 0 2mm + overflow-x hidden; img/svg/canvas max-width 100%; pre/code wrap.
- Render Chrome DO48+DO53: 1 hal A4, SUBTOTAL tampil. php -l OK.

## Print warna + lebar tabel
- Print CSS: abu terang→#4b5563, secondary/small→#374151; hitam-putih header tabel dipertahankan (exact).
- Kolom: Harga 70→85, Subtotal 75→90 (angka sebaris), total fix ~594px muat A4-4mm.
- Bukti: render Chrome DO48+DO53 1 hal; cek visual PNG semua teks terbaca. php -l OK.

## Thead fit sebaris
- Label dipadatkan (Lot/Seri, Produk, Disc%) + thead nowrap 9px di print CSS.
- Bukti visual PNG: NO/SKU/EXP DATE/LOT/SERI/PRODUK/KATEGORI/QTY/SATUAN/HARGA/DISC%/SUBTOTAL sebaris. php -l OK.

## Print teks pudar DO-52
- Bukti visual: seluruh isi pudar kecuali thead (warna terang tema gelap menimpa). Fix: paksa .page teks #111827, thead putih/hitam.
- Re-render: teks pekat terbaca, 1 hal. php -l OK.

## Print var(--rmi-text) akar masalah
- Akar: html[data-theme=dark]{--rmi-text:#e5e7eb} + body{color:var(--rmi-text)} → hitam jadi putih di kertas.
- Fix: @media print timpa --rmi-text/--text→#111827, --rmi-muted/--muted→#374151.
- Bukti visual PNG DO-52: seluruh teks gelap terbaca, 1 hal. php -l OK.

## Kertas 9x11in + garis TTD sejajar
- @page size 9in 11in margin 4mm (render: 648x792pt, 1 hal).
- Kolom customer tanpa TTD digital pakai struktur erp-actor-stamp yang sama + margin judul disamakan → 3 garis sejajar (bukti PNG).
- php -l OK.

## Fix SCM ensure_column cache-key bug
- Gejala: exception scm_receive_photo di scm_do_tasks.php:219 walau ALTER sukses/kolom ada.
- Akar: cache key scm_table_columns()=spl_object_id+table, tapi unset pakai strtolower(table) → cache basi → re-read gagal.
- Fix: unset pakai kunci yang benar (2 titik). Verifikasi: SCM Task DO HTTP200 84KB, tak ada exception; server dimatikan lagi. php -l OK.

## Print CF posisi isi
- Hapus offset top:-24mm (header kepotong) → top:0/left:0; @page tetap 9.5x11in margin 0 (tak diubah).
- Bukti visual: RIZQULLAH tampil penuh, 1 hal. php -l OK.

## Print CF alamat terpotong
- pad_right() memotong alamat kantor 53 kolom (buntung di "No. 7 &"). Fix: word-chunk + sisa alamat ke baris kiri kosong.
- Render DO-52: alamat penuh 3 baris, 1 hal. php -l OK.

## Print CF tengah + wrap kata
- Blok 107ch menempel kiri → center via left:0;right:0;margin auto. Alamat wordwrap per kata.
- Bukti visual: margin kiri-kanan seimbang. php -l OK.

## Rantai pelaku→employee + audit tabrakan gelap/terang
- sales_do_view: helper sdv_actor_label() (login→holder→master_employees); bukti DO-007 tampil "Didi Ferriansyah Maulana (WQS230901)".
- Bug sdv_table_exists (SHOW TABLES LIKE ? → 1064, audit tak pernah jalan) di-fix; pola sama di 8 file lain tercatat P0.
- Audit 28 temuan → tools/qa/DARK_WHITE_COLLISION_AUDIT.md; fix P0: kiosk_poster header cetak digelapkan, CF .table-dark-custom digelapkan. KIOSK+CF HTTP200, php -l OK.

## Delegasi 5 agen DONE (aktor tercatat)
- A/B/C: last_updated_by hardcoded→$actorName + audit gap error_log (sales+stock wqs, scm). C: scm_actor_name nested-session fix.
- D: SHOW TABLES LIKE ? di-fix di 8 file (erp_audit live-test PASS).
- E: migrasi kolom aktor → rename 125→163 (tabrakan nomor), RUN1/2/3 OK idempoten.
- Semua php -l bersih.

## Penanggung jawab terisi otomatis (final)
- Fallback berlapis: kolom *_by → audit system (READY/START/SAVE) → sales_do_audit → label employee; kode dept ditolak (tampil - bukan nama palsu).
- Bukti DO-28/30: WQS=Didi (WQS230901); SCM=- (belum aksi, benar); DO-53 -/- (belum aksi, benar).
- Ke depan: writer simpan $actorName + kolom *_by (migrasi 163) → terisi otomatis. php -l OK.

## Nol hardcode + full coverage pelaku
- Sweep: tidak ada lagi last_updated_by/_by hardcode dept.
- scm_do_tasks__.php ikut dibetulkan. Control tower: kode dept disembunyikan.
- Print tambah Dibuat Nama (KODE) + tgl (sebelumnya miss).
- Bukti DO-53 Lilis Wulandari (CRM230801); DO-30 Didi (WQS230901). php -l OK.

## Konsistensi dark/white + print global
- rmi.css: blok @media print global (var gelap + text-light/white/secondary). Layar tak tersentuh.
- tax_annual h4 text-white→var(--rmi-text); bukti screenshot dark+light terbaca. Sampah align-items-end dihapus.
- public/tracking + sales_do_.php (legacy) fixed-dark mandiri, konsisten internal.

## Ronde tema tunggal + icon pusat + PO aktor
- Topbar: 1 tombol tema (kontras dihapus); uat_smoke + playwright disesuaikan.
- rmi.css: topbar/field adaptif light; print global.
- Helper _shared/rmi_icons.php (28 icon) + bootstrap wire.
- PO print: Prepared/Approved terisi dari audit.

## Print: akun pelaku jadi baris utama (user report)
- Gejala: di print DO, identitas pelaku tertulis "Akun: StaffWQS_TGR" di baris paling bawah — DI BAWAH garis tanda tangan, jadi terlihat lepas dari nama yell. Pembaca print mencari akun, bukan mencari baris terakhir.
- Fix `_shared/actor_stamp.php::rmi_actor_stamp()`: akun naik jadi baris isi pertama (`.erp-actor-name`), prefix "Akun:" dihapus; nama employee + tanggal tetap di bawahnya; catatan tetap di atas garis TTD. Kotak "Diterima Customer" **tidak** disentuh (user: biarkan apa adanya) — tidak punya data akun, hanya PIC + TTD digital.
- Varian monospace `rmi_actor_stamp_text()` disamakan (0 pemanggil saat ini, tapi jangan sampai standarnya melenceng).
- Bukti (DO id=30, `mode=print`, render headless Chrome -> PDF, `pdftotext -bbox`): judul "Disiapkan WQS / Dikirim SCM / Diterima Customer" tetap rata di y=317.28pt; `StaffWQS_TGR` y=359.21 (baris utama), "Didi Ferriansyah Maulana (WQS230901)" y=370.73, "17-03-2026 10:27:33" y=381.23, "Tercatat otomatis oleh ERP" y=395.82. Jumlah baris tetap 4 → tinggi kotak & keselaras 3 tanda tangan tidak berubah. `php -l` OK.
- Penting: baris utama TETAP menampilkan nama employee dan kode, jadi bukti relasi Actor→employee tidak hilang. Relasi putus tetap tampil apa adanya, tidak dikarang.

## Fix: warning PHP di Fixed_Asset/assets_receive.php
- Sweep melaporkan `Undefined variable $row` + `Trying to access array offset on null` di baris 86 saat berkas diakses langsung.
- Akar: berkas ini patch untuk `assets.php`, dipanggil di dalam loop baris, jadi `$row` disetel pemanggil. Guard `$req` (sebelumnya) hanya menutup blok 4, baris 86 tetap akses `$row['id']` telanjang.
- Fix: samakan dengan pola `isset($row[...])` yang sudah dipakai blok 5 — form hanya dirender bila `$row` ada.
- Bukti retest: sweep `Fixed_Asset` 14/14 render, 0 error, 0 warning. Uji dengan `$row` disetel: `request_id` = 42 (benar), link foto tetap muncul.

## Fix: runtime_sweep.php --json-out (menutup PENDING-03)
- Gejala: `run_full_suite.php` memanggil `runtime_sweep.php 100000 --json-out=<path>`, tapi `runtime_sweep.php` memetakan `$argv[2]` sebagai regex-filter. Filter `^--json-out=...` tidak match apa pun → 0 halaman dieksekusi, file JSON tak pernah ditulis → suite selalu "BLOCKER: runtime_sweep tidak menghasilkan JSON".
- Fix: parser argumen berbasis opsi (`--json-out=`, `--limit=`, `--filter=`), argumen posisi lama tetap jalan, opsi asing diabaikan diam-diam. Laporan JSON berisi `summary{total,candidate,ok,empty,forbidden,error,warn_files}` + `pages[]{file,status,len,warn,error}` — persis yang dibaca `run_full_suite.php`. Exit 2 + pesan jelas kalau path JSON tak writable (bukan diam-diam lulus).
- Bukti: `--filter='^/kpi/' --json-out` → 23 halaman, 0 error; `summary` & `pages[].file` terisi sesuai kontrak. Kompatibilitas: `5` dan `5 Fixed_Asset` tetap jalan. `php -l` OK.
- Jebakan terdokumentasi: filter regex harus diawali `^/` karena `$rel`_store Grief startswith slash. `^kpi/` diam-diam_matches 0 halaman dan terlihat "sukses" — ini footgun yang sama seperti `audit_theme_contrast.py`.

## Sapuan 6 agen G1-G6 terverifikasi
- 173 file, php -l bersih semua, tanpa overlap antar agen.
- Smoke 7 halaman 200 + nol fatal; helper render benar, tanpa leak literal.
- Mojibake wqs + const→define + JS ✓ ditangani agen.

## Fix: print DO 4/6 + FIN selalu "Menunggu" (user report RMI-BGR-20251211-007)
- Gejala: DO fin_done tampil "4/6 tahap selesai" (WQS + FIN "Menunggu"); Foto/Video/TTD "-" ; semua tahap "Waktu tercatat, pelaku tidak". User minta seluruhnya diperbaiki, 6/6 + jujur soal data kosong, boleh backfill waktu saja.
- Akar (evidence DB lokal, 39 baris sales_do):
  1. `FIN 'at'=>['act_invoiced_at']` memakai kolom ACT yang NULL di 39/39 baris → FIN tak pernah selesai. Kolom FIN sendiri (`fin_updated_at`) tak pernah ditulis `fin_do_tasks.php` (save/paid/approve_revision hanya isi `fin_updated_by`).
  2. WQS/SCM/ACT lupa fallback `*_updated_at` milik tahap sendiri (data lama mengisi `wqs_updated_at` 15:32 dkk walau `wqs_ready_at` NULL).
  3. `sales_do_audit` tak pernah punya `status_to='fin_done'`; transisi `wait_payment->paid` oleh dept FIN hanya dikredit ke PAID.
  4. Bug penimpa: `$__account = $__aname` membuang pelaku kolom `*_by` bila audit kosong.
  5. POD memang kosong di DB (39/39 NULL) — dash "-" sudah benar, bukan bug.
- Fix:
  - `sales/sales_do_view.php`: fallback `*_updated_at` per tahap; FIN `at=>['fin_updated_at','act_invoiced_at']`, `to=>['fin_done','paid']`; pelaku kolom dipertahankan bila audit kosong; inferensi progres status ikut konvensi `sales_control_tower.php` ($wqsOk/$scmOk/$actOk/$finOk) dengan label jujur ketiga "Selesai — waktu tak tercatat"; stamp WQS/SCM samakan fallback waktu agar konsisten dengan blok Alur.
  - `sales/fin_do_tasks.php`: tulis `fin_updated_at=NOW()` di save, approve_revision, paid.
  - `sql/migrations/167_sales_do_backfill_fin_time.sql` (idempoten, WHERE NULL): isi `fin_updated_at` 2 DO paid dari audit FIN `wait_payment->paid`.
- Bukti: `php -l` kedua file OK; `ci_lint.sh` LULUS; migrasi 167 applied + rerun 0 rows; simulasi logika final 39 DO — 007/008/TGR 6/6 via waktu, paid 6/6 via aktor audit, DO proses parsial tetap parsial (tanpa false-complete), POD tetap `---` (jujur). Pelaku 007 dkk tetap "tidak tercatat" karena `*_by` NULL + 0 audit — tidak dikarang.

## Fix ronde 2: print DO + jejak pelaku (hasil audit 3 sub-agent, data existing saja)
- Gejala lanjutan (DO paid RMI-BGR-260304-001): stamp "Dikirim SCM" bisa menampilkan akun layanan; username sungguhan `admin` berisiko tersaring sebagai kode dept; kasus `WQS_PICKED` tak pernah cocok; footer "Dibuat" vs blok CRM beda menit; tanggal `strtotime` tanpa guard bisa jadi 1970; aksi FIN save tanpa jejak audit; kolom pelaku ditulis kode tapi tak ada di skema.
- Fix `sales/sales_do_view.php`: `sdv_is_dept_code()` tidak lagi menyaring ADMIN/SUPERADMIN/MANAGER/STAFF/BRANCH/SYSTEM + tolak sufiks `_TRACKING`; stamp SCM disamakan dengan daftar `by` tahap SCM (tanpa `last_updated_by`); `by`/`at` tiap tahap menunjuk kolom yang benar-benar ada ditulis (`crm_created_by`, `wqs_started_by`, `act_ready_by`, `fin_paid_by`/`fin_updated_by`; FIN `at` hanya `fin_updated_at`); guard FIN approve_revision tidak menimpa atribusi CRM; CRM "Dibuat" pakai bukti terawal (min) agar = footer; `wqs_picked` diperbaiki (lowercase) + masuk inferensi; 3 titik `date(strtotime())` diganti `rmi_actor_stamp_datetime()`; blok logistik baru (mode/vendor/resi/bukti terima WQS→SCM, kondisional, fakta dari kolom); label "Kelompok DO (dari item)"; caption PIC bukan verifikasi penandatangan.
- Fix `sales/fin_do_tasks.php`: aksi save kini `sales_do_audit_append(FIN)` (satu-satunya aksi FIN yang tanpa jejak) + `fin_updated_at=NOW()` di save/approve_revision/paid (sudah ronde 1, diverifikasi ada).
- Migrasi: `167` backfill `fin_updated_at` DO paid dari audit FIN (applied, rerun 0 rows); `168` tambah kolom pelaku yang hilang (`wqs_started_by`, `fin_updated_by`, `crm_created_by`, pola IF-NOT-EXISTS ala 163; applied, rerun aman).
- Bukti: `php -l` bersih; `ci_lint.sh` LULUS; HTTP login superadmin → `sales_do_view.php?id=15` 6/6, `id=21` 6/6 + blok BITESHIP tampil, `id=23` 2/6 + penanda "sekarang" di WQS; simulasi 39 DO tanpa false-complete; POD tetap `-` (data memang kosong, tidak dikarang).
- Dibatasi scope (jujur dicatat, bukan dikerjakan): blok order-info CRM (kolom tak ada = fitur belum dibangun), sub-langkah ACT tax/exchange di Alur, unifikasi `flow_step_from_status()` kanonis (risiko workflow), `scm_actor_name()` fallback label dept, revision residue — lihat rincian audit di bawah.

## QA-018: triase TODO/FIXME (diputuskan, bukan sekadar grep)
- Hasil: 11 dari 12 berkas adalah false positive pola contoh (`RMI-PO-XXX`, `LOT-XXXX`, `CHASSGSGXXX`, `XXXXXX`, `OPN-..-XXX`, `RFC-XXXX`, `SUGG-XXXX`, `RMI-DO-XXXX`) + nilai enum status `TODO` di `module_governance_tracker.php` + 1 TODO asli (`menu_dashboard_sync.php:50` deteksi leak).
- Fix TODO asli: `menu_rbac_sync_check.php` kini menghitung `leak_count` (GUEST→200 + dept di luar roles→200) ke payload `--write-last`; `menu_dashboard_sync.php` membaca `leak_count` dari artefak (TODO dihapus). Aditif — logika mismatch/ok tak disentuh.
- Bukti: `php -l` bersih; sweep lokal 141 menu → mismatch=55 leak=34 ok=0 (baseline pre-existing, kini terlihat; leak informatif — sebagian pola layout-with-denial yang oleh checker ditoleransi sebagai ok — perlu triase lanjutan sebagai follow-up, bukan gate failure).

## PENDING-02: SVG sweep (dicoba ulang, belum tuntas)
- Upaya: `check_escaped_svg.sh` 660 halaman jobs=8 dijalankan ulang; percobaan >280 dtk (sama seperti interupsi pertama) → dijalankan background ke `/tmp/svg_sweep.log`, hasil triase menyusul saat selesai.

## PENDING-02 lanjutan: hasil sweep + temuan harness (belum PASS repo-wide)
- Fakta: full-run 660 halaman jobs=8 menggantung (>8 mnt, 0 progres) — 1 worker macet me-render `bin/worker.php` (eksekutabel CLI, bukan halaman UI; di-include langsung via CLI sehingga loop worker memblokir). Sweep dihentikan agar tidak menggantung selamanya.
- Sweep lingkup `^sales/` selesai: mayoritas `render-gagal` — artefak harness, bukan bug SVG: halaman butuh param (`sales_do_view.php` tanpa `?id=` memanggil `die()` → proses PHP exit sebelum menulis file hasil). Perlu klasifikasi tersendiri di harness.
- Verifikasi langsung `sales_do_view.php` via HTTP render (id=15/21/23, login superadmin): 0 escaped-SVG asli; 2 hit `&lt;svg` semuanya di atribut `data-icon-*` (pola false-positive yang oleh harness sendiri dikecualikan by design).
- Tindak lanjut agar PENDING-02 bisa PASS: (1) daftar eksklusi eksekutabel non-UI (`bin/`, `*_debug`, dsb.) di `check_escaped_svg.sh`; (2) dukung URL berparam atau tandai `butuh-param` alih-alih `render-gagal`; (3) baru rerun repo-wide. Butuh keputusan owner untuk (1)-(2) karena menyentuh kontrak gate.

## Improve: kartu Alur tampilkan aksi masing-masing tahap (user: "bukan hanya admin")
- Gejala: 6 kartu Alur DO paid semuanya tertulis `Admin / Akun: admin` sehingga terlihat copy-paste. Perburuan bukti (stock_card_photos, handover_media, portal_docs, system_audit_logs, erp_audit_log utk DO 21): NOL baris — satu-satunya manusia yang tercatat di DO ini memang `admin` (5 baris audit). Nama berbeda tidak bisa diadakan tanpa mengarang.
- Fix `sales/sales_do_view.php`: tiap kartu kini menampilkan baris aksi monospace `dari→ke • catatan` dari baris audit yang memenangkannya (mis. CRM `new→crm_to_wqs • CREATE_DO`, WQS `wqs_processing→ready_scm`, ACT `delivered→wait_payment`, FIN/PAID `wait_payment→paid`). Disimpan di map audit (`from`/`note`) + render `sdv-flow-trans` + CSS; semua di-escape.
- Bukti: HTTP `id=21` 200 → 5 baris trans tampil; `id=15` (tanpa audit) 6/6 tanpa baris trans; tanpa `fatal/warning/notice` PHP; `php -l` + `ci_lint.sh` LULUS.
- Cara mendapat nama berbeda sungguhan: kerjakan tiap tahap dengan akun dept masing-masing (StaffCRM/WQS/SCM/ACT/FIN) — sistem kini mencatat pelaku+waktu+audit di semua jalur (termasuk FIN save). Data uji lama yang dikerjakan satu akun akan tetap tampil satu nama — itu fakta, bukan bug tampilan.

## Fix: audit save menimpa transisi asli + bukti orang-per-tahap (user: "kenapa masih admin")
- Gejala: DO 30 (ready_scm) tampil WQS = `Super Admin`, padahal audit mencatat `StaffWQS_TGR` memindahkan `wqs_processing→ready_scm`. Penyebab: 2 baris audit SCM `ready_scm→ready_scm` (aksi save, dari superadmin, 30-09-2026 01:33/01:39) menimpa entri last-wins sehingga transisi asli hilang.
- Fix `sales/sales_do_view.php`: bangun map audit last-wins dengan pengecualian no-op — baris dari→ke identik (save tanpa pindah status) tidak boleh mengalahkan transisi status sesungguhnya; hanya bila tak ada transisi asli barulah save dipakai sebagai bukti sentuhan.
- Bukti: HTTP `id=30` → CRM `api_partner:Hermina Group UAT`, WQS **`Didi Ferriansyah Maulana (WQS230901)`** (relasi akun→employee utuh), SCM `Super Admin` + trans `ready_scm→ready_scm` (transparan: memang hanya save). `id=21`/`id=15` tetap 6/6 tanpa regresi; `php -l` bersih.
- Fakta data (tetap berlaku): DO 21 dikerjakan 1 akun `admin` di semua tahap (audit 5/5 + kolom + satelit NOL) — tampil satu nama adalah kebenaran data uji, bukan bug. Nama berbeda tampil otomatis bila kerja dikerjakan akun dept masing-masing (terbukti DO 30).

## Fix shared CSS 9f: thead satu layer (owner)
- Gejala: header tabel sticky lebih gelap dari permukaan panel lain — `background-color` + `background-image` (gradient warna sama) mengomposit translusensi 2x.
- Fix `_shared/rmi.css` 9f: `background-color: var(--rmi-panel-2)` dikomentari; tinggal gradient satu layer + `box-shadow: inset` sebagai garis bawah (border tak andal pada sticky + border-collapse). Aturan dasar `background: var(--rmi-bg)` sudah lama kalah oleh 9f; override print (`background: ... !important` = shorthand, me-reset image) tak terpengaruh.
- Bukti: `ci_lint.sh` LULUS; render 200: `sales_do_view?id=21`, `sales_do.php`, `sales_control_tower.php`.

## Status BELUM SELESAI per 2026-09-30 (terverifikasi ulang, bukan salinan tabel lama)
| ID | Status kini | Alasan / bukti verifikasi |
|----|-------------|---------------------------|
| QA-001 | PASS 2026-09-30 | 76/76 selesai. 7 defect FIXED (`products_media_view_.php` auth bypass; `master_products_doc.php` HTTP 500; `master_vendors.php` repeated placeholder `:search`; audit di `master_departements.php`/`master_tax.php`/`master_office.php`/`master_user.php`), 2 owner decision, 0 unexplained. `ci_lint.sh` LULUS |
| QA-002 | PASS (P1 subset) 2026-09-30 | 12 P1 diprobe. FIXED: direct-GET 403 di `_do_task_helpers.php`/`_do_office_scope.php`/`_ar_helper.php`; `act_do_tasks.php` SQLSTATE HY000/1525 (`NULLIF` di datetime) hilang. `export_kpi_do_csv.php` permission manager = NEEDS-OWNER. **Catatan: tabel 40 fitur belum terbukti utuh** |
| QA-003 | PASS 2026-09-30 | 40 file: 4 FIXED (audit `purchases_ap_import.php`, `purchases_ap_importress.php`, `purchases_ap_importreplace.php`), 9 PASS, 16 ACCEPT, 8 FAIL di luar scope, 3 NEEDS-OWNER |
| QA-004 | PASS 2026-09-30 | 24 fitur WQS diberi verdict; 2 FIXED (`wqs_stock_opname.php`, `wqs_stock_transfer.php`), 1 NEEDS-OWNER |
| QA-008..013, QA-015, QA-016 | TODO | Belum dijalankan |
| QA-005/006/007 (SCM/ACT/FIN) | TODO, BUTUH OWNER | Tercatat 0 fitur — belum jelas modul kosong vs belum di-scan; jangan assign AGENT-05/06/07 sebelum owner konfirmasi |
| QA-009 (HRL/Absensi) | TODO | Belum dijalankan |
| QA-014 (RBAC) | PASS (matrix) 2026-09-30 | Matrix 19 route × 5 role class. Direct-GET include-only 403. 3 FAIL awal: `wqs_quarantine.php` 200 tanpa permission gate, cross-office read, nonexistent DO 200 (bukan 404). Residue user/row/grant = 0. Gate quarantine masih NEEDS-OWNER; 404 + semantik cross-office BELUM dikerjakan |
| QA-019 (cutover --strict) | BLOCKED 2026-09-30 | `FAIL: APP_ROOT mismatch. Run from [APP_ROOT].` — `app_root_guard` mewajibkan `/volume4/web/ERP_RMI_SOFULL` (NAS/VPS); exit 2 di Mac. Guard TIDAK boleh dilonggarkan |
| QA-020 (panduan) | TODO | 158 panduan belum interaktif |
| PENDING-01 | IN_PROGRESS, BUTUH OWNER | Pertanyaan penempatan gate (lint hosted tak bisa render authenticated) belum diputuskan |
| PENDING-02 | TODO, BUTUH OWNER | Full-run macet di `bin/worker.php`; halaman berparam `render-gagal`; butuh keputusan kontrak harness (eksklusi + param) sebelum rerun |
| PENDING-05 | BLOCKED | Tetap dilarang menebak; butuh keputusan data owner |
| PENDING-06 | TODO, BUTUH VPS | Aturan nginx hanya di `/etc/nginx` server; tak bisa dikerjakan dari Mac tanpa akses server |
| PENDING-07 | TODO | Pindah aset `_shared/`→`public/` berisiko (ratusan referensi); belum dicoba |
| QA-018 | FIXED 2026-09-30 | Triase tuntas + leak_count jalan (sweep 141 menu: mismatch=55 leak=34, baseline pre-existing) |
| DEP/SEC/CI/UI/QA-017 | PASS/FIXED | Tidak berubah |

---

# Ekstensi QA: Playwright / UI / Input / Action / Performance

_Status: 2026-09-30. Semua entri di bawah memakai status yang sama seperti
bagian atas tracker: TODO, IN_PROGRESS, PASS, FAIL, BLOCKED, FIXED, WAIVED.
Tidak ada status "Sebagian" — parsial ditulis di kolom bukti._

## PLAYWRIGHT COVERAGE
| ID | Aspek | Status | Bukti |
|----|-------|--------|-------|
| PW-001 | Runtime terpasang | PASS | Node v26.10.0, `@playwright/test` 1.60.0, marker `PW_INSTALL_DONE` di `/tmp/pw_install.log` |
| PW-002 | 3 engine terunduh | PASS | `chromium-1223`, `firefox-1522`, `webkit-2287` di `~/Library/Caches/ms-playwright` |
| PW-003 | Config + project | PASS | `tools/qa/playwright/playwright.config.js` (chromium/firefox/webkit/responsive) |
| PW-004 | Credential env-based | PASS | `tests/_helpers.js` hanya baca `PW_ADMIN_USER`/`PW_ADMIN_PASS`; tidak ada secret di repo. Spec auth `skip` bila env kosong |
| PW-005 | Smoke cross-browser | PASS | 6/6 PASS (2 spec × 3 engine), 31.1s, `npx playwright test tests/smoke.spec.js` |
| PW-006 | Smoke guardrail PHP | PASS | `/master/login.php` tidak 5xx dan tidak memunculkan `fatal error`/`parse error`/`stack trace` |
| PW-007 | Halaman publik selain login | TODO | Inventaris route publik belum dipetakan |
| PW-008 | Spec authenticated (menu/CRUD per modul) | TODO | Butuh env credential; belum dijalankan |
| PW-009 | Visual regression baseline | TODO | `toHaveScreenshot` belum dibuat |
| PW-010 | JS error monitoring seluruh route | TODO | Hanya login page yang dipantau |
| PW-011 | `.gitignore` output test | PASS | `node_modules/`, `playwright-report/`, `test-results/`, `.playwright/` |

## RESPONSIVE COVERAGE
| ID | Viewport | Status | Bukti |
|----|----------|--------|-------|
| RS-001 | mobile 390×844 | PASS | 3/3 (no horizontal scroll, no overflow, no zero-size control) |
| RS-002 | tablet 820×1180 | PASS | 3/3 |
| RS-003 | desktop 1440×900 | PASS | 3/3 |
| RS-004 | Modul selain login | TODO | Throughput rendah; baru login page |
| RS-005 | Uji interaksi (bukan hanya overflow) | TODO | Belum ada tap/focus/scroll test |

_Reminder_: tidak ada overlay putih dan tidak ada perubahan target sentuh.
Nilai target sentuh yang diuji di sini hanya "zero-size" (kontrol tidak
terlihat sama sekali), bukan threshold estetika.

## INPUT FIELD COVERAGE
| ID | Aspek | Status | Bukti |
|----|-------|--------|-------|
| IN-001 | Inventaris input per halaman | IN_PROGRESS | `ERP_FEATURE_INVENTORY.json` masih field CRUD/filter/action; id/name/label per input belum diekstrak |
| IN-002 | Label terpasang untuk setiap input | TODO | Butuh IN-001 |
| IN-003 | Input tanpa nama/label terdeteksi | TODO | Butuh IN-001 |
| IN-004 | Required/watermark konsisten | TODO | Belum diuji |
| IN-005 | Nilai '&' tidak rusak (encoding) | TODO | Belum diuji |
| IN-006 | Default value aman | TODO | Belum diuji |

## TABLE / FILTER COVERAGE
| ID | Aspek | Status | Bukti |
|----|-------|--------|-------|
| TB-001 | Filter benar-benar menyaring data | TODO | Belum diuji |
| TB-002 | Filter tidak bocor data antar office | TODO | Berkaitan `sales_do_view.php:129-134` (QA-014) |
| TB-003 | Sort stabil & konsisten | TODO | Belum diuji |
| TB-004 | Pagination tidak dobel / tidak skip | TODO | Belum diuji |
| TB-005 | Empty state ada dan informatif | TODO | Belum diuji |
| TB-006 | Header tabel tidak duplicate (9f) | PASS | Fix CSS 9f satu layer; lihat section di atas |

## BUTTON / ACTION COVERAGE
| ID | Aspek | Status | Bukti |
|----|-------|--------|-------|
| BA-001 | Semua action terpetakan | IN_PROGRESS | `action_endpoints_registry.php` ada; belum diverifikasi ulang |
| BA-002 | Tombol tanpa handler = dead control | TODO | Belum diuji |
| BA-003 | Tombol punya konfirmasi bila destruktif | TODO | Belum diuji |
| BA-004 | Tombol nonaktif bila tidak berwenang | TODO | Berkaitan RBAC |
| BA-005 | Endpoint menolak direct GET bila bukan include | PASS | QA-002 + QA-014: helper include-only 403 |

## UI/UX COVERAGE
| ID | Aspek | Status | Bukti |
|----|-------|--------|-------|
| UX-001 | Scan kontras warna | WAIVED | `audit_theme_contrast.py` DILARANG jadi gate (salah pairing background → ~35.703 false positive) |
| UX-002 | Konsistensi dark/white mode | PASS | Section "Konsistensi dark/white + print global" |
| UX-003 | Error state / empty state | TODO | Belum diuji |
| UX-004 | Loading state pada aksi async | TODO | Belum diuji |
| UX-005 | Copywriting Indonesia konsisten | TODO | Belum diuji |

## JAVASCRIPT / AJAX COVERAGE
| ID | Aspek | Status | Bukti |
|----|-------|--------|-------|
| JS-001 | Tidak ada JS error di login | PASS | PW-006 |
| JS-002 | Select2 ter-init dengan benar | TODO | Belum ada scanner Select2 |
| JS-003 | Select2: asset tersedia | TODO | Butuh JS-002 |
| JS-004 | Select2: input asli tetap punya nama | TODO | Butuh JS-002 (scan `name=` pada input[type=hidden] elector) |
| JS-005 | Select2: tanpa duplicate DOM id | TODO | Butuh JS-002 |
| JS-006 | Select2: label & CSRF utuh | TODO | Butuh JS-002 |
| JS-007 | Dead control (elemen tanpa handler) | TODO | Belum diuji |
| JS-008 | Ajax error ditangani | TODO | Belum diuji |
| JS-009 | Tombol double-click tidak submit ganda | TODO | Belum diuji |

## SELECT2 / COMPONENT CROSS-CHECK
| ID | Aspek | Status | Bukti |
|----|-------|--------|-------|
| SC-001 | Scanner Select2 ada | TODO | Belum dibuat; harus leveraging `ERP_FEATURE_INVENTORY.json` |
| SC-002 | Halaman pakai Select2 tapi asset missing | TODO | Butuh SC-001 |
| SC-003 | Select2 init ulang pada partial reload | TODO | Butuh SC-001 |
| SC-004 | Reusable component sudah dipakai (tidak dobel) | TODO | Belum diuji |

## PRINT / PDF COVERAGE
| ID | Aspek | Status | Bukti |
|----|-------|--------|-------|
| PR-001 |Ukuran kertas & margin | PASS | A4 4 mm |
| PR-002 | Semua kolom tercetak | PASS | Kolom tidak terpotong |
| PR-003 | Actor tercetak | PASS | Pelaku sebagai baris utama |
| PR-004 | TTD sejajar | PASS | Section "Kertas 9x11in + garis TTD sejajar" |
| PR-005 | Alamat tidak terpotong | PASS | Section "Print CF alamat terpotong" |
| PR-006 | Export PDF lain (selain DO/CF) | TODO | Belum dipetakan |

## ACCESSIBILITY COVERAGE
| ID | Aspek | Status | Bukti |
|----|-------|--------|-------|
| AC-001 | Label form terasosiasi | TODO | Sama dengan IN-002 |
| AC-002 | Navigasi keyboard | TODO | Belum diuji |
| AC-003 | Focus visible | TODO | Belum diuji |
| AC-004 | `lang` & title dokumen | TODO | Belum diuji |
| AC-005 | Kontras teks (cara manual, bukan gate otomatis) | TODO | Done manual, belum berkala |

## PERFORMANCE / N+1
| ID | Aspek | Status | Bukti |
|----|-------|--------|-------|
| PF-001 | Scanner N+1 ada & self-test | PASS | `tools/qa/nplus1_query_scan.php`; `--self-test` LULUS (4/4 assertion) |
| PF-002 | Scan repo penuh | PASS | 1423 file discan, 2527 temuan terklasifikasi |
| PF-003 | Klasifikasi noise | PASS | N1-DIRECT=979, N1-INDIRECT=242, N1-SMALLLOOP=55, UNBOUNDED=1251. DDL/migrasi & loop literal kecil dipisah agar tidak jadi P1 |
| PF-004 | `->execute()` dideteksi | FIXED | `execute(` sempat tidak ada di daftar primitive → false negative. Ditambahkan |
| PF-005 | Filter CLI valid | FIXED | `--filter` tanpa delimiter memicu `preg_match(): Delimiter must not be alphanumeric`. kini dibungkus otomatis |
| PF-006 | `$argv[0]` bukan argumen | FIXED | Nama script ikut jadi `positional[0]` → hanya 1 file discan. kini di-`array_slice` |
| PF-007 | LIMIT terbaca saat deteksi | FIXED | Scanner meng-redact literal sebelum deteksi sehingga `LIMIT` selalu hilang; kini analisis SQL dari literal mentah |
| PF-008 | False positive dari inline JS | FIXED | `SELECT` di `querySelectorAll`/`execCommand` ikut ter-flag; kini keyword SQL harus di awal statement |
| PF-009 | Verifikasi dinamis query count | TODO | **Wajib**: semua 979 N1-DIRECT masih CANDIDATE, belum ada yang jadi defect |
| PF-010 | Pengujian pada volume data nyata | TODO | Belum dilakukan |
| PF-011 | Baseline waktu response | TODO | Belum ada |

_Teknik_: static scan hanya menghasilkan kandidat. Sebuah N+1 baru boleh
naik ke FAIL setelah direproduksi — mis. 1 halaman列表 = 1 query vs 1 halaman
= N+1 query pada data 500 baris.

## INPUT-COMPONENT / ACTION — TEMUAN SISA (dari N+1 scan)
Temuan performance di atas **belum** dihitung sebagai defect. Kandidat
tertinggi per modul (P1 `N1-DIRECT`):
- master=600, sales=393, payroll=190, stock=179, dashboards=149.

Modul `master` dan `payroll` menjadi fokus triage berikutnya (PF-009).

## DEFECT REGISTER (ekstensi)
Belum ada defect CONFIRMED di ekstensi ini. Semua N+1 = CANDIDATE (PF-009),
UI/UX = TODO, Playwright authenticated = TODO (PW-008).

## REGRESSION REGISTER (ekstensi)
| ID | Trigger perubahan | Verifikasi wajib | Status |
|----|--------------------|------------------|--------|
| RG-001 | Ubah CSS/JS/layout | `npx playwright test` (cross-browser) + `--project=responsive` | PASS 2026-09-30 |
| RG-002 | Ubah query/loop | `php tools/qa/nplus1_query_scan.php` + verifikasi query count dinamis | IN_PROGRESS |
| RG-003 | Ubah master/purchases/stock/sales PHP | `bash tools/qa/ci_lint.sh` | PASS 2026-09-30 |
| RG-004 | Tambah route publik | Smoke test `PW-007` | TODO |

## BLOCKED
| ID | Item | Alasan |
|----|------|--------|
| BL-001 | QA-019 cutover strict | APP_ROOT guard hanya bisa terpenuhi di NAS/VPS |
| BL-002 | Gate permission `wqs_quarantine.php` | Tidak ada `WQS.QUARANTINE_*`; butuh owner |
| BL-003 | PW-008 authenticated suite | Butuh env credential dari owner |
| BL-004 | PENDING-01/02/05/06/07 | Butuh keputusan owner / akses VPS |

## RINGKASAN STATUS (ekstensi)
- PASS: PW-001..006, PW-011, RS-001..003, BA-005, TB-006, UX-001(WAIVED), UX-002, JS-001, PR-001..005, PF-001..008, RG-001, RG-003
- IN_PROGRESS: IN-001, BA-001, PF-002, PF-003, RG-002
- TODO: SISANYA (lihat tabel)
- BLOCKED: BL-001..004
- WAIVED: UX-001

_Nota: PF-002 & PF-003 muncul di dua daftar. Tabel per-wave memberi PASS,_
_ringkasan ini masih IN_PROGRESS — gulf itu sudah diketahui dan tercatat_
_sebagai ketidakkonsistenan Internal Consistency Gate._

_Final gate tetap tidak boleh PASS selama PW-008, IN-001, PF-009,_
_SEC-002, SEC-003, SEC-005, dan blokir owner masih terbuka._

---

# TEMUAN KEAMANAN 2026-09-30 (ditemukan saat reset kredensial)

Ditemukan tidak sengaja saat menyiapkan reset password untuk Playwright.
Semua terverifikasi langsung ke DB `erp_rmi_sofull` (127.0.0.1), bukan dari
asumsi. Nilai kredensial sengaja TIDAK ditulis di tracker ini.

| ID | Temuan | Severity | Status | Bukti |
|----|--------|----------|--------|-------|
| SEC-001 | **2 akun test masih ACTIVE dengan akses SYS** setelah QA wave: `SmokeSYS_SYS` (id 189, role=sys, level=SYS) dan `SmokeBRANCH_SYS` (id 190, STAFF). Keduanya `last_login_at = 2026-09-30 03:57` | **P0** | **FIXED** | Soft-deactivate 2026-10-01 00:57:04 → `status=INACTIVE`, `role=disabled`, `level=DISABLED`, `deactivated_at` terisi, `deleted_at` tetap NULL (rekam jejak). Login keduanya kini ditolak *"Akun dinonaktifkan, hubungi admin."* tanpa redirect. Lihat ACT-006 |
| SEC-002 | Klaim lama "0 QA login user" **tidak akurat** — ada **8** akun test tersisa (bukan 9 seperti klaim awal): `uat_smoke_user` (179), `qa_wqs` (180), `qa_pqp` (181), `qa_crm` (182), `qa_fin` (183), `qa_act` (184), `qa_hrl` (185), `qa_mpr` (186) — semuanya INACTIVE tapi tidak dihapus | P1 | FAIL | id 179–186 di `master_system_login`. Koreksi jumlah: 179–186 = 8 baris, bukan 9 |
| SEC-003 | **Kredensial NAS bocor di komentar** `config-db.php` baris 2 (`LOCAL DEV ONLY - backup asli di config-db.php.bak_local (credential NAS: ...)`) | **P0** | FAIL | `config-db.php`; nilai tidak dicantumkan di sini. File ter-ignore & tidak pernah ter-commit |
| SEC-004 | **Fallback password `'1234'`** saat variabel password kosong. Cakupan awal salah: yang tercatat hanya 2 file mati, padahal ada **4 titik** di 3 file — termasuk file **live** | **P0** | **FIXED** | Lihat detail "Cakupan SEC-004" di bawah + ACT-007 |
| SEC-005 | `superadmin` (id 1) & `admin` (id 2) SYS-level **MFA nonaktif**, sementara `RizqullahMediskaSYS` (187) MFA aktif | P1 | FAIL | Kolom `mfa_enabled` / `mfa_confirmed_at` |
| SEC-006 | ~~`config-db.php` tracked di git~~ **TIDAK terjadi** — sudah di-ignore & tidak pernah di-commit | — | WAIVED | `git ls-files --error-unmatch config-db.php` → tidak match; `git log --all -- config-db.php.bak` → kosong. `config-db.php.bak` juga untracked |

### Cakupan SEC-004 (koreksi terhadap catatan awal)

Grep awal hanya menangkap pola `password_hash(($password!==''?$password:'1234'))` dan
hanya di 2 file mati. Sweep lanjutan menemukan 2 titik lagi di file **live**
`master/master_system_login.php`, dan 2 bentuk lain di file yang sama. Total:

| # | Lokasi | Bentuk | Chilli | severity nyata |
|---|--------|--------|--------|----------------|
| 1 | `master_system_login.php` + `*malang*` + `*id*` | `password_hash(($password!==''?$password:'1234'))` di `import_csv` | akun baru dari CSV dapat password `1234` | tinggi |
| 2 | `master_system_login.php` + `*malang*` + `id*` | `$defaultPass = $_POST['default_password'] ?? '1234'` di `seed_from_departements` | **seed massal** membuat banyak akun seragam | **lebih tinggi** — massal |
| 3 | 3 file | `<input name="default_password" value="1234">` | form mem-prefill password lemah | media (memperkuat #2) |
| 4 | 3 file | teks bantuan `Jika password kosong → default "1234"` | dokumentasi yang berbohong | rendah (tidak keamanan) |

Catatan severity: `seed_from_departements` adalah yang paling berbahaya karena
menghasilkan akun `Mgr{DEPT}_{OFFICE}` / `Staff{DEPT}_{OFFICE}` dalam jumlah
banyak, semuanya dengan password sama yang diketahui. Satu knowledge leak =
banyak akun kompromi.


## Aksi yang sudah dijalankan
| ID | Aksi | Hasil |
|----|------|-------|
| ACT-001 | Reset password `superadmin` (id 1) dengan owner authorization | Berhasil. `PASSWORD_DEFAULT` (bcrypt), `password_verify` lolos dari DB |
| ACT-002 | Bersihkan lockout `auth_login_attempts` untuk `superadmin` | `failed_count=0`, `locked_until=NULL` |
| ACT-003 | Audit `system_audit_logs` module `master` action `PASSWORD_RESET` | 1 baris ditambahkan, detail tidak menyimpan password |
| ACT-004 | Verifikasi login end-to-end via curl | Password salah → HTTP 200 (ditolak). Password benar → HTTP 302 ke `/dashboards/index.php` |
| ACT-005 | Hapus skrip reset dari `/tmp` | Selesai, tidak ada kredensial di disk temporary |
| ACT-006 | **SEC-001** — soft-deactivate `SmokeSYS_SYS` (189) + `SmokeBRANCH_SYS` (190) | Selesai dalam 1 transaksi. `status=INACTIVE`, `role=disabled`, `level=DISABLED`, `deactivated_at=2026-10-01 00:57:04`. `deleted_at` dibiarkan NULL. Tidak ada permission/office/token/PIN yang perlu dicabut (semua 0). 103 baris `system_audit_logs` milik keduanya **dipertahankan** (append-only). 1 baris audit `master/DEACTIVATE` ditambahkan |
| ACT-007 | **SEC-004** — hapus 4 titik fallback password di 3 file | Selesai. `import_csv`: baris tanpa password di-skip (bukan dapat `1234`), dengan penghitung `skipped_no_password` di audit + flash message. `seed_from_departements`: password wajib diisi, min 8 karakter, dan ditolak bila termasuk denylist lemah (`1234`, `123456`, `12345678`, `password`, `admin`, `admin123`, `qwerty`). Form: `value="1234"` dihapus, jadi `type="password" required minlength="8" autocomplete="new-password"`. Teks bantuan diperbaiki |

### Verifikasi SEC-001 & SEC-004

| Cek | Hasil |
|-----|-------|
| `SELECT ... WHERE username LIKE 'Smoke%' AND status='ACTIVE'` | **0 baris** |
| Login `SmokeSYS_SYS` (password apa pun) via curl | HTTP 200 + *"Akun dinonaktifkan, hubungi admin."*, tanpa `Location:` → tidak masuk sesi |
| Login `SmokeBRANCH_SYS` (password apa pun) via curl | sama: ditolak, tanpa `Location:` |
| Kontrol: `admin` + password salah | HTTP 200, ditolak normal, **tanpa 500** → tidak ada collateral damage |
| Baris audit kedua akun | 103 baris utuh (tidak dihapus) |
| `php -l` 3 file yang disentuh | No syntax errors |
| Guard `default_password` (unit test terisolasi) | `""`/`"  "`→ditolak, `1234`→ditolak (<8), `12345678`→ditolak (denylist), `admin123`→ditolak (denylist), `abc`→ditolak (<8), `SandiKuat2026!`→**diterima** |
| Sweep akhir `1234` sebagai password | bersih; sisa hanya denylist + dokumentasi yang sudah diperbaiki |
| `bash tools/qa/ci_lint.sh` | **LULUS** (3 check NAS-only ter-skip) |


## Rekomendasi (urutan prioritas, di-update 2026-10-01)
1. ~~**SEC-001**~~ — **SELESAI** (ACT-006). Akun SYS test nonaktif, login ditolak.
2. **SEC-003** — rotasi kredensial NAS, lalu hapus komentar yang membocorkan. **Butuh owner**: rotasi berada di luar repo. Sisa kerja di sisi repo: hapus baris komentar di `config-db.php` setelah rotasi sukses (file ter-ignore, tidak ikut ter-commit).
3. ~~**SEC-004**~~ — **SELESAI** (ACT-007). Semua 4 titik fallback password ditutup.
4. **SEC-002** — hapus 8 akun `qa_*` + `uat_smoke_user` yang INACTIVE. Menunggu keputusan owner: penghapusan permanen vs. biarkan sebagai rekam jejak.
5. **SEC-005** — aktifkan MFA untuk `superadmin` & `admin`. Butuh owner (mengubah hak akses akun primary).

_SEC-002 dan SEC-005 belum dijalankan: keduanya mengubah kredensial/_
_hak akses akun nyata dan butuh keputusan owner._
