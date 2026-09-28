# Jalur kanonik, duplikasi, dan apa yang sengaja ada

Dokumen ini **jujur**: tidak semua baris di repo bisa diaudit otomatis dalam satu langkah. Yang di bawah ini adalah **keputusan arsitektur** dan **perbaikan yang sudah diterapkan** agar tidak ada “lapisan tersembunyi”.

## Sumber kebenaran (kanonik)

| Area | Kanonik | Keterangan |
|------|---------|------------|
| Helper ERP (`rmi_*`, CSRF, redirect) | `_shared/helpers.php` | `helpers.php` di **root** hanya *shim* + guard URL langsung. |
| Bootstrap umum | `_shared/bootstrap.php` → `master/auth.php` | `bootstrap.php` di root memuat `_shared/bootstrap.php`. |
| RBAC runtime urutan gate | `docs/internal/RBAC_RUNTIME_LAYERS.md` | Login → CSRF → `require_rbac` → `rmi_page_registry_guard_try`. |
| RBAC tidak jalan / 403 massal | `docs/internal/RBAC_TROUBLESHOOTING.md` | Dept session, tabel matrix, seed, env ketat. |
| Pengaturan role/permission operasional | `docs/internal/RBAC_SIMPLE_SETUP.md` | User dept/role/level + RBAC Center + page registry. |
| Hub panduan Dashboard Center | `dashboards/panduan.php` | `panduan_index.php` / `panduan_dashboard_center.php` = **301** ke sini (bookmark lama). Registry panduan untuk `dashboards/index.php` + `dashboard_center.php` mengarah ke **`dashboards/panduan.php`** (generator: `tools/gen_panduan_bundle.php`). |
| Page registry (URL unik) | `config/page_registry.php` (+ merge panduan generated) | Skrip cek: tidak ada URL duplikat di merge hasil `require page_registry.php`. |

## Folder yang **bukan** sumber kebenaran operasional

| Path | Alasan |
|------|--------|
| `_backup/` | Arsip; jangan disamakan dengan perilaku produk. |
| `exports/deploy/.../` | Snapshot deploy lama; bisa berbeda dari tree aktif. |

## Duplikasi yang **bukan** bug

- **Banyak file `panduan_*.php`** per modul: masing-masing halaman panduan + stub MD; bukan satu file raksasa — itu fitur navigasi per URL.
- **`tools/*_helpers.php`**, `*_bootstrap.php` per modul: scope berbeda (tools UI, absensi, Fixed Asset, dll.).

## Env / perilaku opsional (tidak disembunyikan)

| Env | Efek |
|-----|------|
| `RMI_RBAC_POLICY_DEPT=1` | `require_rbac()` juga filter `depts` di `rbac_policy.php`. Default: **mati**. |
| `APP_DEBUG` / `RMI_RBAC_VERBOSE_DENY=1` | Detail 403 RBAC di body + log (`storage/logs/rbac_apply.log`). |
| `RMI_DISABLE_PAGE_REGISTRY_GUARD` | Matikan guard registry (hati-hati). |

## Entri dashboard ganda (`index.php` vs `dashboard_center.php`)

Keduanya ada di repo: **`dashboards/index.php`** = Dashboard Center utama (cards, dept landing); **`dashboards/dashboard_center.php`** = layout hub ringan. Bukan duplikasi fungsi identik; bila ingin satu URL saja, itu perlu keputusan produk + migrasi link.

## Maintenance

- Setelah ubah `help_sop_map.json` / struktur panduan: jalankan `php tools/gen_panduan_bundle.php`.
- Setelah tambah halaman ERP baru: tambahkan baris di `config/page_registry.php` (dan policy jika perlu).
