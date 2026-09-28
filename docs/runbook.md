# Runbook — Operasi Harian & Incident

**Lokasi lengkap:** [docs/runbook/RUNBOOK.md](runbook/RUNBOOK.md)

---

Ringkasan:

- **Base:** `/volume4/web/ERP_RMI_SOFULL` (NAS)
- **Operasi harian:** backup cron, cutover gate, smoke, evaluate_alerts
- **Incident:** health check, DB error, smoke fail, path violation, backup/restore, RBAC 403
- **Cron examples:** backup 23:00, alerts setiap 6 jam, ops snapshot harian

```bash
cd /volume4/web/ERP_RMI_SOFULL
./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --strict --write-last
```
