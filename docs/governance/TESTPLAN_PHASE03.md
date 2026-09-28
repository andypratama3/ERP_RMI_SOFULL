# Testplan Phase 03 — Compliance & Integrations

## Manual Tests

### Compliance Export
- [ ] Login as HRL/ADMIN, open reg_alkes_export_compliance.php
- [ ] Download CSV → file received, valid rows
- [ ] Without login → redirect to login

### Expiry Check
- [ ] CLI: `php hrl_reg_alkes/reg_alkes_expiry_check.php` → JSON output
- [ ] storage/logs/reg_alkes_expiry_last.json created
- [ ] Web: open reg_alkes_expiry_check.php → counts displayed

### Compliance Summary API
- [ ] GET /api/v1/internal/compliance/reg_alkes_summary.php without login → 401/403
- [ ] With session → 200, data.total_active, expiring_30d, etc.

### Marketplace Webhook
- [ ] POST without X-API-Key (when secret set) → 401
- [ ] POST with valid key + JSON {order_id, items[]} → 200, id returned
- [ ] POST same order_id+source again → 409 Duplicate

### Payment Webhook
- [ ] POST without key (when secret set) → 401
- [ ] POST with valid key → 200

### Bank Statement Import
- [ ] Login as FIN, open tools/finance/bank_statement_import.php
- [ ] Upload CSV → Preview shows rows
- [ ] Import → rows in bank_statement_lines, audit logged

## CLI Smoke

```bash
php tools/qa/smoke_compliance_integrations.php
# Expect: PASS, overall_ok=true
```

## Gate Pass

- PHP lint: 0 error
- smoke_compliance_integrations: overall_ok=true
- No auto-posting by default
- All endpoints guarded
