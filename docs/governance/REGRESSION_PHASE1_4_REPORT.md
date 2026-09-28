# Regression Phase 1–4 Report

**Source:** `storage/logs/regression_phase1_4_last.json`

## Overview

The regression runner executes checks in order with **STOP-ON-FAIL**. Full report is always written.

## Check Order

| Phase | Check | Script |
|-------|-------|--------|
| A | preflight_check | tools/preflight_check.php |
| B | api_health_json | api/v1/health.php |
| C | contract_check | tools/qa/contract_check.php --strict |
| D | smoke_http | tools/smoke_http.php --strict |
| E | tools_dashboard_smoke | tools/qa/tools_dashboard_smoke.php |
| F | uat_smoke | tools/uat_smoke.php |
| G | smoke_mobile_stock_opname | tools/qa/smoke_mobile_stock_opname.php |
| G | smoke_pwa | tools/qa/smoke_pwa.php |
| G | smoke_compliance_integrations | tools/qa/smoke_compliance_integrations.php |

## Run Command

```bash
# With web server + DB
APP_BASE_URL=http://127.0.0.1 php tools/qa/run_regression_phase1_4.php

# CLI only (A–C may pass; D+ may fail without web)
php tools/qa/run_regression_phase1_4.php
```

## Output Schema

```json
{
  "state_version": 1,
  "env": "local",
  "overall_ok": false,
  "fail_count": 1,
  "checks": [...],
  "blocked": [],
  "request_id": "reg-...",
  "generated_at": "2026-03-01T..."
}
```

## Blocked Scripts

If a script does not exist, it is recorded in `blocked` and does not affect `fail_count`.
