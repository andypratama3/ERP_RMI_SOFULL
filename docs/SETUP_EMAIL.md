# Setup Email — Notifikasi RFQ & Customer Portal

Panduan konfigurasi email untuk notifikasi RFQ (PQP) dan Customer Portal (CRM).

---

## 1. Ringkasan

| Fitur | Penerima | Sumber alamat |
|-------|----------|---------------|
| **RFQ** — notifikasi ke PQP saat manufacturer submit quotation | PQP | `system_config` RFQ.PQP_EMAIL → env `PQP_RFQ_EMAIL` → `ALERT_EMAIL_TO` |
| **RFQ** — notifikasi ke manufacturer saat RFQ open | Manufacturer | `manufacturer_portal_users.email` |
| **Customer Portal** — notifikasi ke CRM saat order baru | CRM | `system_config` CUSTOMER_PORTAL.CRM_EMAIL → env `CUSTOMER_PORTAL_CRM_EMAIL` → `ALERT_EMAIL_TO` |

---

## 2. Langkah Setup

### 2.1 Aktifkan pengiriman email

Di `.env` (root project):

```
ENABLE_EMAIL_ALERTS=1
```

Untuk RFQ saja bisa pakai `ENABLE_RFQ_EMAIL=1` (fallback).

### 2.2 Alamat penerima (opsional)

**Prioritas:** `system_config` → env → fallback.

Jika sudah diisi di **System Config** (Master Data → System Config), env tidak wajib.

| Env | Kegunaan | Contoh |
|-----|----------|--------|
| `PQP_RFQ_EMAIL` | Fallback jika system_config RFQ.PQP_EMAIL kosong | `pqp@domain.com,other@domain.com` |
| `CUSTOMER_PORTAL_CRM_EMAIL` | Fallback jika system_config CUSTOMER_PORTAL.CRM_EMAIL kosong | `crm@domain.com,crm2@domain.com` |
| `ALERT_EMAIL_TO` | Fallback umum (tools alert, RFQ, portal) | `admin@domain.com` |

Format: comma-separated, tanpa spasi.

### 2.3 System Config (disarankan)

Atur di **Master Data → System Config**:

- **RFQ** → **PQP_EMAIL**: `pqp@rizqullahmediska.com,rizqullahmediskapqp@rizqullahmediskaindonesia.com`
- **CUSTOMER_PORTAL** → **CRM_EMAIL**: `crm@rizqullahmediska.com,crm@rizqullahmediskaindonesia.com`

Migration 153 & 154 memastikan config ini ada dan tidak duplikat.

---

## 3. Server: Pengiriman Email

Aplikasi memakai **PHP `mail()`**. Agar email terkirim, server harus bisa mengirim:

### 3.1 Linux / NAS (Synology)

- **Synology Mail Server** harus terpasang (menyediakan sendmail/Postfix)
- Konfigurasi **SMTP relay** (Gmail/SendGrid) agar email terkirim
- Edit **php.ini**: `sendmail_path`, `sendmail_from`

**Panduan lengkap:** `docs/SETUP_EMAIL_SYNOLOGY_NAS.md`

### 3.2 Shared hosting

- Biasanya sudah siap; `mail()` langsung berfungsi

### 3.3 Cek apakah server bisa kirim email

```bash
php -r "var_dump(mail('test@example.com', 'Test', 'Body'));"
```

`true` = berhasil; `false` = gagal (cek log MTA).

---

## 4. Deliverability (opsional)

Agar email tidak masuk spam:

| Setting | Keterangan |
|---------|------------|
| **SPF** | Record TXT di DNS domain pengirim |
| **DKIM** | Tanda tangan email |
| **DMARC** | Kebijakan penanganan email gagal verifikasi |

Atur di panel DNS domain (mis. Cloudflare, cPanel).

---

## 5. Verifikasi

1. **RFQ:** Buat RFQ (status Open) → manufacturer submit quotation → cek inbox PQP
2. **Customer Portal:** Customer checkout → cek inbox CRM
3. **System Config:** Pastikan PQP_EMAIL & CRM_EMAIL masing-masing 1 baris (jalankan migration 154 jika ada duplikat)

---

## 6. Referensi

- **Setup Synology NAS:** `docs/SETUP_EMAIL_SYNOLOGY_NAS.md` — langkah install Mail Server, SMTP relay, php.ini
- **UAT RFQ:** `docs/UAT_RFQ.md`
- **SOP Customer Portal:** `docs/SOP_CUSTOMER_PORTAL.md`
- **README:** bagian RFQ & Customer Portal
