# Runbook — Deployment, Config, Troubleshooting, Rollback

**Lokasi lengkap:** [RUNBOOK.md](RUNBOOK.md)

---

## Deployment

- **Base:** `/volume4/web/ERP_RMI_SOFULL` (NAS)
- **Wajib:** Deploy dari NAS. Path `/Volumes/` DILARANG di tools/logs/config.
- **Pre-deploy:** `./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --strict --write-last` → `overall_ok=true`
- **Config:** `.env` (DB, APP_URL, TOOLS_BASE_URL_INTERNAL)

## Rollback

1. Restore dari backup: `tools/restore_now.sh --from <package> --apply --restore-db --restore-files`
2. Verifikasi: `tools/qa/backup_restore_gate.php --write-last --strict`
3. Smoke: `tools/smoke_http.php --strict --write-last`

## Troubleshooting

- **Health:** `curl /api/v1/health.php`
- **Logs:** `storage/logs/`
- **Audit:** `master/audit_logs.php`
- **Incident:** Lihat [RUNBOOK.md](RUNBOOK.md) § 2. Incident Response
