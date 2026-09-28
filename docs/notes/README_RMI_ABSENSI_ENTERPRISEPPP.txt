# RMI Absensi by Photo — Enterprise+++ (Fase 1-3) + GeoFence (Google Maps)

## Instalasi (Drop-in)
1) Copy folder `absensi/` ke root ERP: `ERP_RMI_SOFULL/absensi/`
2) Pastikan folder ini writable: `ERP_RMI_SOFULL/uploads/absensi/`
3) Opsional: import SQL `absensi/_sql/absensi_install.sql` (modul juga auto-create table saat dibuka)

## Akses
- Dashboard: `/absensi/index.php`
- Check-in: `/absensi/checkin.php`
- Check-out: `/absensi/checkout.php`
- Riwayat: `/absensi/history.php`
- Izin/Dinas: `/absensi/request.php`
- Admin HR: `/absensi/admin/rekap.php` (butuh HR admin)

## Menjadikan user HR Admin
Admin HR -> Users: `/absensi/admin/users.php`
- centang HR Admin untuk user yang berhak

## GeoFence (A)
Admin HR -> Office Settings: `/absensi/admin/offices.php`
- Isi `lat/lng` dari Google Maps + radius meter (rekomendasi indoor 120–150m)
- Mode:
  - Wajib (tolak di luar radius)
  - Catat saja (tidak menolak)

## Catatan
- Modul ini tidak mengubah tabel ERP (hanya membuat tabel absensi_*).
- Halaman Users memakai tabel `master_system_login`.
