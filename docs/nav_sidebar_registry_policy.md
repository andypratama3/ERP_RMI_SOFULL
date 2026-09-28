# Kebijakan internal: Sidebar vs Page Registry vs utilitas

Dokumen ringkas untuk merapikan navigasi dan RBAC (`ERP_RMI_SOFULL`).

## Tiga tingkat

| Tingkat | Sidebar kiri (`nav_config` + Nav Manager) | Page Registry (`config/page_registry.php`) | Contoh |
|--------|-------------------------------------------|-----------------------------------------------|--------|
| **A — Wajib operasional** | Ya | Ya | Modul yang dipakai harian: Absensi (check-in, riwayat, pengajuan), DO, stok utama, dll. |
| **B — Cukup Registry** | Tidak wajib | Ya | Halaman detail, print, export sekali jalan, sub-alur yang cukup dari deep link / tombol di halaman. |
| **C — Tidak perlu keduanya** | Tidak | Opsional / minimal | Login, logout, auth bootstrap, AJAX, util internal, tracking public — **bukan** menu ERP. |

## Aturan praktis

1. **Keamanan**: Semua route sensitif tetap pakai **guard RBAC di file**; sidebar hanya membantu penemuan, bukan satu-satunya gate.
2. **SYS / admin**: Boleh banyak entri di registry (audit); sidebar tidak perlu menampilkan semua — hindari menu berlebihan.
3. **Audit paralel**: Laporan `rbac/nav_parallel_report.php` dan CLI `tools/rbac/nav_parallel_sources_report.php` membandingkan sumber; **gap besar Registry ⊄ sidebar sering wajar** untuk kategori B.
4. **Bootstrap tab bar**: Tautan di `*_bootstrap.php` yang sama dengan item sidebar mengurangi kebingungan; tautan ke `master/login.php` / `auth.php` **bukan** gap produk. Laporan gap `in_bootstrap_toolbar_not_in_sidebar_nav` memang **mengecualikan** `master/auth.php`, `master/login.php`, `master/logout.php`. Path kanonikal **WQS Task DO** di menu = `stock/wqs_do_tasks.php` (wrapper RBAC); implementasi UI tetap di `sales/wqs_do_tasks.php` (di-include oleh wrapper stock). **`_shared/rbac_policy.php`**: override `/stock/wqs_do_tasks.php` = **WQS + SYS** (sama seperti rule lama); `/sales/wqs_do_tasks.php` tetap ada sebagai **legacy** dengan gate yang sama, sebelum wildcard `/sales/*`.

## Absensi (prioritas modul)

Menu sidebar **ABSENSI** memaketkan alur utama (beranda, check-in/out, riwayat, pengajuan, approval, panduan, admin) dengan **`perm`** agar selaras Page Registry dan RBAC Center.

Terakhir diperbarui otomatis bersama perubahan repo (lihat commit / PR).
