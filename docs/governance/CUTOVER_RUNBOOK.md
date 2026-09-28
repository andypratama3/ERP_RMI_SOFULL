# Cutover Runbook — ERP_RMI_SOFULL

## STEP 0 — Backup (WAJIB sebelum perubahan)

```bash
cd /volume4/web/ERP_RMI_SOFULL
bash tools/backup_now.sh --label pre_tools_fix
```

Atau via PHP:
```bash
php tools/ops/backup_now.php --label pre_tools_fix
```

Verifikasi: `storage/backups/ERP_RMI_SOFULL_backup_*_pre_tools_fix/` dengan manifest.json + checksums.sha256.

---

## STEP 1 — Lock APP_ROOT

```bash
cd /volume4/web/ERP_RMI_SOFULL
php tools/dev/lock_app_root.php
```

Verifikasi: Tools → Assumptions Log tidak ada APP_ROOT_MISMATCH.

---

## STEP 2 — Config BASE URL (.env)

```env
TOOLS_BASE_URL_INTERNAL=http://10.10.60.20/ERP_RMI_SOFULL
TOOLS_BASE_URL=http://10.10.60.20/ERP_RMI_SOFULL
APP_BASE_URL=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL
APP_PUBLIC_URL=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL
ERP_PHP_BIN=/usr/local/bin/php84
```

---

## STEP 3 — Run Cutover Checks

```bash
cd /volume4/web/ERP_RMI_SOFULL
export ERP_PHP_BIN=/usr/local/bin/php84
export TOOLS_BASE_URL_INTERNAL=http://10.10.60.20/ERP_RMI_SOFULL
$ERP_PHP_BIN tools/qa/run_cutover_checks.php --write-last --strict --allow-sales-tracking-data
```

Target: `overall_ok=true` di `storage/logs/cutover_checks.last.json`.

---

## Evidence Pack

- `storage/logs/smoke_http_last.json` (fail=0)
- `storage/logs/cutover_checks.last.json` (overall_ok=true)
- `storage/logs/contract_check_last.json` (ok=true)
- `storage/logs/tools_dashboard_smoke.last.json` (ok=true)
- `storage/logs/sales_tracking_checks.last.json` (ok=true)
- `storage/logs/readiness_report_last.json` (score=100 jika ada)
- `storage/logs/tools_run_history.jsonl` (bertambah 1 run)

---

## Catatan

- **lock_app_root --dev**: Untuk dev lokal (Mac), jalankan `php tools/dev/lock_app_root.php --dev`.
- **Cutover di NAS**: Pastikan TOOLS_BASE_URL_INTERNAL dan ERP_PHP_BIN di .env. NAS harus reachable (10.10.60.20).
