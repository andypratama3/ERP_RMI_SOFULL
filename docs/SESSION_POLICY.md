# Kebijakan sesi login — ERP_RMI_SOFULL

## Cookie (browser)

Ditetapkan di `master/auth.php` dan `_shared/bootstrap.php` **sebelum** `session_start()`:

| Opsi | Nilai | Keterangan |
|------|--------|------------|
| `lifetime` | `0` | Session cookie — umumnya hilang saat browser ditutup. |
| `path` | `/` | Berlaku untuk seluruh path aplikasi. |
| `domain` | `''` | Host yang diakses saja. |
| `secure` | HTTPS / proxy | `true` jika HTTPS atau `X-Forwarded-Proto: https`. |
| `httponly` | `true` | JavaScript tidak membaca cookie sesi. |
| `samesite` | `Lax` | Keseimbangan CSRF vs navigasi normal. |

## `session_regenerate_id` (anti session fixation)

- **Sudah dipakai** setelah autentikasi sukses lewat **`auth_session_mark_login_complete()`** di `master/auth.php`.
- Dipanggil dari:
  - `master/login.php` → `login_finalize_session()` (password OK, tanpa MFA atau sebelum MFA).
  - `master/mfa_verify.php` (setelah OTP/backup code valid).

## Idle timeout (server, rolling)

Cookie tetap `lifetime = 0`; **batas waktu nyata** = tidak ada request dalam X detik:

| Kelompok | Default | Env |
|----------|---------|-----|
| User biasa (bukan SYS bucket) | **30 menit** (1800 s) | `RMI_SESSION_IDLE_SECONDS` |
| **SYS bucket**: `level`/`role` = SYS **atau** `department` = SYS | **60 menit** (3600 s) | `RMI_SESSION_IDLE_SYS_SECONDS` |

Minimum yang diizinkan di kode: **300 detik** (5 menit) jika env di-set terlalu kecil.

Pada setiap request yang sudah login (`auth_require_login`), stempel `$_SESSION['_rmi_last_activity']` diperbarui. Jika lewat batas → sesi dihancurkan, audit `SESSION_IDLE_EXPIRED`, redirect ke `login.php?reason=session_idle` (atau 401 JSON untuk API yang mengharapkan JSON).

## Umur file sesi di server (`session.gc_maxlifetime`)

Harus **≥** batas idle SYS. Default **7200** detik (2 jam) jika env tidak set.

| Env | Arti |
|-----|------|
| `RMI_SESSION_GC_MAXLIFETIME` | Detik — PHP boleh simpan file sesi di server sebelum GC (set sebelum/ setelah `session_start()` via `auth_session_apply_gc_maxlifetime()`). |

## Contoh `.env`

```env
# Idle: staff/manager default 30 menit; SYS bucket 60 menit
RMI_SESSION_IDLE_SECONDS=1800
RMI_SESSION_IDLE_SYS_SECONDS=3600

# Umur data sesi di storage server (≥ idle SYS)
RMI_SESSION_GC_MAXLIFETIME=7200
```

## Verifikasi cepat

1. Login sebagai staff → biarkan tab terbuka > 30 menit tanpa klik → refresh harus ke login dengan pesan idle.
2. Login sebagai SYS (atau dept SYS) → batas 60 menit (default).
3. Setelah login, di DevTools → Application → Cookies: cookie sesi **HttpOnly**, **SameSite=Lax**, **Secure** jika HTTPS.
