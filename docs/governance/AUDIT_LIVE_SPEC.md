# Live Audit — Real Data + Real-Time Spec

**Project:** ERP_RMI_SOFULL  
**State Version:** audit_live_v1  
**APP_ROOT:** /volume4/web/ERP_RMI_SOFULL

---

## 1. Overview

Live Audit validates:
- Real data integrity (PR→PO→GR→AP, SO→DO→Stock, Opname)
- Ops toolchain health (smoke_http, contract_check, cutover readiness)
- RBAC boundaries (guest/staff/admin)
- Unicode/Cyrillic link consistency + /Volumes path policing
- Produces 1-page Executive Summary for management

---

## 2. Files

| File | Purpose |
|------|---------|
| `tools/qa/audit_live.php` | CLI runner |
| `tools/qa/audit_live_web.php` | Web viewer + Run Now |
| `tools/qa/_shared/audit_live_lib.php` | Library functions |
| `tools/qa/_shared/audit_schema_detect.php` | Schema/table detection |
| `tools/qa/audit_live_policy.json` | Thresholds & windows |
| `tools/qa/http_endpoints_audit_live.json` | Endpoint list |
| `tools/ops/build_audit_exec_summary.php` | Build 1-page HTML/MD |
| `tools/ops/audit_exec_summary.php` | Web viewer for exec summary |
| `tools/cron/audit_live_cron.php` | Cron entry runner |

---

## 3. CLI Usage

```bash
cd /volume4/web/ERP_RMI_SOFULL

# Manual run
php tools/qa/audit_live.php --env=production --write-last --strict

# Cron mode (same, with --mode=cron)
php tools/qa/audit_live.php --env=production --mode=cron --write-last --strict
```

---

## 4. Cron Schedule

Add to crontab (run every 15 minutes):

```
*/15 * * * * php /volume4/web/ERP_RMI_SOFULL/tools/cron/audit_live_cron.php
```

Or via Synology Task Scheduler: create task, run script, schedule every 15 min.

---

## 5. Output Artifacts

| Artifact | Location |
|----------|----------|
| audit_live_last.json | storage/logs/ |
| audit_live_history.jsonl | storage/logs/ |
| audit_exec_summary_last.html | storage/logs/ |
| audit_exec_summary_last.md | storage/logs/ |
| unicode_guard_last.json | storage/logs/ |
| audit_live_assumptions_last.md | storage/logs/ (if assumptions) |

---

## 6. Gate PASS Rules (Strict Window)

PASS if:
- critical_fail_count == 0
- smoke_http strict fail == 0 (both LAN + PUBLIC)
- contract_check ok == true (both)
- unicode_guard ok == true
- /Volumes path detected == 0
- office missing == 0 (strict window)
- depo missing for stock-touching docs == 0
- RBAC: guest tools blocked, staff admin blocked
- sales_tracking_checks ok == true
- finance_schema_guard ok == true

Legacy anomalies (outside strict window) => WARN only.

---

## 7. Base URLs

- **LAN:** http://10.10.60.20/ERP_RMI_SOFULL
- **PUBLIC:** https://erp.rizqullahmediska.com/ERP_RMI_SOFULL

---

## 8. Referensi

- `docs/governance/INDEX.md`
- `tools/index.php` — pinned links
