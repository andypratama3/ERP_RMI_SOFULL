# Security Checklist — ERP_RMI_SOFULL

**Tanggal:** 2026-03-08

---

## 1. Yang Sudah Diverifikasi

| Item | Status | Keterangan |
|------|--------|-------------|
| **config-db.php** | ✅ | Di .gitignore — credential tidak ikut commit |
| **.env** | ✅ | Di .gitignore |
| **API Key** | ✅ | Disimpan hash (password_hash), validasi via password_verify |
| **API Key di log** | ✅ | Tidak ada — log hanya ts, ip, partner, do_id, dll |
| **order_create SQL** | ✅ | Semua pakai prepared statement |
| **storage/logs/*.log** | ✅ | Di .gitignore |
| **storage/backups/*.sql** | ✅ | Di .gitignore |
| **CSRF** | ✅ | Form web pakai verify_csrf (banyak modul) |
| **API Partner** | ✅ | Stateless, auth X-API-Key — CSRF tidak perlu |
| **Environment check** | ✅ | API key Production hanya valid di APP_ENV=production |

---

## 2. Yang Perlu Perhatian

| Item | Rekomendasi |
|------|-------------|
| **tools/nas/check_db_config.php** | Hapus setelah selesai debug — menampilkan config DB (host, port, name, user). Akses via web. |
| **tools/ops/* require_login** | Banyak file include parent yang punya require_login. Pastikan tools hanya diakses admin. |
| **PHP CLI pdo_mysql** | Jika backup cron gagal, cek ekstensi. Tidak berdampak ke keamanan web. |

---

## 3. H2H API — Security

| Aspek | Implementasi |
|-------|--------------|
| Auth | X-API-Key / Bearer — validasi hash di DB |
| Scope | order:create (jika di-set) |
| SQL Injection | Prepared statements |
| Input validation | customers_code, office_code, items — validasi & trim |
| Idempotency | Cegah duplikat DO |
| Log | Tidak log API key; log IP (CF-Connecting-IP), partner, do_id |

---

## 4. Langkah Verifikasi Manual

```bash
# 1. Pastikan config-db tidak ter-commit
git status config-db.php
# Expected: untracked atau ignored

# 2. Cek .gitignore
grep -E "config-db|\.env" .gitignore

# 3. Cek check_db_config — hapus jika tidak dipakai
ls -la tools/nas/check_db_config.php
```

---

## 5. Referensi

- `.cursor/rules/coding-standards.mdc` — PDO, rmi_h(), verify_csrf
- `docs/governance/SECURITY_PHASE5_REPORT.md` — Scan sebelumnya (banyak false positive)
- `api/_lib/partner_auth.php` — Validasi API key
