# Test Plan - Phase 4

## Chart.js
- [ ] rmi_assets_foot(['chartjs'=>true]) loads Chart.js
- [ ] Dashboard can render chart

## Date Range
- [ ] exec_summary: period (m), office filter
- [ ] dashboard_detail: month, year, as_of
- [ ] wqs_dashboard: period (m)

## CSV Export
- [ ] exec_summary: ?export=csv downloads CSV
- [ ] dashboard_detail: target_csv, finance_csv (existing)

## Scheduled Reports
- [ ] php tools/cron/scheduled_reports.php runs
- [ ] storage/logs/scheduled_reports_last.json created
