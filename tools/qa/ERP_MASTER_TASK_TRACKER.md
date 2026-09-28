# ERP MASTER TASK TRACKER (SINGLE SOURCE)

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
