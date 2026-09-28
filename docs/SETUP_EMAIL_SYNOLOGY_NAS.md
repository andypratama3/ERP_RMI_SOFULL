# Setup Email di Synology NAS — Agar PHP mail() Bisa Kirim

Panduan agar notifikasi RFQ & Customer Portal bisa terkirim dari ERP yang berjalan di Synology NAS.

---

## Ringkasan

ERP memakai **PHP `mail()`**. Di Synology, `mail()` butuh:
1. **Synology Mail Server** (menyediakan sendmail/Postfix)
2. **SMTP relay** — Outlook/Microsoft 365, Gmail, atau SMTP lain
3. **php.ini** — `sendmail_path` & `sendmail_from`

---

## Langkah 1: Install Synology Mail Server

1. Buka **Package Center** di DSM
2. Cari **Mail Server** (atau **MailPlus Server**)
3. Klik **Install**

> Tanpa paket ini, PHP `mail()` tidak punya program sendmail.

---

## Langkah 2: Konfigurasi SMTP Relay

Agar email terkirim ke Gmail/Yahoo/Outlook (bukan spam), relay via SMTP eksternal.

### Opsi A: Outlook / Microsoft 365 SMTP

1. **App Password** (jika akun pakai 2FA):
   - Buka [Microsoft Account → Security → Advanced security](https://account.microsoft.com/security)
   - **App passwords** → Create new → copy password

2. **DSM → Control Panel → Notification → Email**
   - Enable **Send notification emails**
   - **SMTP server:** `smtp.office365.com` (Microsoft 365) atau `smtp-mail.outlook.com` (Outlook.com)
   - **Port:** `587`
   - **Encryption:** STARTTLS
   - **Authentication:** Enable
   - **Username:** alamat email lengkap (mis. `pqp@rizqullahmediska.com`)
   - **Password:** password akun atau App Password
   - **Sender email:** sama dengan username

3. **Test** — kirim test email dari DSM. Jika berhasil, relay Outlook aktif.

### Opsi B: Gmail SMTP

1. **Google App Password**
   - Aktifkan **2-Step Verification** di akun Google
   - Buka [Google Account → Security → App passwords](https://myaccount.google.com/apppasswords)
   - Buat App Password untuk "Mail" → copy 16 karakter (tanpa spasi)

2. **DSM → Control Panel → Notification → Email**
   - **SMTP server:** `smtp.gmail.com`
   - **Port:** `587`
   - **Encryption:** STARTTLS
   - **Username:** alamat Gmail lengkap
   - **Password:** App Password (16 karakter)

### Opsi C: Synology Mail Server sebagai relay

Jika pakai **Mail Server** (bukan hanya DSM Notification):

1. Buka **Mail Server** → **SMTP Relay**
2. Enable relay
3. Isi SMTP server eksternal (Outlook, Gmail, dll) sesuai Opsi A atau B

### Opsi D: SMTP perusahaan

Jika punya SMTP internal (mis. `smtp.rizqullahmediska.com`):
- Isi host, port, user, pass sesuai kebijakan IT

---

## Langkah 3: Atur php.ini untuk Web Station

PHP web (Web Station) harus tahu lokasi sendmail.

### 3.1 Buka php.ini

**Via DSM:**
1. **Package Center** → **Web Station** → **Configure**
2. Tab **PHP** → pilih versi PHP (mis. 7.4 / 8.0)
3. Klik **Edit** atau **Open** php.ini

**Via SSH:**
```bash
# Cek lokasi php.ini
php --ini

# Biasanya salah satu:
# /usr/local/php/etc/php.ini
# /volume1/@appstore/PHP*/usr/local/php/etc/php.ini
```

### 3.2 Edit parameter

Tambahkan atau ubah:

```ini
; Untuk Synology Mail Server
sendmail_path = /var/packages/MailServer/target/sbin/sendmail -t
sendmail_from = noreply@rizqullahmediska.com
```

- `sendmail_path` — path sendmail dari paket Mail Server
- `sendmail_from` — alamat pengirim (gunakan domain yang valid)

> Jika paket bernama **MailPlus Server**, path bisa:
> `/var/packages/MailPlus/target/sbin/sendmail -t`
>
> Cek: `ls /var/packages/*/target/sbin/sendmail`

### 3.3 Simpan & restart

**Via SSH:**
```bash
synoservicectl --restart httpd-user
```

Atau restart **Web Station** dari DSM → Package Center.

---

## Langkah 4: Verifikasi

### 4.1 Test dari command line (SSH ke NAS)

```bash
cd /volume4/web/ERP_RMI_SOFULL

# Pakai PHP yang dipakai web (bisa beda dengan system php)
# Jika pakai erp.sh:
./tools/nas/erp.sh php -r "var_dump(mail('pqp@rizqullahmediska.com', 'Test ERP', 'Body test'));"
```

- `true` = berhasil
- `false` = gagal (cek sendmail_path, SMTP relay, log)

### 4.2 Test dari ERP

1. Set `ENABLE_EMAIL_ALERTS=1` di `.env`
2. Buat RFQ (status Open) → manufacturer submit quotation
3. Cek inbox `pqp@...` — harus ada notifikasi

---

## Troubleshooting

| Masalah | Solusi |
|---------|--------|
| `mail()` return `false` | Cek `sendmail_path` benar; Mail Server terinstall & jalan |
| Email tidak sampai | Cek SMTP relay (Outlook/Gmail: App Password jika 2FA, port 587); cek spam |
| Outlook: auth failed | Pakai App Password jika 2FA aktif; pastikan smtp.office365.com |
| Port 25 diblok ISP | Pakai port 587 (STARTTLS) — Outlook & Gmail mendukung |
| Path sendmail salah | `ls /var/packages/*/target/sbin/sendmail` |
| PHP CLI vs Web beda | Web pakai php.ini dari Web Station; pastikan edit yang dipakai web |

---

## Referensi

- [Microsoft: SMTP settings for Outlook/Office 365](https://support.microsoft.com/en-us/office/pop-imap-and-smtp-settings-for-outlook-com-d088b986-291d-432b-9183-312ab3a82bdc)
- [Synology: Gmail SMTP untuk DSM](https://kb.synology.com/en-global/DSM/tutorial/How_to_use_Gmail_SMTP_server_to_send_emails_for_DSM)
- [Synology Mail Server SMTP](https://kb.synology.com/en-global/DSM/help/MailServer/mailserver_smtp)
- `docs/SETUP_EMAIL.md` — konfigurasi ERP (env, system_config)
