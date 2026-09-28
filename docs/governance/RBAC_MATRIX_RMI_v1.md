# RBAC Matrix RMI v1 — Module Permissions (MOD_*)

**Versi:** 1.0  
**Dokumen:** RBAC Module Access Matrix untuk ERP_RMI_SOFULL  
**Target:** Deny-by-default, MOD_* permission per modul.

---

## 1. Role List

| Role/Dept | Deskripsi |
|-----------|-----------|
| **SYS** | System Administrator — all modules + all actions |
| **ITC** | IT/Infrastructure — MOD_TOOLS + read modules as needed |
| **MPR** | Management/Policy — MOD_MPR + MOD_KPI + read-only dashboards |
| **CRM** | Sales/Customer — MOD_SALES + MOD_MASTER(customers) + CRM leads |
| **SCM** | Supply Chain — MOD_PURCHASES (shipping/forwarder) + MOD_STOCK + MOD_MASTER(vendor forwarder) |
| **ACT** | Accounting — MOD_PURCHASES(AP invoice) + MOD_FINANCE(GL/tax) |
| **FIN** | Finance — MOD_FINANCE + MOD_PURCHASES(payments) |
| **HRL** | HR/Legal — MOD_HRL + MOD_MASTER(products legal status) |
| **WQS** | Warehouse — MOD_STOCK + MOD_PURCHASES(PR/GR) |
| **PQP** | Purchasing — MOD_PURCHASES + MOD_MASTER(manufactures/products) + MOD_STOCK(read) |

---

## 2. Module Permissions (MOD_*)

| Permission | Module | Deskripsi |
|------------|--------|-----------|
| MOD_MASTER | MASTER | Master Data Center |
| MOD_PURCHASES | PURCHASES | Modul Purchases |
| MOD_STOCK | STOCK | Modul Stock |
| MOD_SALES | SALES | Modul Sales |
| MOD_FINANCE | FINANCE | Modul Finance |
| MOD_HRL | HRL | Modul HRL |
| MOD_PAYROLL | PAYROLL | Modul Payroll |
| MOD_MPR | MPR | Modul MPR |
| MOD_KPI | KPI | Modul KPI |
| MOD_FIXED_ASSET | FIXED_ASSET | Modul Fixed Asset |
| MOD_CHAT | CHAT | Modul Chat |
| MOD_TOOLS | TOOLS | Tools (ITC) |

---

## 3. Module Permissions per Role (Minimum Mapping)

| Role | MOD_* Granted |
|------|---------------|
| **SYS** | All MOD_* + all actions (SYS = ADMIN = SUPERADMIN) |
| **ITC** | MOD_TOOLS + all MOD_* read-only (safe default) |
| **MPR** | MOD_MPR, MOD_KPI + read-only dashboards |
| **CRM** | MOD_SALES, MOD_MASTER (customers), CRM leads |
| **SCM** | MOD_PURCHASES (shipping/forwarder), MOD_STOCK, MOD_MASTER (vendor forwarder) |
| **PQP** | MOD_PURCHASES, MOD_MASTER (manufactures/products), MOD_STOCK (read) |
| **WQS** | MOD_STOCK, MOD_PURCHASES (PR/GR) |
| **ACT** | MOD_PURCHASES (AP invoice), MOD_FINANCE (GL/tax) |
| **FIN** | MOD_FINANCE, MOD_PURCHASES (payments) |
| **HRL** | MOD_HRL, MOD_MASTER (products legal status) |

**Note:** Keep minimal and safe; refine later. Dept-based guards remain; MOD_* is additive.
