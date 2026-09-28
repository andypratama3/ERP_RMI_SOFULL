# PATCH_DIFF_UNICODE — Cyrillic Purge

**Generated:** 2026-03-03

## Ringkasan Perubahan

### File Baru
- `tools/qa/unicode_guard.php` — Scanner Cyrillic (path + content)
- `tools/qa/unicode_guard_web.php` — Viewer hasil scan (ADMIN/SUPERADMIN)
- `docs/governance/UNICODE_POLICY.md` — Kebijakan ASCII-only
- `docs/governance/UNICODE_FIX_REPORT.md` — Laporan fix
- `storage/logs/manual_action_queue_last.json` — Queue manual (kosong)
- `storage/logs/unicode_guard_last.json` — Hasil scan terakhir

### File Diubah
- `tools/qa/run_cutover_checks.php` — Tambah step `unicode_guard` (pertama, sebelum preflight)

### Diff run_cutover_checks.php

```diff
 $steps = [
+    ['name' => 'unicode_guard', 'cmd' => escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/unicode_guard.php') . ' --scan --strict --write-last', 'artifact' => ts_storage_logs_dir() . '/unicode_guard_last.json'],
     ['name' => 'preflight', ...
```

## Hasil Scan

- path_cyrillic: 0
- content_cyrillic_path_ctx: 0
- content_cyrillic_general: 0
- **ok: true** — Project bersih dari Cyrillic

## Gate Status

| Step | Status | Catatan |
|------|--------|---------|
| unicode_guard | PASS | 33s |
| preflight | PASS | |
| smoke_http | FAIL | Network: Could not connect to 10.10.60.20 (env) |
| contract_check | FAIL | Network unreachable (env) |
| sales_tracking_checks | FAIL | DB Connection refused (env) |
| finance_schema_guard | FAIL | DB Connection refused (env) |

**Catatan:** smoke_http, contract_check, dan DB checks membutuhkan lingkungan NAS dengan koneksi ke 10.10.60.20 dan database. Jalankan `run_cutover_checks.php` di NAS untuk validasi lengkap.
