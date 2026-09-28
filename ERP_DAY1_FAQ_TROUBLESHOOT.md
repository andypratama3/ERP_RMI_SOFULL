# FAQ & Troubleshooting — Day 1 ERP

## Login & Akses

**Q: Tidak bisa login?**  
A: Pastikan username/password benar. Reset password via ITC jika lupa. Cek juga koneksi jaringan.

**Q: Menu tidak muncul?**  
A: Pastikan akun sudah punya department dan office_code. Hubungi admin jika belum.

**Q: Halaman 403 Forbidden?**  
A: Role/permission belum sesuai. Hubungi RBAC admin untuk penyesuaian.

## Transaksi & Data

**Q: Form tidak bisa submit?**  
A: Cek validasi (field wajib, format). Pastikan CSRF token valid (refresh halaman jika perlu).

**Q: Data tidak tersimpan?**  
A: Cek koneksi DB, log error di storage/logs. Eskalasi ke L2 jika berulang.

**Q: Laporan lambat?**  
A: Query berat di jam sibuk. Coba filter lebih spesifik atau tunggu beberapa menit.

## Eskalasi

| Severity | Contoh | Tindakan |
|----------|--------|----------|
| SEV1 | Sistem down, tidak bisa login sama sekali | Eskalasi segera ke war-room |
| SEV2 | Modul inti tidak berfungsi | Prioritas tinggi, ETA 2 jam |
| SEV3 | Bug minor, workaround tersedia | Backlog, ditangani sesuai kapasitas |

## Kontak

- Help Center: `/docs/help_center.php`
- Account Readiness: `/master/account_readiness.php`
