# Fix Report - Phase 3 (Compliance)

**Date:** 2026-03-01

## Summary
Reg Alkes compliance export, expiry check stub, internal API, webhooks, bank statement import preview.

## Files Created
- hrl_reg_alkes/reg_alkes_export_compliance.php (CSV export)
- hrl_reg_alkes/reg_alkes_expiry_check.php (stub)
- api/v1/internal/compliance/reg_alkes_summary.php
- api/v1/webhooks/marketplace_order.php (X-Webhook-Secret, marketplace_orders_inbox)
- api/v1/webhooks/payment_callback.php (payment_callbacks_inbox)
- sql/migrations/112_marketplace_inbox.sql
- purchases/bank_statement_import.php (preview only)

## Config
- MARKETPLACE_WEBHOOK_SECRET (env) for marketplace_order webhook
