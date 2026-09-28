# Test Plan - Phase 1

## Stock Opname Mobile API

### GET stock/opname_list
- [ ] Without auth → 401
- [ ] With auth, no stock perm → 403
- [ ] With auth + stock perm → 200, data.items array
- [ ] Filter office_code, depo_name, status

### GET stock/opname_detail?id=
- [ ] Without auth → 401
- [ ] With auth, id invalid → 404
- [ ] With auth, id valid → 200, header + items

### POST stock/opname_create
- [ ] Without X-Idempotency-Key → 422
- [ ] Missing office_code/depo_name → 422
- [ ] Valid payload → 200, opname_id, opname_code, status DRAFT

### POST stock/opname_input_qty
- [ ] opname_id + items required
- [ ] Items: [{item_id, qty_fisik}]
- [ ] Opname must be DRAFT

### POST stock/opname_apply
- [ ] opname_id required
- [ ] Opname must be DRAFT
- [ ] Applies delta to wqs_stock, creates wqs_stock_adjustments

## Redis
- [ ] rmi_redis_enabled() returns false when ext-redis missing
- [ ] rmi_cache_get/set/del work when Redis available
- [ ] redis_status.last.json written to storage/logs

## Rate Limit
- [ ] 120 req/min per user (authenticated)
- [ ] 120 req/min per IP (unauthenticated)
- [ ] ERR_RATE_LIMIT 429 when exceeded

## Smoke
```bash
php tools/qa/smoke_mobile_stock_opname.php
# Requires APP_BASE_URL, SMOKE_ADMIN_USER, SMOKE_ADMIN_PASS
```
