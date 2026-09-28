# API Cache Policy Matrix

| Endpoint | TTL | Scope Key | Cached? | Risk | Notes |
|----------|-----|-----------|---------|------|-------|
| dashboard/get | 60 | user_id:role:office_code | Ya | Low | KPI counts, tasks |
| stock/items | 30 | user_id:role:office_code + q, page, limit | Ya | Low | Product list + stock |
| auth/me | - | - | Tidak | - | Personal |
| sales/do_list | - | - | Tidak | - | Transaksi |
| stock/incoming_list | - | - | Tidak | - | Transaksi |
| purchases/* | - | - | Tidak | - | Transaksi |
| chat/* | - | - | Tidak | - | Personal/real-time |

## Phase 1 (Current)

- TTL-based invalidation only
- No cross-module hooks

## Phase 2 (Optional, Future)

- Tag-based bust: `cache_bust_tag("master_products")`
- File: `storage/cache/cache_bust_versions.json`
