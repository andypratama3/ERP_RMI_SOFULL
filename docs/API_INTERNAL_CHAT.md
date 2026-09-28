# API Internal & Chat (referensi cepat)

Indeks lengkap: **`docs/API_INDEX.md`**. File ini fokus **tabel path / metode / gate** untuk `/api/v1/internal/*.php` dan `/api/v1/chat/*.php`.

---

## Internal (`/api/v1/internal/`)

**Transport:** biasanya **cookie sesi** (browser login). **Response:** banyak memakai `App\Api\ApiResponse` (`success`, `data`, `error`, `meta.request_id`).

**POST dengan `internal_api_require_write_guard()`:** kirim **CSRF** seperti form web.

| Path | Metode | Auth / permission (ringkas) |
|------|--------|-------------------------------|
| `crm_leads_summary.php` | GET | `require_login` + role CRM, MPR, FIN, ACT, MANAGER, SYS, ADMIN, SUPERADMIN |
| `crm_leads_listing.php` | GET | sama |
| `crm_lead_detail.php` | GET | sama |
| `crm_lead_action.php` | POST | sama + **CSRF** |
| `gl_summary.php` | GET | FIN, SYS, ADMIN, SUPERADMIN, ACT, MANAGER |
| `gl_ledger.php` | GET | sama |
| `gl_journal_listing.php` | GET | sama |
| `gl_trial_balance.php` | GET | sama |
| `gl_enqueue_posting.php` | POST | sama + **CSRF** + rate limit |
| `bank_recon_summary.php` | GET | FIN, SYS, ADMIN, SUPERADMIN, ACT, MANAGER |
| `tax_invoice_status.php` | GET | ACT, FIN, SYS, ADMIN, SUPERADMIN, MANAGER |
| `funnels_summary.php` | GET | `require_any_permission` DASHBOARD.* atau fallback role |
| `sales_tracking_sync.php` | POST | role SCM (dan segmen kedua CRM) + **CSRF** + rate limit |
| `sales_scm_geo_ping.php` | POST | SCM, SYS, … + **CSRF** |

Bootstrap CSRF: `api/v1/internal/_internal_api_bootstrap.php` (disalin dari `api/internal/` agar `require_once` di file v1 tidak fatal).

---

## Chat web (`/api/v1/chat/`)

**Entry umum:** `ChatApiController` — di constructor **`require_login()`** (lihat `app/Controllers/Api/V1/ChatApiController.php`). **POST** mutasi memakai **`require_post()` + `verify_csrf()`** di controller.

**Permission `CHAT.VIEW`:** didefinisikan di `api/v1/chat/_chat_bootstrap.php`; banyak endpoint **tidak** memuat file itu dan hanya lewat controller — **gate tambahan bisa lewat route policy** (`_shared/rbac_policy.php` untuk `/api/*`). Verifikasi di environment Anda jika 403.

**Format respons:** umumnya **`ApiResponse`** (sama seperti internal).

| Path | Metode (umum) | Catatan |
|------|----------------|---------|
| `channels.php` | GET, POST | POST = join / DM / create channel (lihat controller) |
| `channels_list.php` | → `channels.php` | alias |
| `messages.php` | GET, POST | |
| `channel_messages.php` | → `messages.php` | alias |
| `message_send.php` | POST | |
| `read.php` | POST | |
| `read_mark.php` | → `read.php` | alias |
| `mark_all_read.php` | POST | |
| `mute.php`, `unmute.php` | GET/POST | cek file untuk cabang metode |
| `presence.php`, `typing.php`, `prefs.php`, `acl.php` | GET/POST | |
| `presence_get.php`, `presence_ping.php`, `presence/ping.php` | bervariasi | |
| `search.php` | GET/POST | |
| `attachment_upload.php` | POST | multipart |
| `attachment_download.php`, `attachment_preview.php` | GET | |
| `attachments/download.php`, `attachments/preview.php` | | subpath |
| `users.php` | GET | |
| `message_delete.php` | POST | |
| `message_context.php`, `messages/context.php` | | konteks pesan |
| `reaction.php`, `emojis.php` | POST/GET | |
| `pin.php`, `pins.php`, `pin_policy.php` | | |
| `thread.php` | | |
| `exports.php`, `export_create.php`, `export_download.php`, `exports/download.php` | | ekspor chat |
| `events.php` | | |
| `channel_retention.php` | | |

Untuk detail parameter, buka **file PHP** atau **`ChatApiController`**.

---

## Lihat juga

- `docs/governance/API_ERROR_ENVELOPES.md` — bentuk JSON sukses/gagal.
- `docs/governance/API_DEPRECATION_NON_V1.md` — path tanpa `v1`.
