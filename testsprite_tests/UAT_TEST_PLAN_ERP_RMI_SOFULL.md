# UAT Test Plan — ERP_RMI_SOFULL

**Sumber:** docs/ERP_MENU_WORKFLOW_REFERENCE.md  
**APP_ROOT:** /volume4/web/ERP_RMI_SOFULL  
**Base URL:** http://10.10.60.20/ERP_RMI_SOFULL (internal) | https://erp.rizqullahmediska.com/ERP_RMI_SOFULL (public)

---

## 1. Persona & Credentials

| Persona | Username | Password | Role | Dept |
|---------|----------|----------|------|------|
| GUEST | — | — | Belum login | — |
| STAFF | smoke_staff | SmokeStaff#123 | STAFF | ITC |
| ADMIN | smoke_admin | SmokeAdmin#123 | ADMIN | ITC |

*Credentials dari tools/smoke_http.php (SMOKE_STAFF_USER, SMOKE_ADMIN_USER, dll). Seed otomatis saat smoke run.*

---

## 2. Modul & URL (dari ERP_MENU_WORKFLOW_REFERENCE.md)

### MAIN
| URL | Role | Expected |
|-----|------|----------|
| /dashboards/index.php | ALL | 200 |
| /chat/index.php | ALL (CHAT.VIEW) | 200 atau 403 |
| /docs/help_center.php | ALL | 200 |
| /dashboards/owner/exec_summary.php | SYS, ADMIN, SUPERADMIN | 200 atau 403 |
| /dashboards/quality/qc_complaint_dashboard.php | ALL (DASHBOARD.QUALITY_VIEW) | 200 atau 403 |

### MASTER
| URL | Role | Expected |
|-----|------|----------|
| /master/index.php | ITC, ADMIN, SUPERADMIN | 200 atau 403 |
| /master/master_products.php | MASTER.VIEW | 200 atau 403 |
| /master/master_customers.php | MASTER.VIEW | 200 atau 403 |
| /master/master_vendors.php | MASTER.VIEW | 200 atau 403 |
| /master/master_manufactures.php | MASTER.VIEW | 200 atau 403 |
| /master/company_bank_accounts.php | FIN, ADMIN | 200 atau 403 |
| /master/master_system_login.php | ADMIN, SUPERADMIN | 200 (admin) / 302 (guest, staff) |
| /master/master_system_config.php | ADMIN, SUPERADMIN | 200 (admin) / 302 (guest, staff) |

### CRM/SALES
| URL | Role | Expected |
|-----|------|----------|
| /sales/index.php | CRM, ADMIN | 200 atau redirect |
| /sales/sales_do.php | SALES.* | 200 atau 403 |
| /sales/sales_order.php | SALES.* | 200 atau 403 |
| /sales/crm_leads.php | SALES.* | 200 atau 403 |
| /sales/crm_lead_create.php | SALES.* | 200 atau 403 |
| /sales/crm_lead_view.php | SALES.* | 200 atau 403 |
| /sales/crm_lead_edit.php | SALES.* | 200 atau 403 |
| /sales/sales_control_tower.php | CRM, WQS, SCM, ACT, FIN | 200 atau 403 |
| /sales/tax_invoices.php | ACT, FIN | 200 atau 403 |
| /stock/wqs_do_tasks.php | WQS, SALES.TASK_WQS | 200 atau 403 |
| /sales/scm_do_tasks.php | SCM, SALES.TASK_SCM | 200 atau 403 |
| /sales/act_do_tasks.php | ACT, SALES.TASK_ACT | 200 atau 403 |
| /sales/fin_do_tasks.php | FIN, SALES.TASK_FIN | 200 atau 403 |

### PQP/PURCHASES
| URL | Role | Expected |
|-----|------|----------|
| /purchases/index.php | PQP, SCM, ADMIN | 200 |
| /purchases/purchases_po.php | PURCHASES.* | 200 atau 403 |
| /purchases/purchases_gr.php | PURCHASES.GR_PROCESS | 200 atau 403 |
| /purchases/purchases_invoice_ap.php | PURCHASES.AP_* | 200 atau 403 |
| /purchases/purchases_payment_ap.php | PURCHASES.AP_* | 200 atau 403 |
| /purchases/purchases_import_control_tower.php | PURCHASES.IMPORT_CONTROL | 200 atau 403 |
| /purchases/stock_update_from_gr.php | WQS, PQP | 200 atau 403 |
| /purchases/purchases_ceisa_pib.php | ACT, PQP | 200 atau 403 |

### WQS/STOCK
| URL | Role | Expected |
|-----|------|----------|
| /stock/wqs_stock.php | WQS.INCOMING_CRUD, STOCK.ADJUST | 200 atau 403 |
| /stock/wqs_incoming.php | WQS.INCOMING_CRUD | 200 atau 403 |
| /stock/wqs_pr.php | WQS.PR_CRUD | 200 atau 403 |
| /stock/wqs_allocation.php | WQS.ALLOCATION | 200 atau 403 |
| /stock/wqs_picking.php | WQS.PICKING_CRUD | 200 atau 403 |
| /stock/wqs_stock_adjustment.php | STOCK.ADJUST | 200 atau 403 |
| /stock/wqs_stock_transfer.php | WQS.TRANSFER_CRUD | 200 atau 403 |
| /stock/wqs_stock_opname.php | STOCK.ADJUST, WQS.* | 200 atau 403 |
| /stock/wqs_stock_audit.php | STOCK.AUDIT_VIEW | 200 atau 403 |

### HRL
| URL | Role | Expected |
|-----|------|----------|
| /hrl/index.php | HRL, ADMIN | 200 |
| /hrl_process/index.php | Multi-dept | 200 |
| /hrl_reg_alkes/index.php | HRL, ADMIN | 200 |

### ABSENSI
| URL | Role | Expected |
|-----|------|----------|
| /absensi/index.php | ALL | 200 |
| /absensi/checkin.php | ALL | 200 |
| /absensi/checkout.php | ALL | 200 |
| /absensi/request.php | ALL | 200 |
| /absensi/approval.php | ABSENSI.APPROVE | 200 atau 403 |

### KPI
| URL | Role | Expected |
|-----|------|----------|
| /kpi/index.php | ALL | 200 atau redirect |
| /kpi/kpi_center.php | KPI.VIEW | 200 atau 403 |
| /kpi/kpi_do_sla.php | SALES.KPI_VIEW | 200 atau 403 |

### FIN/PAYROLL
| URL | Role | Expected |
|-----|------|----------|
| /payroll/index.php | FIN, HRL, ADMIN | 200 |
| /payroll/payroll_run.php | PAYROLL.* | 200 atau 403 |
| /payroll/payslip.php | PAYROLL.* | 200 atau 403 |

### MPR
| URL | Role | Expected |
|-----|------|----------|
| /mpr/index.php | MPR, FIN, ADMIN | 200 |
| /mpr/mpr_dashboard.php | MPR | 200 atau 403 |
| /mpr/mpr_plans.php | MPR | 200 atau 403 |
| /mpr/mpr_plan_view.php | MPR | 200 atau 403 |
| /mpr/mpr_budget_fin.php | FIN | 200 atau 403 |
| /mpr/mpr_ops_daily_fin.php | MPR | 200 atau 403 |

### ACT (Fixed Asset)
| URL | Role | Expected |
|-----|------|----------|
| /Fixed_Asset/index.php | ACT, FIN, ADMIN | 200 |
| /Fixed_Asset/assets.php | FIXED_ASSET.ASSET_CRUD | 200 atau 403 |
| /Fixed_Asset/depreciation.php | FIXED_ASSET.* | 200 atau 403 |
| /Fixed_Asset/disposals.php | FIXED_ASSET.* | 200 atau 403 |
| /Fixed_Asset/transfers.php | FIXED_ASSET.* | 200 atau 403 |
| /Fixed_Asset/audit.php | FIXED_ASSET.* | 200 atau 403 |

### RBAC & TOOLS
| URL | Role | Expected |
|-----|------|----------|
| /rbac/index.php | ITC, ADMIN, SUPERADMIN | 200 (admin) / 302 (guest, staff) |
| /tools/index.php | ITC, ADMIN, SUPERADMIN | 200 (admin) / 302 (guest, staff) |
| /tools/health.php | ADMIN | 200 (admin) / 302 (guest, staff) |
| /tools/backup_manager.php | ADMIN | 200 (admin) / 302 (guest, staff) |
| /tools/backup_schedule.php | ADMIN | 200 atau 302 |
| /tools/backup_verify.php | ADMIN | 200 atau 302 |
| /tools/qa/smoke_http_web.php | ADMIN | 200 atau 302 |
| /tools/qa/contract_check_web.php | ADMIN | 200 atau 302 |
| /tools/qa/cutover_checks_web.php | ADMIN | 200 atau 302 |

---

## 3. Security / Negative Tests

| Test | Expected |
|------|----------|
| POST tanpa CSRF ke backup_manager | 403 |
| POST tanpa CSRF ke master_system_login create | 403 |
| Guest GET /tools/health.php | 302 |
| Guest GET /tools/backup_manager.php | 302 |
| Guest GET /master/master_system_login.php | 302 |
| Staff GET /tools/health.php | 302 atau 403 |
| Staff GET /master/master_system_login.php | 302 atau 403 |

---

## 4. API Health

| Endpoint | Expected |
|----------|----------|
| GET /api/v1/health.php | 200, JSON dengan request_id |

---

## 5. Flow Test (Non-Destructive)

### P2P
- GET /stock/wqs_pr.php (form load)
- GET /purchases/purchases_po.php
- GET /stock/wqs_incoming.php atau /purchases/purchases_gr.php
- GET /purchases/purchases_invoice_ap.php
- GET /purchases/purchases_payment_ap.php

### O2C
- GET /sales/sales_do.php
- GET /sales/sales_control_tower.php
- GET /stock/wqs_do_tasks.php
- GET /sales/scm_do_tasks.php
- GET /sales/act_do_tasks.php
- GET /sales/fin_do_tasks.php

### Stock Opname
- GET /stock/wqs_stock_opname.php (list/create)

---

## 6. Path Policy (CRITICAL)

- **DILARANG:** Path /Volumes/ di log, config, test output.
- **Wajib:** Eksekusi dari /volume4/web/ERP_RMI_SOFULL (NAS).
- **Verifikasi:** php tools/qa/volumes_police.php → overall_ok: true, violations: [].

---

## 7. Missing Mapping (skip jika file tidak ada)

*Dicek saat eksekusi. Jika URL di dokumen tidak ada file-nya → SKIP, catat "missing mapping".*
