# TestSprite Full Regression Suite

**Source of truth:** docs/ERP_MENU_WORKFLOW_REFERENCE.md  
**APP_ROOT:** /volume4/web/ERP_RMI_SOFULL

## CRITICAL

- **Path policy:** Jika workspace path mengandung `/Volumes/` (Mac) → CRITICAL FAIL, STOP.
- **Eksekusi wajib dari NAS:** `cd /volume4/web/ERP_RMI_SOFULL`

## Coverage (sesuai PRD)

| Kategori | Tests |
|----------|-------|
| A) AUTH + RBAC (P0) | Guest login, block tools/health, block master_system_login; Staff login, block; Admin login, dashboards, tools/health, backup_manager |
| B) WQS STOCK (P0) | stock/index, wqs_pr, wqs_incoming, wqs_stock, wqs_stock_adjustment, wqs_picking, wqs_allocation, wqs_stock_opname, wqs_dashboard |
| C) PURCHASES (P0) | purchases/index, purchases_po, purchases_invoice_ap, purchases_payment_ap, gl_reversal_approvals |
| D) SALES (P1) | sales/index, crm_leads, sales_order, sales_do, act_do_tasks, sales_control_tower |
| E) TOOLS OPS (P0) | tools/index, health, uat_smoke, backup_manager, backup_schedule, backup_verify; staff_block_tools |
| F) API (P0) | health 200 + JSON envelope (request_id); mobile/stock/items 401 |
| PUBLIC | health, login, / (min 3 read-only) |

## Run

```bash
cd /volume4/web/ERP_RMI_SOFULL
TOOLS_BASE_URL_INTERNAL=http://10.10.60.20/ERP_RMI_SOFULL php tools/qa/testsprite_regression.php
```

**Env vars (opsional):**
- `TS_SKIP_PUBLIC=1` — skip public URL smoke (jika firewall/WAF block)
- `TS_SKIP_DB_CLI=1` — skip db connect & seed (jika CLI tidak punya pdo_mysql; pakai user yang sudah ada)

## Gate

```bash
php tools/qa/testsprite_gate.php
# Exit 0 = pass (passRate 100%, no critical)
# Exit 2 = fail
```

## Outputs

| Path | Isi |
|------|-----|
| storage/logs/testsprite_last_summary.json | Full JSON |
| storage/logs/testsprite_last_summary.md | Markdown summary |
| storage/logs/testsprite_last_evidence/ | Screenshots/trace (placeholder) |

## Credentials

Env: `TS_ADMIN_USER`, `TS_ADMIN_PASS`, `TS_STAFF_USER`, `TS_STAFF_PASS`  
Fallback: `SMOKE_ADMIN_USER`, `SMOKE_STAFF_USER` atau default smoke_admin, smoke_staff.

**QA users per dept:** Jalankan `php tools/seed/seed_qa_users.php` atau buka `/tools/seed/seed_qa_users.php` sebagai Admin. Lihat `testsprite_tests/UI_TEST_SPEC.md` untuk daftar lengkap.
