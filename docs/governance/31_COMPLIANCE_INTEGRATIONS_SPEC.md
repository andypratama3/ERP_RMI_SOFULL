# Compliance & Integrations Spec (Phase 3)

**Date:** 2026-02-25  
**Phase:** 03

## Overview

Compliance-ready + integration-ready, default OFF untuk side-effects.

## 3.1 Compliance Reg Alkes

### Export (reg_alkes_export_compliance.php)
- **Auth:** require_login, permission HRL_COMPLIANCE_EXPORT or HRL.REG_ALKES_VIEW
- **Output:** CSV default; XLSX if PhpSpreadsheet + ENABLE_EXCEL_EXPORT=1
- **Audit:** COMPLIANCE_EXPORT_REG_ALKES (actor, row_count, format, range)

### Expiry Check (reg_alkes_expiry_check.php)
- **Mode:** CLI (JSON output) or Web (UI)
- **Output:** storage/logs/reg_alkes_expiry_last.json
- **Fields:** total_active, expiring_30d, expiring_90d, expired, top_expiring[]
- **Notify:** NOTIFY_ENABLED=0 default

### Summary API (api/v1/internal/compliance/reg_alkes_summary.php)
- **Auth:** require_login (internal API bootstrap)
- **Response:** {ok, data: {total_active, expiring_30d, expiring_90d, expired, last_check_at}}

## 3.2 Marketplace Integration

### Webhook (api/v1/webhooks/marketplace_order.php)
- **Auth:** X-API-Key or X-Webhook-Secret (env: WEBHOOK_API_KEY, MARKETPLACE_WEBHOOK_SECRET)
- **Validation:** order_id, items[] (minimal)
- **Storage:** marketplace_orders_inbox (external_order_id, request_id, payload_json)
- **Idempotent:** UNIQUE(external_order_id, source) → 409 if duplicate
- **Audit:** WEBHOOK_MARKETPLACE_RECEIVED

### MarketplaceSyncService
- **Input:** inbox_id
- **Output:** sales_marketplace_staging (NEW status)
- **Flag:** MARKETPLACE_AUTO_CREATE_DO=0 default (no auto sales_do)

### MarketplaceStockSync
- **Default:** DRY-RUN (no external API call)
- **Output:** storage/logs/marketplace_stock_sync_last.json
- **Enable:** MARKETPLACE_STOCK_SYNC_DRY_RUN=0, API key + base URL required

## 3.3 Bank & Payment

### Bank Statement Import (tools/finance/bank_statement_import.php)
- **Auth:** require_login, role FIN/ADMIN/SUPERADMIN
- **Flow:** Preview (parse CSV) → Import (POST+CSRF)
- **Tables:** bank_statement_raw, bank_statements, bank_statement_lines
- **Audit:** BANK_STATEMENT_IMPORTED

### Payment Callback (api/v1/webhooks/payment_callback.php)
- **Auth:** X-API-Key (PAYMENT_WEBHOOK_SECRET, WEBHOOK_API_KEY)
- **Storage:** payments_webhook_inbox (or payment_callbacks_inbox fallback)
- **Audit:** WEBHOOK_PAYMENT_RECEIVED
- **No auto-posting:** reconciliation task for FIN

## Security

- No secret in repo
- No absolute path in logs
- All mutating endpoints: auth + CSRF
- Webhooks: rate limit recommended
