# SYS / ITC — Akses, Audit & Operasi IT

## Ringkasan alur

**ITC/SYS** mengelola **user**, **RBAC**, **navigasi**, **tools** QA/backup, serta **monitoring** audit & error. **SYS** = level/role canonical untuk kebijakan keras (bukan menambah role baru sembarangan).

## Halaman utama

| Path | Fungsi | Role / dept tipikal |
|------|--------|---------------------|
| `rbac/index.php` | RBAC matrix & permission | ITC, SYS |
| `master/audit_logs.php` | Audit log terpusat | ITC, SYS (+ RBAC) |
| `master/monitoring_center.php` | Hub monitoring & pintasan | ITC, SYS (+ RBAC) |
| `docs/monitoring_guide.php` | Panduan monitoring | ITC, SYS |
| `master/itc_reset_password.php` | Reset password user | ITC |
| `master/nav_manager.php` | Override menu drawer | ITC, SYS |
| `tools/index.php` | Tools, smoke, backup | ITC, SYS |
| `dashboards/itc/itc_dashboard.php` | Dashboard ITC | ITC |
| `master/mfa_settings.php` | MFA per user | Semua login (diri sendiri) |
| `master/login.php` / `logout.php` | Autentikasi | Publik / semua |

## Kebijakan sesi & keamanan

- `docs/SESSION_POLICY.md` — cookie, **session_regenerate_id**, idle timeout (30m / SYS bucket 60m).
- `docs/MONITORING_AND_CONTROL.md` — log file, audit DB, error global.

## Deep-dive

- `docs/RBAC_PERIODIC_REVIEW.md`, `docs/RBAC_PERMISSION_GUIDE.md`.
- `docs/TROUBLESHOOTING_LOGIN_RESET_PASSWORD.md`.
- Baseline deploy: `docs/BASELINES.md`.
