# Indeks API — ERP_RMI_SOFULL

Dokumen ini **hanya referensi**; tidak mengubah perilaku server. Base URL contoh: `{APP_URL}` + path di bawah (mis. `https://erp.example.com/ERP_RMI_SOFULL`).

**Lihat juga:** `docs/governance/API_DEPRECATION_NON_V1.md`, `docs/governance/API_ERROR_ENVELOPES.md`, `docs/governance/OPENAPI_MOBILE.yaml`, `docs/governance/OPENAPI_PARTNER.yaml`.

---

## 1. Health (`/api/v1/`)

| Path | Metode | Auth | Keterangan |
|------|--------|------|------------|
| `/api/v1/health.php` | GET | Umum (tanpa login) | readiness DB, storage, ringkasan cron backup — dipakai smoke/monitoring |

---

## 2. Partner / H2H (`/api/v1/partner/`)

| Path | Metode | Auth | Keterangan |
|------|--------|------|------------|
| `/api/v1/partner/health.php` | GET | **X-API-Key** atau `Authorization: Bearer <key>` | Validasi key mitra |
| `/api/v1/partner/order_create.php` | POST | Sama + scope opsional `order:create` | Body JSON PO/H2H — lihat `docs/PERSIAPAN_H2H_PO_HERMINA.md` |

Detail request/response mitra: **`docs/governance/OPENAPI_PARTNER.yaml`** + **`docs/api/postman_partner_h2h_collection.json`**.

---

## 3. Mobile (`/api/v1/mobile/`)

**Auth:** `POST /auth/login` & `refresh` tanpa Bearer; selain itu **`Authorization: Bearer <JWT>`**.

**Header umum:**
- **`X-Request-Id`** — wajib/disarankan (divalidasi di `_router.php`).
- **`X-Idempotency-Key`** — wajib pada **POST mutasi** (lihat router).

**URL:** setiap fitur = file PHP di bawah folder (mis. `.../mobile/sales/do_list.php`). Isi file mem-set `$MOBILE_ENDPOINT` lalu `require _router.php`.

**Spesifikasi OpenAPI:** `docs/governance/OPENAPI_MOBILE.yaml` (path relatif ke `/api/v1/mobile`, tanpa `.php`).

### 3.1 Auth & umum

| Area | File / endpoint logis | Metode |
|------|------------------------|--------|
| Login | `auth/login.php` | POST |
| Refresh | `auth/refresh.php` | POST |
| Logout | `auth/logout.php` | POST |
| Profil | `auth/me.php` | GET |
| Dashboard | `dashboard/get.php` | GET |
| Tasks | `tasks/my.php` | GET |

### 3.2 Sales / Purchases / Stock

| Grup | File (relatif `mobile/`) | Metode utama |
|------|---------------------------|--------------|
| Sales DO | `sales/do_list.php`, `do_detail.php` | GET |
| Sales DO aksi | `sales/do_action.php` | POST + idempotency |
| Purchases | `purchases/pr_list`, `pr_create`, `po_list`, `po_create`, `po_detail`, `ap_invoice_*`, `ap_payment_*` | GET / POST sesuai nama |
| Stock | `stock/items`, `incoming_list`, `incoming_receive`, `adjustment_create`, `adjustment_approve`, `audit` | GET / POST |

### 3.3 Stock opname (file ada — **router v1 belum lengkap**)

File entry berikut **ada** di repo dan mem-set `$MOBILE_ENDPOINT`, namun **`api/v1/mobile/_router.php` saat ini tidak memuat handler `stock/opname_*`** (akan berujung `ERR_NOT_FOUND` sampai logika disalin dari `api/mobile/_router.php` atau setara):

- `stock/opname_list.php`, `opname_detail.php`, `opname_create.php`, `opname_input_qty.php`, `opname_apply.php`

**Lihat:** `api/mobile/_router.php` (branch `stock/opname_*`) sebagai referensi implementasi legacy.

### 3.4 Chat & notifikasi (mobile)

| File | Metode |
|------|--------|
| `chat/channels.php`, `messages.php`, `send.php`, `read.php`, `mark_all_read.php`, `mute.php`, `unmute.php` | GET / POST |
| `chat/presence_ping.php`, `presence_get.php`, `search.php` | POST / GET |
| `chat/attachment_upload.php` | POST (multipart) |
| `chat/attachment_preview.php`, `attachment_download.php` | GET |
| `notifications/register_token.php`, `mark_read.php` | POST |
| `notifications/list.php` | GET |

### 3.5 Master read (policy)

`master/products.php`, `vendors.php`, `customers.php` — di router v1 mengembalikan **403** (“disabled by policy”) kecuali kebijakan diubah di kode.

---

## 4. Internal (sesi browser / JSON) — `/api/v1/internal/*.php`

**Auth:** **Cookie sesi** setelah login web (`require_login()`). Banyak response memakai `App\Api\ApiResponse` (`success`, `data`, `error`, `meta.request_id`).

**POST mutasi:** beberapa memanggil `internal_api_require_write_guard()` → **CSRF** (sama seperti form web — kirim token CSRF).

| Path | Metode | Gate utama |
|------|--------|------------|
| `crm_leads_summary.php` | GET | `require_role` CRM, MPR, FIN, ACT, MANAGER, SYS, ADMIN, SUPERADMIN |
| `crm_leads_listing.php` | GET | sama |
| `crm_lead_detail.php` | GET | sama |
| `crm_lead_action.php` | POST | sama + **CSRF** |
| `gl_summary.php`, `gl_ledger.php`, `gl_journal_listing.php`, `gl_trial_balance.php` | GET | FIN, SYS, ADMIN, SUPERADMIN, ACT, MANAGER |
| `gl_enqueue_posting.php` | POST | FIN, ADMIN, SUPERADMIN, SYS, ACT, MANAGER + **CSRF** + rate limit |
| `bank_recon_summary.php` | GET | FIN, SYS, ADMIN, SUPERADMIN, ACT, MANAGER |
| `tax_invoice_status.php` | GET | ACT, FIN, SYS, ADMIN, SUPERADMIN, MANAGER |
| `funnels_summary.php` | GET | `require_any_permission` DASHBOARD.* atau fallback role |
| `sales_tracking_sync.php` | POST | SCM… (+ segmen kedua CRM…) + **CSRF** + rate limit |
| `sales_scm_geo_ping.php` | POST | SCM, SYS, … + **CSRF** |

**Catatan:** `api/internal/compliance/reg_alkes_summary.php` ada di **`api/internal/`** (bukan di bawah `v1/internal/` di tree saat ini); mirror path perlu dicek saat integrasi.

---

## 5. Chat web — `/api/v1/chat/*.php`

**Auth:** Cookie sesi + **`CHAT.VIEW`** (`_chat_bootstrap.php`). Mutasi POST memakai **`verify_csrf()`** (via helper/controller).

Entry utama banyak memakai **`App\Controllers\Api\V1\ChatApiController`** atau include ke file lain (mis. `channel_messages.php` → `messages.php`, `read_mark.php` → `read.php`).

**Daftar file (audit cepat):**  
`acl.php`, `attachment_download.php`, `attachment_preview.php`, `attachment_upload.php`, `channel_messages.php`, `channel_retention.php`, `channels.php`, `channels_list.php`, `channels_read.php`, `emojis.php`, `events.php`, `export_create.php`, `export_download.php`, `exports.php`, `mark_all_read.php`, `message_context.php`, `message_delete.php`, `message_send.php`, `messages.php`, `messages/context.php`, `mute.php`, `pin.php`, `pin_policy.php`, `pins.php`, `prefs.php`, `presence.php`, `presence_get.php`, `presence_ping.php`, `presence_ping` (folder ping), `reaction.php`, `read.php`, `read_mark.php`, `search.php`, `thread.php`, `typing.php`, `unmute.php`, `users.php`, plus `_bootstrap.php` / `_chat_bootstrap.php`.

Untuk metode tepat per path, buka file terkait (biasanya `$method === 'GET'|'POST'` atau controller).

---

## 6. Webhooks — `/api/webhooks/`

| Path | Keterangan |
|------|------------|
| `payment_callback.php` | Callback pembayaran (validasi sesuai implementasi file) |
| `marketplace_order.php` | Order marketplace |

---

## 7. Lain-lain (di luar `v1` standar)

| Path | Keterangan |
|------|------------|
| `api/kpi_exec.json.php` | KPI JSON |
| `absensi/admin/api/rekap_dt.php` | Absensi admin (DataTables/API) |

---

## 8. Duplikat path tanpa `/v1/`

Folder **`api/mobile/`**, **`api/internal/`**, **`api/chat/`** sering berisi mirror dari versi `api/v1/...`. **Disarankan** klien baru hanya memakai **`/api/v1/...`** — lihat `docs/governance/API_DEPRECATION_NON_V1.md`.
