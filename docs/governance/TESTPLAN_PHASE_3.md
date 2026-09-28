# Test Plan - Phase 3

## Reg Alkes
- [ ] reg_alkes_export_compliance.php: CSV download
- [ ] reg_alkes_expiry_check.php: stub page loads

## Internal API
- [ ] api/v1/internal/compliance/reg_alkes_summary.php: JSON summary (requires login)

## Webhooks
- [ ] marketplace_order: POST with X-Webhook-Secret, stores to marketplace_orders_inbox
- [ ] payment_callback: POST stores to payment_callbacks_inbox

## Bank Statement
- [ ] bank_statement_import.php: upload CSV, preview rows (no persist)
