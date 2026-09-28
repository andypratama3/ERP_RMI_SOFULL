# API v1 Enhancement & Caching Spec

## Tujuan

- Non-breaking enhancement untuk Mobile API v1
- File-based caching untuk endpoint read-heavy (tanpa dependency eksternal)
- Security headers konsisten
- Cache metadata via header (debug only), tidak mengubah JSON contract

## Non-Breaking Rules

- Tidak rename field response (ok, message, data, request_id)
- Hanya tambah optional field/header
- Semua mutasi POST: auth + RBAC + CSRF (session web)
- Target ENV: STAGING atau LOCAL (production: API_CACHE_ENABLED=0 default)

## Response Headers (Semua Endpoint)

| Header | Nilai | Wajib |
|--------|-------|-------|
| X-Request-Id | request_id | Ya |
| X-Content-Type-Options | nosniff | Ya |
| Cache-Control | no-store | Ya (default) |
| X-Cache | HIT \| MISS \| BYPASS | Optional (debug) |
| X-Cache-TTL | seconds | Optional (debug) |
| X-Cache-Key | short hash | Optional (debug) |

## Cache Scope & Key

- Cache key WAJIB include scope user: `user_id:role:office_code`
- Plus: endpoint path + query string normalized
- Hanya GET, hanya response ok=true
- TTL: 30–60s (dashboard), 30s (stock items)

## Cache Storage Layout

- Dir: `storage/cache/api/` (atau API_CACHE_DIR)
- Format file: `api_<hash>.json`
- Isi: `{ state_version, key, created_at, ttl, expires_at, tags[], payload, meta }`
- Path masking: tidak bocor absolute path di output tools

## Failure Modes & Safe Fallback

- Cache corrupt → treat as MISS, log warning (masked)
- API_CACHE_ENABLED=0 → BYPASS, endpoint tetap 200
- Dir tidak writable → BYPASS
- Tidak ada stacktrace di production

## Env Flags

| Env | Default | Deskripsi |
|-----|---------|-----------|
| API_CACHE_ENABLED | 0 | 1=enable, 0=disable |
| API_CACHE_DRIVER | file | (reserved) |
| API_CACHE_DIR | storage/cache/api | Override path |
| API_CACHE_DEFAULT_TTL | 60 | Default TTL detik |
