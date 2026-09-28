# Fix Report - Phase 4 (BI)

**Date:** 2026-03-01

## Summary
Chart.js support, date range filters, CSV export mandatory, scheduled reports stub.

## Modifications
- _shared/assets.php: Chart.js CDN when opts['chartjs']=true in rmi_assets_foot()
- dashboards/owner/exec_summary.php: CSV export link + export=csv handler
- tools/cron/scheduled_reports.php: stub (writes to storage/logs/scheduled_reports_last.json)

## CSV Export
- exec_summary: Export CSV button, outputs Revenue MTD/YTD, AR, Inventory, PO
- dashboard_detail: already has target_csv, finance_csv (existing)

## Chart.js
- Use rmi_assets_foot(['chartjs'=>true]) in dashboards that need charts

## XLSX
- PhpSpreadsheet not present; CSV is mandatory. XLSX when PhpSpreadsheet available.
