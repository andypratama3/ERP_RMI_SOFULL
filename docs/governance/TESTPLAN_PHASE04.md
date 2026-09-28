# Test Plan — Phase 4 (BI & Advanced Dashboards)

**Date:** 2026-02-25  
**Project:** ERP_RMI_SOFULL

## F1) PHP Lint

```bash
php -l dashboards/warehouse/wqs_dashboard.php
php -l dashboards/warehouse/wqs_dashboard_export.php
php -l _shared/export_excel.php
php -l tools/cron/scheduled_reports.php
```

Expected: 0 error

## F2) Manual Dashboard

- [ ] Open `dashboards/warehouse/wqs_dashboard.php`
- [ ] No blank page
- [ ] Charts render (or show "Tidak ada data")
- [ ] Date range filter works (7/30/90/custom)
- [ ] KPI cards clickable → drill-down

## F3) Export CSV

- [ ] Click "Export CSV" on dashboard
- [ ] CSV downloads
- [ ] Check audit: REPORT_EXPORTED in audit log

## F4) Scheduled Reports

```bash
php tools/cron/scheduled_reports.php
```

- [ ] Exit code 0
- [ ] `storage/exports/reports/report_daily_YYYYMMDD.md` exists
- [ ] `storage/exports/reports/report_weekly_YYYYWW.md` exists
- [ ] `storage/logs/scheduled_reports_last.json` updated

## F5) Smoke & Contract

- [ ] smoke_http strict PASS
- [ ] contract_check strict PASS

## Gate Pass

- [ ] Dashboard mobile/desktop OK
- [ ] Chart load OK (no console fatal)
- [ ] Export works & audited
- [ ] Scheduled report runs
