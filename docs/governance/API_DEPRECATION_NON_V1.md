# Kebijakan deprecation — path API tanpa `/v1/`

**Status:** kebijakan dokumentasi. Menghapus atau mengalihkan URL lama adalah **langkah deploy terpisah** dan harus melalui QA/smoke.

## Prinsip

1. **Kanonik untuk klien baru:** gunakan prefix **`/api/v1/`** (mobile, internal, partner, chat) kecuali webhook/khusus yang memang tidak memakai pola itu.
2. **Path legacy** di bawah:
   - `api/mobile/*` (tanpa `v1`)
   - `api/internal/*`
   - `api/chat/*`  
   dianggap **mirror / salinan deploy** dari versi `v1`. Duplikasi membingungkan mitra dan mobile team.

## Yang dilakukan sekarang (tanpa mengubah server)

- Semua indeks & OpenAPI mengarah ke **`docs/API_INDEX.md`** dan file di `api/v1/**`.
- Komentar di README/runbook disesuaikan bertahap ke URL `v1`.

## Rencana deprecation (opsional, saat tim siap)

1. **Fase 0:** Inventaris traffic (log akses) ke path non-`v1`.
2. **Fase 1:** Dokumentasi & Postman hanya memuat `v1`.
3. **Fase 2:** HTTP **301/308** dari path lama ke `v1` (hati-hati: query string, POST body tidak selalu aman di-redirect).
4. **Fase 3:** Hapus file duplikat **hanya** jika tidak ada klien aktif.

## Pengecualian

- **`/api/webhooks/*`** — tidak wajib `v1` (kontrak dengan penyedia eksternal).
- **`api/kpi_exec.json.php`**, **`absensi/admin/api/*`** — evaluasi per modul; bisa tetap atau dinomori ulang dalam rilis terpisah.
