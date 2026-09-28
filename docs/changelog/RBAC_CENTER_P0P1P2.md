# RBAC Center — ringkasan perubahan

**File utama:** `rbac/index.php`  
**Referensi:** Maret 2026  

Dokumen ini merangkum hardening (P0), audit (P1), fitur preview/simulasi (P2), serta penyelarasan policy route dan panel Health/QA.

---

## Cutover HRL — hapus permission duplikat (2026-03)

- **Registry:** `HRL.REQUEST_KENAIKAN_GAJI`, `HRL.REQUEST_REKRUTMEN`, `REG_ALKES_READ|WRITE|EXPORT` dihapus dari `config/rbac_permissions.php`; matrix memakai `HRL.REQ_*_CREATE` dan `HRL.REG_ALKES_*`.
- **DB:** jalankan `sql/migrations/161_rbac_hrl_drop_duplicate_perms.sql` setelah **Sync Permissions** (NAS).
- **Kode:** `rbac_perm_equivalent_codes` tidak lagi memetakan pasangan itu; `hrlp_process_rbac.php` hanya cek `HRL.REQ_<TIPE>_*`.

## Matrix: Per modul / Per tipe + hardening dept URL (2026-03)

- **Tampilan:** tab **Per modul** (default) vs **Per tipe** (`rbac_layout=category`) — kelompok VIEW / CREATE / EDIT / DELETE / APPROVE / … lalu sub-blok modul; filter cari & dropdown tipe tetap dipakai.
- **Keamanan:** `dept_code` di URL yang tidak ada di master canonical + DB dipaksa balik ke dept pertama; pintasan SYS tetap GET saja; simpan matrix = POST + CSRF + validasi dept/role (tak berubah).
- **JS:** hitung progress per blok `.mod-block` lewat `updateModCountForBlock` agar layout Per tipe tidak salah hitung.

## Prune orphan registry (2026-03)

- **Masalah:** Sync Full **additive** → DB bisa punya `perm_code` yang sudah tidak ada di `config/rbac_permissions.php` (mis. 459 aktif vs 425 di file).
- **Solusi:** `rbac_prune_permissions_not_in_config()` di `_shared/rbac.php`; RBAC Center tab **Sync** → **Hapus orphan registry**; CLI `tools/rbac/prune_rbac_orphan_permissions.php` (`--dry-run` default, `--execute` untuk hapus). `tools/rbac_diff_config_db.php` diarahkan ke langkah ini (bukan “tambah ke config”).

## Cutover global — mirror RBAC Center (2026-03)

- **Merge map:** `config/rbac_legacy_merge_map.php` (~78 pasangan: `SALES_READ`→`SALES.VIEW`, `PO_CREATE`→`PURCHASES.PO_CREATE`, `PAYROLL.RUN_*`→`PAYROLL.*`, dll.).
- **Runtime:** `rbac_alias_candidates()` + forward map agar `can()` mengenali matrix lama atau kode baru.
- **DB:** `sql/migrations/162_rbac_merge_legacy_mirror.sql` (generate: `php tools/rbac/gen_162_rbac_merge_legacy_migration.php`).
- **Registry:** blok “DB MIRROR” besar di `config/rbac_permissions.php` dipangkas; sisa ACT/FIN/SCM/SALES task/WF/WQS + `MASTER_WRITE`.
- **Dokumentasi:** `docs/RBAC_LEGACY_MERGE.md`.
- **P2b:** HRL `HRL_COMPLIANCE_*` / `HRL_REG_ALKES.*`, `HRL.DOCS_EDIT`, semua `MASTER.*_CRUD` & `PURCHASES/WQS *_CRUD`, `WQS.ALLOCATION`, `SALES.DO`, `PURCHASES.PO`, `MPR.ACCESS`, `SYSTEM.RBAC_VIEW` — masuk merge map; baris duplikat dihapus dari `config/rbac_permissions.php`; regenerasi **162** (112 pasangan).

---

## Ringkasan cepat

| Topik | Isi singkat |
|--------|-------------|
| **Akses halaman** | `SYSTEM.RBAC_MANAGE` **atau** `SYSTEM.RBAC_VIEW` |
| **Mutasi** | Hanya `RBAC_MANAGE` (POST ditolak untuk VIEW) |
| **Policy route** | `_shared/rbac_policy.php`: `/rbac/*` → dept `ALL`, level `STAFF` / `MANAGER` / `SYS` |
| **Tools** | `/tools/*` tetap SYS-only; skrip QA banyaknya CLI di NAS |
| **Changelog teknis** | Lihat tabel di bawah |

---

## P0 — Keamanan & konsistensi

- Output dinamis memakai **`rmi_h()`** (bukan fungsi `h()` lokal).
- **`require_any_permission(['SYSTEM.RBAC_MANAGE', 'SYSTEM.RBAC_VIEW'])`**.
- **`$rbacManage`** = `can('SYSTEM.RBAC_MANAGE')`; tanpa ini semua POST mutasi ditolak (flash + redirect).
- **Export JSON:** hanya `RBAC_MANAGE`; jika ditolak → `text/plain` + pesan jelas.
- **Simpan matrix & import JSON:** validasi **`rbac_index_valid_dept_role()`**.
- **Error DB ke user:** pesan generik jika teks mengandung `SQLSTATE` / `PDO`; detail di log (`rmi_log_module_error`).
- **CSRF:** tetap **`rmi_csrf_verify`**.

## P1 — Audit & pesan sukses

- **`SAVE_MATRIX`:** bandingkan state lama vs baru → hitung **ditambah / dihapus**.
- **`master_audit`:** `details` berisi `added_count`, `removed_count`, `added_sample`, `removed_sample` (sampel maks. 40 kode).
- Flash sukses menyebut **total allow**, **ditambah**, **dihapus**.

## P2 — Preview & permission efektif

- **Sebelum simpan matrix:** JavaScript membandingkan checkbox dengan state awal (`#rbac-matrix-initial`); `confirm()` ringkas; **`matrix_confirmed`** menghindari loop.
- **Simulasi user:** dropdown (500 user, urut nama); GET `effective_user=<id>`; **`rbac_index_compute_effective()`** = matrix dept/role + `rbac_user_permissions`; **privileged** (SYS/ADMIN/SUPERADMIN) = penjelasan allow-all.

## Policy route & Health/QA

- **`rbac_policy.php`:** entri eksplisit untuk `index`, `panduan`, `v1_legacy`, lalu `/rbac/*` — semua **`ALL` + STAFF/MANAGER/SYS**, GET+POST. Izin halaman tetap di **`rbac/*.php`**.
- **`rbac_completeness_check.php`:** `/rbac/*` tidak lagi diwajibkan SYS-only; ada **warn** jika rule `/rbac/index.php` tidak sesuai pola di atas.
- **Sidebar RBAC Center — Health/QA:** perintah CLI di NAS; link web opsional ke **`tools/rbac_diff_config_db.php`** (privileged); **`rbac_coverage_check.php` = CLI only**.

## Registry — HRL vs HRL Process (blok matrix)

- Permission tetap **`HRL.PROCESS_*`**; kolom **`rbac_permissions.module`** untuk empat baris tersebut = **`HRL_PROCESS`** agar di RBAC Center tampil sebagai modul terpisah dari **HRL** (selaras pemisahan menu **HRL Process**).
- Sumber kebenaran: `config/rbac_permissions.php` + fallback `_shared/rbac.php`; DB: migration **`sql/migrations/159_rbac_hrl_process_module_split.sql`** atau **Sync Permissions**.

## HRL Process — per tipe pengajuan (`HRL.REQ_*`)

- **28 permission** `HRL.REQ_<TIPE>_{VIEW|CREATE|EDIT|DELETE}` untuk tipe: CUTI, IZIN, LEMBUR, PERJADIN, PERMINTAAN_KARYAWAN, KENAIKAN_GAJI, REKRUTMEN.
- Helper: `_shared/hrlp_process_rbac.php` — **`HRL.PROCESS_*`** tetap mengizinkan semua tipe (kompatibel); granular mengoverride per tipe.
- UI Tower memfilter form & list sesuai izin; detail/request memakai **EDIT** untuk alur approve/reject/Paid.

## Katalog RBAC per modul (CRUD-oriented)

- **`docs/RBAC_CATALOG_BY_MODULE.md`** — semua permission dari `config/rbac_permissions.php`, dikelompokkan per kolom `module`, dengan klasifikasi VIEW/CREATE/EDIT/DELETE/legacy.
- Regenerate: `php tools/rbac/build_rbac_catalog_md.php`
- **Standar quartet:** `docs/RBAC_CRUD_STANDARD.md` · helper `_shared/rbac_crud.php`

---

## Checklist verifikasi

1. **RBAC_MANAGE:** ubah satu centang → muncul konfirmasi diff → simpan → flash berisi +/− → baris audit `SAVE_MATRIX`.
2. **RBAC_VIEW:** banner mode baca; tidak ada Save/Sync/Export; POST mutasi → flash penolakan.
3. **Simulasi:** user non-privileged → daftar permission + sumber (Matrix / Override / Matrix + override).
4. `php -l rbac/index.php` tanpa error.
5. `php tools/qa/rbac_completeness_check.php` — tidak ada regresi policy SYS-only yang salah untuk `/rbac/*`.
6. Setelah sync/migrasi: di matrix muncul blok **HRL_PROCESS** (📋) terpisah dari **HRL** (👥).

---

## Tautan terkait

- Panduan UI: `rbac/panduan.php`
- README (langkah pengecekan): bagian **RBAC Center**
