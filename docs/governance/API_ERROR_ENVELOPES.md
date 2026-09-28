# Format respons & error API — ringkasan per kelompok

Dokumen ini **mendeskripsikan keadaan saat ini** (bukan saran mengubah kode). Menyatukan envelope di semua endpoint membutuhkan refactor dan kontrak dengan klien.

## 1. Mobile (`/api/v1/mobile/*`)

**Sukses / error (JSON):**

- `ok` (bool)
- `message` (string)
- `data` (object/array, opsional pada error)
- `request_id` (string)
- Error: `code` (string, mis. `ERR_VALIDATION`), opsional `details`

Didefinisikan di `docs/governance/OPENAPI_MOBILE.yaml` (`SuccessEnvelope` / `ErrorEnvelope`).

## 2. Internal JSON (`App\Api\ApiResponse`)

Digunakan banyak endpoint di **`/api/v1/internal/*.php`**:

```json
{
  "success": true,
  "data": { },
  "error": null,
  "meta": { "request_id": "...", "ts": "ISO-8601" }
}
```

Error:

```json
{
  "success": false,
  "data": null,
  "error": { "code": "ERR_GENERIC", "message": "..." },
  "meta": { "request_id": "...", "ts": "..." }
}
```

## 3. Partner (`/api/v1/partner/*`)

Polanya **dekat mobile** untuk error sederhana:

- `ok`, `code`, `message` (tanpa `request_id` di beberapa respons)

Lihat `OPENAPI_PARTNER.yaml` untuk contoh.

## 4. Health (`/api/v1/health.php`)

Struktur khusus readiness (score, checks, storage, cron) — bukan envelope mobile.

## 5. Chat web (`/api/v1/chat/*`)

Campuran **controller** (`ChatApiController`) dan response langsung; umumnya JSON dengan field yang bervariasi. Gate: session + `CHAT.VIEW`; error HTTP + JSON sesuai implementasi.

## Rekomendasi integrasi

- **Klien mobile:** patokan **`OPENAPI_MOBILE`** + header `X-Request-Id` / idempotency.
- **Klien internal/script:** patokan **`ApiResponse`** + CSRF untuk POST.
- **Mitra H2H:** patokan **`OPENAPI_PARTNER`**.

Penyatuan penuh ke satu skema global = **P2+** (breaking change risk).
