# ERP MASTER TASK TRACKER (SINGLE SOURCE)

> **ATURAN TRACKER (WAJIB, DARI OWNER):** setiap temuan, perubahan, dan hasil
> verifikasi baru **harus** ditulis ke file ini. Tidak ada pekerjaan QA yang
> dianggap selesai kalau tidak tercatat di sini beserta evidence-nya.

_Updated: 2026-09-29 11:05 | Sesi deploy VPS: 46 halaman mati diperbaiki (commit 9876230); config Nginx untuk `_shared/` statis masih di luar repo — lihat PENDING-06 |_

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
| PENDING-03 | `tools/qa/run_full_suite.php` | IN_PROGRESS | belum stabil | Memanggil `runtime_sweep.php --json-out=...` yang belum didukung; hanya cover render SYS, belum CRUD/RBAC/audit/print per role |
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
| QA-018 | ALL | TODO/FIXME discovery | 12 files contain TODO/FIXME/XXX/HACK | P2 | AGENT-13 | IN_PROGRESS | grep list | 2026-09-29 05:07 | rincian di bawah |
| QA-019 | ALL | Final gate cutover --strict | fail_count=0 required | P0 | AGENT-13 | TODO | - | - | run php tools/qa/run_cutover_checks.php --strict --write-last |
| QA-020 | ALL | UX panduan interaktif + tombol aksi | 158 panduan_*.php, view non-interaktif | P1 | AGENT-10 | TODO | - | - | user report: view membingungkan |

## TODO/FIXME files (12)
- [ ] `_backup/purchases_m2_20260308_231256/purchases/purchases_forwarder_quotes.php`
- [ ] `purchases/purchases_forwarder_quotes.php`
- [ ] `tools/qa/_lib/pipeline_steps.php`
- [ ] `tools/qa/_lib/plan_one_pager_lib.php`
- [ ] `tools/qa/_lib/manifest_lock_suggest_lib.php`
- [ ] `tools/qa/menu_dashboard_sync.php`
- [ ] `tools/ops/module_governance_tracker.php`
- [ ] `docs/link/sop_mfa.php`
- [ ] `master/master_manufactures.php`
- [ ] `master/master_products.php`
- [ ] `stock/wqs_picking.php`
- [ ] `stock/wqs_stock_opname.php`

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

## Sapuan 6 agen G1-G6 terverifikasi
- 173 file, php -l bersih semua, tanpa overlap antar agen.
- Smoke 7 halaman 200 + nol fatal; helper render benar, tanpa leak literal.
- Mojibake wqs + const→define + JS ✓ ditangani agen.
