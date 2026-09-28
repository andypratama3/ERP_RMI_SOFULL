# Architecture Audit Report

**Run ID:** auto  
**Generated:** 2026-03-03T11:23:40+07:00

## Executive Summary

| Domain | Status | Confidence |
|--------|--------|------------|
| Broker | PRESENT | 100 |
| Data gov | PRESENT | 100 |
| Iam | PRESENT | 100 |
| Monitoring | PRESENT | 100 |
| Backup dr | PRESENT | 100 |

## Findings per Domain

### Broker

- **Status:** PRESENT
- **Confidence:** 100
- **Risk:** Message broker detected.
- **Evidence (sample):**
  - api/health.php [supervisor]
  - api/internal/gl_enqueue_posting.php [supervisor]
  - api/v1/health.php [supervisor]
  - api/v1/internal/gl_enqueue_posting.php [supervisor]
  - docs/archive/root_cleanup_20260213_phase2/OPENAPI.yaml [supervisor]
  - docs/governance/ALERTING_POLICY.yaml [supervisor]
  - docs/governance/ARCHITECTURE_AUDIT_REPORT_LAST.md [supervisor]
  - docs/governance/ARCHITECTURE_AUDIT_REPORT_LAST.md [docs]
  - docs/governance/PATCH_DIFF_UNICODE.md [supervisor]
  - docs/governance/PEMERIKSAAN_ERP_RMI_SOFULL.md [supervisor]

### Data gov

- **Status:** PRESENT
- **Confidence:** 100
- **Evidence (sample):**
  - api/internal/crm_lead_action.php [rfc]
  - api/internal/crm_leads_summary.php [rfc]
  - api/mobile/_router.php [rfc]
  - api/v1/internal/crm_lead_action.php [rfc]
  - api/v1/internal/crm_leads_summary.php [rfc]
  - api/v1/mobile/_router.php [rfc]
  - app/CRM/LeadDedupeService.php [dedupe]
  - docs/DEPLOY_DOCS_CHECKLIST.md [module]
  - docs/NOTES_DOCS_UPDATE_CHECKLIST.md [module]
  - docs/_token_helper.php [module]

### Iam

- **Status:** PRESENT
- **Confidence:** 100
- **Evidence (sample):**
  - api/_lib/partner_auth.php [sso]
  - api/chat/_chat_bootstrap.php [sso]
  - api/chat/_chat_bootstrap.php [login]
  - api/chat/_chat_bootstrap.php [guard]
  - api/internal/_internal_api_bootstrap.php [login]
  - api/internal/_internal_api_bootstrap.php [guard]
  - api/internal/bank_recon_summary.php [rbac]
  - api/internal/bank_recon_summary.php [sso]
  - api/internal/bank_recon_summary.php [login]
  - api/internal/compliance/reg_alkes_summary.php [login]

### Monitoring

- **Status:** PRESENT
- **Confidence:** 100
- **Evidence (sample):**
  - api/health.php [smoke]
  - api/v1/health.php [smoke]
  - app/Services/MarketplaceStockSync.php [log]
  - docs/BRANCH_Checklist_Admin.md [smoke]
  - docs/BRANCH_Set_Holder_Panduan.md [smoke]
  - docs/governance/21_PWA_MOBILE_UX_SPEC.md [log]
  - docs/governance/31_COMPLIANCE_INTEGRATIONS_SPEC.md [log]
  - docs/governance/ALERTING_POLICY.yaml [smoke]
  - docs/governance/APP_ROOT_LOCK_POLICY.md [log]
  - docs/governance/ARCHITECTURE_AUDIT_ONE_PAGER_LAST.md [docs]

### Backup dr

- **Status:** PRESENT
- **Confidence:** 100
- **Evidence (sample):**
  - api/chat/_chat_bootstrap.php [docs]
  - api/health.php [storage]
  - api/health.php [docs]
  - api/internal/crm_lead_action.php [docs]
  - api/internal/crm_leads_listing.php [docs]
  - api/internal/crm_leads_summary.php [docs]
  - api/internal/gl_enqueue_posting.php [docs]
  - api/internal/gl_summary.php [docs]
  - api/internal/gl_trial_balance.php [docs]
  - api/internal/sales_scm_geo_ping.php [docs]

## Scan Coverage

- Files scanned: 698
- Total eligible: 698
- Truncated: no
- Excluded: storage, vendor, node_modules, public/assets/vendor, dist, build

## Inventory

- composer.json: missing
- composer.lock: missing
- package.json: missing
- pnpm-lock.yaml: missing
- yarn.lock: missing
- docker-compose.yml: missing
- Dockerfile: missing
- github_workflows: missing
- config: missing
- sql_migrations: exists
- tools: exists
- docs: exists

## Fix Commands (non-destructive)

```bash
# Run architecture audit
php tools/qa/arch_audit.php --run-id=auto --scope=repo --write-last
```
