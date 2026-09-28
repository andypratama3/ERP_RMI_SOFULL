# WebAuthn / Passkeys (Face ID, Windows Hello) — Setup

## Ringkasan

ERP RMI SOFULL mendukung **Passkeys** sebagai opsi MFA tambahan selain TOTP (Google Authenticator) dan Backup Code. Passkeys memungkinkan login dengan:

- **Face ID** (iPhone, Mac)
- **Sidik jari** (Android, laptop)
- **Windows Hello** (PC)
- **Security key** (YubiKey, dll.)

## Prasyarat

1. **Composer** — Jalankan `composer install` di root project
2. **Migration 124** — Jalankan `sql/migrations/124_webauthn_credentials.sql` via phpMyAdmin
3. **HTTPS** — WebAuthn memerlukan HTTPS di production (localhost boleh HTTP)
4. **Browser** — Chrome 67+, Firefox 60+, Safari 13+, Edge 18+

## Langkah Setup

### 1. Install dependency

```bash
cd /volume4/web/ERP_RMI_SOFULL
composer install
```

### 2. Jalankan migration

Jalankan SQL di phpMyAdmin:

```sql
-- Dari sql/migrations/124_webauthn_credentials.sql
CREATE TABLE IF NOT EXISTS `auth_webauthn_credentials` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `credential_id` VARCHAR(512) NOT NULL,
  ...
);
```

### 3. Konfigurasi (production)

Di `.env` untuk production:

```
WEBAUTHN_RP_ID=erp.rizqullahmediska.com
```

RP ID harus sama dengan domain (tanpa path, tanpa https://). Jika tidak di-set, diambil dari `HTTP_HOST`.

## Cara Pakai

### Daftar Passkey

1. Login → **Master Data** → **MFA Settings**
2. Pastikan MFA (TOTP) sudah aktif
3. Klik **Tambah Passkey**
4. Ikuti prompt browser (Face ID / sidik jari / PIN)

### Verifikasi dengan Passkey

1. Login dengan username + password
2. Di halaman MFA Verification, klik **Gunakan Passkey (Face ID / Sidik jari)**
3. Verifikasi dengan biometric / PIN

## File Terkait

| File | Fungsi |
|------|--------|
| `master/webauthn_api.php` | API JSON (register_options, register_verify, auth_options, auth_verify) |
| `master/mfa_settings.php` | UI daftar Passkey |
| `master/mfa_verify.php` | Opsi Passkey saat verifikasi MFA |
| `app/Security/WebAuthnService.php` | Wrapper lbuchs/WebAuthn |
| `sql/migrations/124_webauthn_credentials.sql` | Tabel credentials |

## Troubleshooting

- **"WebAuthn not available"** — Pastikan `composer install` sudah dijalankan dan `vendor/autoload.php` ada
- **"Invalid origin"** — RP ID harus match domain. Set `WEBAUTHN_RP_ID` jika pakai IP atau subdomain
- **"Dibatalkan"** — User membatalkan prompt atau perangkat tidak mendukung
