# ALERT POLICY — Monitoring & Alerting

Kebijakan alert untuk ERP_RMI_SOFULL. Sumber: `tools/ops/evaluate_alerts.php`, artifact `storage/logs/alerts_last.json`.

---

## 1. Severity Levels

| Level | Arti | SLA Notify |
|-------|------|------------|
| **CRITICAL** | Blocker, harus ditindak segera | 1 jam |
| **HIGH** | Penting, tindak dalam 6 jam | 6 jam |
| **MEDIUM** | Perlu perhatian | 24 jam |
| **LOW** | Informasi, trend | 24 jam |

---

## 2. Alert Codes

| Code | Severity | Kondisi | Tindakan |
|------|----------|---------|----------|
| `contract_fail` | CRITICAL | contract_ok=false | Cek API contract, contract_check_last.json |
| `smoke_fail` | CRITICAL | smoke_fail > 0 | Cek smoke_http_last.json, URL yang fail |
| `backup_stale_72h` | CRITICAL | Backup > 72 jam | Jalankan backup_now.sh, cek cron |
| `all_checks_fail` | CRITICAL | overall_ok=false | Cek run_cutover_checks, cutover_checks.last.json |
| `readiness_below_100` | HIGH | readiness_score < 100 | Cek readiness_report_last.json |
| `backup_stale_24h` | HIGH | Backup 24–72 jam | Cek backup cron, storage/backups |
| `ops_artifact_integrity` | MEDIUM | readiness/smoke invalid | Cek artifact JSON valid |
| `readiness_3day_downtrend` | LOW | Trend turun 3 hari | Review ops snapshot |

---

## 3. Metrics Sumber

- **Error log rate** — `storage/logs/error.log`
- **Job failures** — `storage/logs/tools_run_history.jsonl`
- **Last backup status** — `storage/backups/LATEST_BACKUP.txt`, `backup_restore_gate_last.json`
- **Last cutover status** — `storage/logs/cutover_checks.last.json`
- **Readiness** — `storage/logs/readiness_report_last.json`
- **Smoke** — `storage/logs/smoke_http_last.json`

---

## 4. Artifact

| File | Isi |
|------|-----|
| `storage/logs/alerts_last.json` | Alert payload (state_version, ts, alerts, active_counts) |
| `storage/logs/ops_alerts_last.json` | Alias, sama |
| `storage/logs/ops_alert_throttle.json` | Throttle notify per code |
| `storage/logs/ops_findings.json` | Findings auto-opened dari alert |

---

## 5. Cron

```cron
# Evaluate alerts setiap 6 jam
0 */6 * * * cd /volume4/web/ERP_RMI_SOFULL && php tools/ops/evaluate_alerts.php >> storage/logs/evaluate_alerts.log 2>&1
```

---

## 6. Health Endpoint

```
GET /api/v1/health.php
```

**PASS:** `success=true`, `data.db.ok=true`, `data.storage` OK, `meta.request_id` ada.

---

## 7. Escalation

- **CRITICAL:** Notify OPS/SYS segera. Cek runbook incident.
- **HIGH:** Tindak dalam shift. Log di ops_findings.
- **MEDIUM/LOW:** Review harian/mingguan.

---

*Update: Final Hardening Execution.*
