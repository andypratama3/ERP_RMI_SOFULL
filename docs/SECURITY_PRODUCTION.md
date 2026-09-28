# Checklist Keamanan Production — ERP_RMI_SOFULL

Untuk https://erp.rizqullahmediska.com/ERP_RMI_SOFULL

## Wajib di .env (Production)

```env
APP_ENV=production
APP_DEBUG=false
```

**Penting:** `APP_DEBUG=false` mencegah stack trace dan path internal terpapar ke user.

## .env & Credential

- Pastikan `.env` **tidak** di-commit ke git (tambahkan ke `.gitignore` jika pakai git)
- Pastikan `.env` **tidak** bisa diakses via web (di luar document root atau dilindungi)
- Set permission file: `chmod 600 .env` di server

## Perbaikan yang Sudah Diterapkan

1. **CSRF** — MFA Verify, Manufactures, reg_alkes_case
2. **XSS** — Flash message di semua master + sales_do di-escape
3. **display_errors** — mpr, payroll, Fixed_Asset bootstrap mengikuti APP_DEBUG

## Verifikasi

Setelah deploy, cek:

```bash
# Di NAS
cd /volume4/web/ERP_RMI_SOFULL
grep -E "APP_DEBUG|APP_ENV" .env
# Harus: APP_DEBUG=false, APP_ENV=production
```

## Laporan Audit

Laporan lengkap: tools/enterprise_audit.php (jika tersedia)
