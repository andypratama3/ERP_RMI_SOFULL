# Cyrillic Ban Policy

## Goal

Hilangkan/larang huruf Cyrillic di path/route/code agar link tidak inkonsisten (homograph).

## Scan

```bash
php tools/dev/scan_cyrillic.php
```

Output: `storage/logs/cyrillic_scan_last.json`

- **found_paths[]** — nama file/folder mengandung Cyrillic
- **found_contents[]** — isi file (PHP/HTML/JS/CSS) mengandung Cyrillic
- **has_critical** — true jika ada di path/route yang publicly reachable

## Severity

- **CRITICAL:** Cyrillic di nama file/folder yang jadi route web (api/, master/, sales/, dll)
- **WARN:** Cyrillic di komentar atau konten non-route

## Fix Policy (Safe-by-Default)

- **DEFAULT:** Jangan auto-rename tanpa bukti.
- Jika ditemukan di NAMA FILE/FOLDER:
  - Buat rencana rename (ASCII only) + mapping: `docs/governance/CYRILLIC_RENAME_MAP.md`
  - Rename hanya jika semua reference bisa diupdate deterministik
  - Evidence: smoke_http strict PASS setelah fix
- Jika tidak bisa menjamin → FAIL + mapping only (no change)

## Gate

- `contract_check.php` menjalankan `scan_cyrillic.php` dan FAIL jika `has_critical=true`
- Whitelist: Cyrillic boleh di konten text business (nama customer), bukan di path/route/code symbol
