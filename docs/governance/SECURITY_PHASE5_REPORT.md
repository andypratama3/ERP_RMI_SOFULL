# Security Scan Report

**Generated:** 2026-02-28T20:27:58+00:00 | Request ID: sec-20260228202152-59b99a08  
**Updated:** 2026-03-07 — Penjelasan false positive & file dihapus

## Result: FAIL (banyak false positive — lihat penjelasan)

| Severity | Count |
|----------|-------|
| critical | 4 |
| high | 61 |

## Penjelasan Temuan (2026-03-07)

| Temuan | Status |
|--------|--------|
| **tools/qa/security_scan.php** | ❌ File tidak ada (dihapus/rename). Abaikan. |
| **tools/enterprise_audit.php** | ✅ Read-only scan, tidak handle POST. No CSRF needed. |
| **tools/cache_admin.php** | ✅ Sudah punya verify_csrf + hash_equals. |
| **tools/ops/* MISSING_REQUIRE_LOGIN** | ⚠️ CLI-only atau pakai tools_require_access() → require_login. |
| **app/* MISSING_REQUIRE_LOGIN** | ⚠️ Service classes, bukan web entry. False positive. |
| **dashboards/_manager_scope.php** | ⚠️ Helper file, bukan entry point. |
| **sales/tracking_public*.php** | ✅ By design — public dengan token. |
| **kpi/kpi_employee.php RAW_SQL_CONCAT** | ✅ False positive — kpi_audit() pakai prepared statement. |

## Findings (Original)

- [high] tools/enterprise_audit.php:0 MISSING_VERIFY_CSRF — File handles POST but no verify_csrf *(read-only, no POST)*
- [critical] tools/qa/security_scan.php:5 DIRECT_ECHO_UNESCAPED — *File tidak ada.*
- [critical] tools/qa/security_scan.php:52 DIRECT_ECHO_UNESCAPED — // Direct echo $_GET/$_POST without escaping (exclude comments)
- [high] tools/ops/snapshot_ops_score.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/update_alerting_policy.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/generate_ops_snapshot.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/generate_fix_backlog_trend.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/restore_now.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/health.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/weekly_trend_build.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/validate_ops_thresholds.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/generate_fix_backlog.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/generate_executive_summary.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/evaluate_alerts.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/create_ops_gov_patch_zip.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/run_daily_sop.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/backup_verify.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/generate_weekly_ops_report.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/update_ops_thresholds.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/validate_alerting_policy.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/alert_engine.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/backup_retention.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/validate_executive_summary.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/fix_act_fin_integrity.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/backup_schedule.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/snapshot_fix_backlog.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/sla_monitor_daily.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/module_governance_tracker.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/generate_ops_trend.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/backup_manager.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/ops/generate_ops_score_trend.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] tools/cache_admin.php:0 MISSING_VERIFY_CSRF — File handles POST but no verify_csrf
- [high] app/Accounting/GLReportService.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Accounting/ThreeWayMatchValidator.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Accounting/GLPostingService.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Accounting/TaxInvoiceService.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Accounting/BankReconService.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Bootstrap/AppBootstrap.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Bootstrap/legacy_entry.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Bootstrap/app_root.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Security/LoginThrottle.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Security/MFABypassService.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Security/TotpService.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Security/MFAPolicyService.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Security/RateLimiterService.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Security/SecurityHeaders.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/CRM/LeadDedupeService.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Dashboard/DashboardDetailService.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Support/AppLogger.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Support/RequestContext.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Support/path_mask.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Api/ApiResponse.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Workers/ChatRetentionWorker.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Modules/Chat/Module.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Services/MarketplaceSyncService.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Services/MarketplaceStockSync.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Services/SalesTrackingService.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Services/ChatService.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Services/ChatMentionService.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] app/Services/ChatAttachmentService.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] sales/tracking_public_live.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] sales/tracking_public.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [high] dashboards/_manager_scope.php:0 MISSING_REQUIRE_LOGIN — Web entry without require_login
- [critical] kpi/kpi_employee.php:455 RAW_SQL_CONCAT — if (function_exists('kpi_audit')) { kpi_audit($pdo, 'kpi_employee', 'sync_audit_
- [critical] exports/deploy/2026-02/ERP_RMI_SOFULL_deploy_20260225/kpi/kpi_employee.php:455 RAW_SQL_CONCAT — if (function_exists('kpi_audit')) { kpi_audit($pdo, 'kpi_employee', 'sync_audit_
