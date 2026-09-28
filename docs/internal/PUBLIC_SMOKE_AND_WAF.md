# Public URL smoke vs WAF / Cloudflare

## Gejala

- `tools/smoke_http.php`: **internal** `fail=0`, **public** banyak **403** (login, health, dashboard).
- `php tools/qa/smoke_public_probe.php --write-last` → `storage/logs/smoke_public_probe_last.json` menunjukkan public **403** sementara internal **200/302**.

## Penyebab umum

Bukan RBAC PHP: **reverse proxy / Cloudflare / Bot Fight** memblokir request **curl** dari server NAS sebelum sampai ke PHP.

## Perbaikan infra (target: public `fail=0` tanpa skip)

1. Cloudflare / WAF: **whitelist IP egress** server yang menjalankan smoke (NAS).
2. Atur rule agar path `/ERP_RMI_SOFULL/*` tidak di-challenge untuk health/login dari IP terpilih.
3. Pastikan vhost domain publik **document root / proxy** ke app yang sama dengan internal.

## Opsi sementara (gate)

- `SMOKE_AUTO_SKIP_PUBLIC_EDGE=1` — smoke melewati suite publik penuh jika probe login gagal (lihat `tools/smoke_http.php`).
- `SMOKE_SKIP_PUBLIC=1` — tidak menjalankan layer public sama sekali.

## Referensi

- `docs/internal/RBAC_POLICY.md` — model akses URL vs Nav.
