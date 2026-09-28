# Isi Masing-Masing Landing Page / Dashboard

Dokumen ini merinci isi setiap dashboard yang menjadi landing page per departemen.

---

## 1. Branch Dashboard
**Path:** `dashboards/branch/branch_dashboard.php`  
**Dept:** BRANCH

### Widget / KPI
| Widget | Sumber Data | Keterangan |
|--------|-------------|------------|
| Penjualan Cabang (MTD) | `sales_do` | Jumlah DO + Nilai Rp bulan ini per office user |
| Jumlah DO | Count DO | DO dengan `office_code` = office user |
| Nilai (Rp) | Sum `grand_total` | Total nilai DO bulan ini |

### Quick Links
- Buat DO
- Sales Control Tower
- CRM Leads
- WQS Incoming
- PR
- PO
- GR
- HRL Process
- Chat
- Absensi

---

## 2. CRM / Sales Dashboard
**Path:** `sales/sales_dashboard.php`  
**Dept:** CRM

### Widget / KPI
| Widget | Sumber Data | Keterangan |
|--------|-------------|------------|
| Stage Counts | `sales_do` | Jumlah DO per stage: CRM, WQS, SCM, ACT, FIN |
| Stage Overdue | `sales_do` | DO overdue per stage (SLA) |
| CRM Leads Funnel | `crm_leads` | DRAFT, SUBMITTED, APPROVED, CLOSED, CANCELLED |
| Approval % | Derived | Approved / Submitted |
| Close % | Derived | Closed / Approved |

### Filter
- `date_from`, `date_to`
- `department`, `status`
- `include_locked`

### Quick Links
- KPI / Audit
- KPI / SLA
- Export KPI CSV
- CRM Leads
- Tax Invoice Workflow
- Absensi

---

## 3. Warehouse (WQS) Dashboard
**Path:** `dashboards/warehouse/wqs_dashboard.php`  
**Dept:** WQS

### Widget / KPI
| Widget | Sumber Data | Keterangan |
|--------|-------------|------------|
| SKU On Hand | `wqs_stock` / `wqs_stock_by_office` | Jumlah SKU dengan stock > 0 |
| Qty On Hand | `wqs_stock` | Total qty stock |
| Incoming MTD | `wqs_incoming` | Jumlah incoming bulan ini |
| Allocation MTD | `wqs_allocations` | Jumlah allocation bulan ini |
| Picking MTD | `wqs_picking` | Jumlah picking bulan ini |
| Unallocated Items | `wqs_incoming_items` vs `wqs_allocations` | Item incoming belum dialokasi |
| Expiring 90 hari | `wqs_allocations` | Item exp date dalam 90 hari |
| Expired | `wqs_allocations` | Item sudah expired |

### Daftar
- Expiring Soon (top 15 by exp_date)

### Quick Links
- Stock (WQS)
- WQS Incoming
- WQS Allocation
- WQS Picking
- WQS DO Tasks
- Stock Opname
- Stock Adjustment
- Dashboard Center
- KPI Center

---

## 4. SCM Dashboard
**Path:** `dashboards/scm/scm_dashboard.php`  
**Dept:** SCM

### Widget / KPI
| Widget | Sumber Data | Keterangan |
|--------|-------------|------------|
| PR Submitted | `wqs_pr` | PR status SUBMITTED (menunggu PO) |
| PO Open | `purchases_po` | PO status OPEN, IN_PRODUCTION, READY |
| Incoming MTD | `wqs_incoming` | Jumlah incoming bulan ini |

### Quick Links
- HRL Process
- SCM DO Tasks
- Procurement / Import
- Purchases (PQP)
- PO
- Forwarding Tasks
- Import Control Tower
- Sales Control Tower
- Master Vendor
- Quality & Complaint
- Absensi

---

## 5. Finance Dashboard
**Path:** `dashboards/finance/ar_ap_cash_dashboard.php`  
**Dept:** FIN

### Widget / KPI
| Widget | Sumber Data | Keterangan |
|--------|-------------|------------|
| Cash Balance | KPI policy | Total cash |
| Weekly Burn | KPI policy | Burn rate |
| Runway (minggu) | Derived | Cash / Burn |
| AR Outstanding | `sales_do` | Total AR status wait_payment |
| AR Overdue | `sales_do` | AR dengan due_date < today |
| AR Aging | `sales_do` | 0-30, 31-60, 61-90, 90+ hari |
| Top Overdue | `sales_do` | Top 10 customer overdue |
| AP Outstanding | `purchases_invoice_ap` | Count + amount |
| AP Due This Week | `purchases_invoice_ap` | Jatuh tempo 7 hari |

### Quick Links
- KPI Monthly / Daily
- FIN DO Tasks
- Tax Invoice
- Control Tower
- AP Invoice
- Rekening Perusahaan
- Dashboard Detail (Admin)

---

## 6. ACT Dashboard
**Path:** `dashboards/act/act_dashboard.php`  
**Dept:** ACT

### Widget / KPI
- Tidak ada KPI widget (hanya Quick Links)

### Quick Links
- Tax Invoice Workflow
- HRL Process
- Fixed Asset
- ACT - Task DO
- AP Invoice
- AP Payment
- Bank Recon
- Rekening Perusahaan
- Tax Annual
- Absensi
- KPI Center
- Sales Control Tower

---

## 7. HRL Dashboard
**Path:** `dashboards/hrl/hrl_dashboard.php`  
**Dept:** HRL

### Widget / KPI
| Widget | Sumber Data | Keterangan |
|--------|-------------|------------|
| Dokumen HRL | `hrl_docs` | Total dokumen (deleted_at IS NULL) |
| Absensi Hari Ini | `absensi_logs` | Check-in hari ini |
| Karyawan Aktif | `master_employees` | Status active |

### Quick Links
- HRL (Docs)
- HRL Process
- HRL Tower
- HRL Reg Alkes
- Absensi
- KPI Center
- Payroll
- Master Karyawan

---

## 8. ITC Dashboard
**Path:** `dashboards/itc/itc_dashboard.php`  
**Dept:** ITC

### Widget / KPI
- Tidak ada KPI widget (hanya Quick Links)

### Quick Links
- HRL Process
- RBAC
- Tools
- ITC Reset Password
- API Partner Keys
- MFA Settings
- Security Settings
- Health Check
- Absensi
- KPI Center

---

## 9. PQP / Purchases Dashboard
**Path:** `purchases/purchases_dashboard.php`  
**Dept:** PQP

### Widget / KPI
| Widget | Sumber Data | Keterangan |
|--------|-------------|------------|
| PR Submitted | `wqs_pr` | PR menunggu PO |
| PO Open | `purchases_po` | PO belum selesai |
| PO Value Month | `purchases_po` | Total nilai PO bulan ini |
| AP Outstanding | `purchases_payment_ap` | Count AP OPEN/PARTIAL |
| AP Unpaid | `purchases_payment_ap` | Total balance_amount |

### Quick Links
- HRL Process
- Master Manufactures
- Stock (WQS)
- Absensi
- Import Control Tower
- PR, PO, GR, AP Invoice, Payment

---

## 10. MPR Dashboard
**Path:** `mpr/mpr_dashboard.php`  
**Dept:** MPR

### Widget / KPI
| Widget | Sumber Data | Keterangan |
|--------|-------------|------------|
| Plans Active | `mpr_plans` | Status ACTIVE, APPROVED, DRAFT, SUBMITTED, REJECTED |
| Plans Submitted | `mpr_plans` | Menunggu approval |
| Visits MTD | `mpr_visits` | Kunjungan bulan ini |
| Progress MTD | `mpr_progress` | Progress 7 hari terakhir |
| Budget Submitted | `mpr_budget_requests` | Budget menunggu FIN |

### Quick Links
- Plans, Visits, Progress, Budget
- Daily Ops
- KPI Center

---

## 11. Procurement / Import Dashboard
**Path:** `dashboards/procurement/import_po_dashboard.php`  
**Dept:** PQP, SCM, WQS

### Isi
- **Redirect** ke `purchases/purchases_import_control_tower.php` (atau `purchases_dashboard.php` jika file tidak ada)

---

## 12. Dashboard Center (Index)
**Path:** `dashboards/index.php`  
**Akses:** SYS (non-dept redirect)

### Isi
- Grid cards ke semua dashboard (Owner, Branch, CRM, MPR, Warehouse, Procurement, Finance, Regulatory, Quality, SCM, HRL, ITC, ACT)
- Filter visibility berdasarkan permission & dept
- Admin melihat semua cards

---

## Mapping Dept → Landing

| Dept | Landing Page |
|------|--------------|
| BRANCH | `dashboards/branch/branch_dashboard.php` |
| CRM | `sales/sales_dashboard.php` |
| WQS | `dashboards/warehouse/wqs_dashboard.php` |
| SCM | `dashboards/scm/scm_dashboard.php` |
| FIN | `dashboards/finance/ar_ap_cash_dashboard.php` |
| ACT | `dashboards/act/act_dashboard.php` |
| HRL | `dashboards/hrl/hrl_dashboard.php` |
| ITC | `dashboards/itc/itc_dashboard.php` |
| PQP | `purchases/purchases_dashboard.php` |
| MPR | `mpr/mpr_dashboard.php` |
