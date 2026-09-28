# RUNBOOK OPS — ERP_RMI_SOFULL

> **Lokasi lengkap:** [`docs/runbook/RUNBOOK.md`](runbook/RUNBOOK.md)  
> **Base (WAJIB):** `/volume4/web/ERP_RMI_SOFULL`  
> **CRITICAL:** DILARANG jalankan dari `/Volumes/` path.

---

## GO / NO-GO (Pre-Deploy)

```bash
cd /volume4/web/ERP_RMI_SOFULL || exit 1
# 1. Path guard
grep -R "/Volumes/" tools/ storage/logs/ 2>/dev/null && { echo "CRITICAL FAIL: /Volumes detected"; exit 3; } || echo "OK: No /Volumes"
# 2. Cutover gate
./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --strict --write-last
# PASS: overall_ok=true in storage/logs/cutover_checks.last.json
```

---

## Backup / Restore

```bash
# Backup now
./tools/backup_now.sh --label manual
php tools/ops/backup_now_cli.php --write-last      # artifact: storage/logs/backup_now_last.json

# Verify
php tools/ops/backup_verify_cli.php --write-last   # artifact: storage/logs/backup_verify_last.json

# Restore dry-run (non-destructive)
php tools/ops/restore_dry_run.php --from-latest --write-last
# artifact: storage/logs/restore_dry_run_last.json
```

---

## Smoke + Monitoring

```bash
./tools/nas/erp.sh php tools/smoke_http.php --strict --write-last
php tools/ops/build_ops_metrics.php --write-last   # artifact: storage/logs/ops_metrics_last.json
php tools/ops/evaluate_alerts.php                  # artifact: storage/logs/alerts_last.json
```

---

## Incident Response

| Gejala | Periksa | Tindakan |
|--------|---------|----------|
| 403 di menu | `menu_rbac_sync_last.json` mismatch_count | Perbaiki RBAC policy |
| Smoke fail | `smoke_http_last.json` fail_count | Cek error log, restart service |
| Backup stale | `alerts_last.json` backup_stale | Jalankan backup manual |
| Cutover FAIL | `cutover_checks.last.json` steps | Ikuti pesan error per step |

---

## Rollback

```bash
# List backups
ls storage/backups/
# Dry-run verify
php tools/ops/restore_dry_run.php --from-latest --write-last
# Apply (HATI-HATI, di production setelah konfirmasi)
# ./tools/restore_now.sh --from storage/backups/<tanggal> --restore-db --restore-files
```

---

## Referensi

- [`docs/runbook/RUNBOOK.md`](runbook/RUNBOOK.md) — Runbook lengkap
- [`docs/ops/FINAL_GATE_CHECKLIST.md`](ops/FINAL_GATE_CHECKLIST.md) — Checklist go/no-go
- [`docs/governance/RBAC_CHANGE_RULES.md`](governance/RBAC_CHANGE_RULES.md) — Aturan perubahan RBAC
