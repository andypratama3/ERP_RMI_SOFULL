# ERP Full Tracker

> **Generated** — jangan diedit manual. Di-regenerate dengan:
> `php tools/qa/feature_inventory_scan.php --self-test --tracker`

| | |
|---|---|
| Dibuat | `2026-09-29T05:23:00+00:00` |
| Root | `ERP_RMI_SOFULL` |
| Generator | `tools/qa/feature_inventory_scan.php` |
| Halaman terinventarisasi | **930** (778 halaman + 152 helper/include) |
| Modul | **23** |

> **Aturan status:** kolom `status` hanya `UNDETECTED` (belum diperiksa manusia)
> atau `REVIEWED` (sudah dicek manusia). Tool ini **tidak pernah** menulis PASS.
> PASS wajib evidence: command + result + file + timestamp.

## 1. Ringkasan Cakupan

| Dimensi | Halaman | % dari halaman nyata |
|---|---:|---:|
| auth gate | 349 | 45% |
| filter/query | 253 | 33% |
| tabel | 225 | 29% |
| form | 221 | 28% |
| update action | 131 | 17% |
| workflow state | 162 | 21% |
| permission check | 170 | 22% |
| audit trail | 17 | 2% |
| export | 57 | 7% |

## 2. Temuan Berisiko (perlu keputusan owner)

Dihitung dari deteksi statis. **Belum diverifikasi manual** — ini kandidat, bukan vonis.

### 2.1 Halaman yang bisa mengubah data tapi tidak menulis audit trail

- **114 dari 131** halaman yang punya `update_action` + CRUD tidak memanggil `rmi_audit_safe()` / `audit_log()` / `log_audit()`.
- Dicek manual: tidak ada helper audit terpusat di `_shared/`, jadi ini bukan artefak deteksi.
- Hanya 17 halaman yang menulis audit, dan semuanya membentuk satu pola jelas: master CRUD, task DO (act/fin/scm/wqs), dan pergerakan stok.

| Halaman | Filter | Aksi | Status/slot |
|---|---:|---:|---|
| `Fixed_Asset/assets.php` | 8 | 2 | CANCELLED, DRAFT, Draft |
| `Fixed_Asset/assets_receive.php` | 0 | 1 |  |
| `Fixed_Asset/audit.php` | 2 | 1 | CLOSED, OPEN |
| `Fixed_Asset/depreciation.php` | 1 | 1 |  |
| `Fixed_Asset/ops.php` | 1 | 1 |  |
| `absensi/admin/approval.php` | 0 | 1 | APPROVED, PENDING, REJECTED |
| `absensi/admin/late_penalty_settings.php` | 0 | 4 |  |
| `absensi/admin/pins.php` | 0 | 1 |  |
| `api/internal/crm_lead_action.php` | 0 | 4 | APPROVED, CANCELLED, CLOSED, DRAFT |
| `api/v1/internal/crm_lead_action.php` | 0 | 4 | APPROVED, CANCELLED, CLOSED, DRAFT |
| `customer_portal/cart.php` | 0 | 4 |  |
| `customer_portal/order_detail.php` | 2 | 2 | CANCELLED, OPEN, Open, PAID |
| `dashboards/finance/adjustment_manage.php` | 2 | 3 |  |
| `dashboards/finance/dashboard_detail.php` | 13 | 2 | approved, cancelled, draft, rejected |
| `dashboards/finance/target_rekap.php` | 4 | 3 |  |
| `dashboards/hrl/absensi_manual_alpha.php` | 2 | 3 |  |
| `hrl/hrl_doc_view.php` | 2 | 8 | APPROVED, DRAFT, REJECTED, Rejected |
| `hrl/hrl_docs.php` | 6 | 9 | APPROVED, DRAFT |
| `hrl/hrl_tower.php` | 4 | 6 | APPROVED, DRAFT, REJECTED, SUBMITTED |
| `hrl_process/employee_mutation_view.php` | 1 | 6 | APPROVED, CANCELLED, DRAFT, REJECTED |
| `hrl_process/employee_mutations.php` | 2 | 2 | APPROVED, CANCELLED, DRAFT, REJECTED |
| `hrl_process/leave_adjustment.php` | 4 | 3 | PAID |
| `hrl_process/leave_adjustmentedit.php` | 3 | 3 | PAID |
| `hrl_process/my_pin.php` | 0 | 1 |  |
| `hrl_process/request_view.php` | 1 | 11 | DRAFT, PAID, REJECTED, SUBMITTED |
| `hrl_process/tower.php` | 5 | 2 | DRAFT, Draft, PAID, REJECTED |
| `hrl_reg_alkes/reg_alkes.php` | 6 | 1 |  |
| `hrl_reg_alkes/reg_alkes_case.php` | 3 | 7 | CANCELLED, CLOSED, OPEN, PAID |
| `hrl_reg_alkes/reg_alkes_control_tower.php` | 6 | 8 | CLOSED, OPEN |
| `kpi/kpi_do_sla.php` | 3 | 1 | CLOSED, DELIVERED, OPEN, PAID |
| `kpi/kpi_employee.php` | 9 | 1 | DRAFT |
| `kpi/kpi_office.php` | 8 | 1 | DRAFT |
| `kpi/kpi_purchases.php` | 5 | 1 | DRAFT |
| `kpi/kpi_snapshot.php` | 6 | 4 | APPROVED, PENDING, REJECTED, rejected |
| `kpi/kpi_stock.php` | 5 | 1 | DRAFT |
| `kpi/kpi_sync.php` | 0 | 1 |  |
| `manufacturer_portal/case_detail.php` | 1 | 3 | pending |
| `manufacturer_portal/manufacture_docs.php` | 0 | 2 | pending |
| `manufacturer_portal/rfq.php` | 1 | 1 | open, pending, submitted |
| `master/account_readiness.php` | 0 | 2 |  |

… 74 halaman lain. Lihat `ERP_FEATURE_INVENTORY.json`.

### 2.2 Halaman dengan auth gate tapi tanpa cek permission spesifik

- **183 dari 349** halaman punya session/login gate tapi tidak memanggil `require_any_permission()` / `can_any()` / `require_permission()`.
- Auth gate hanya membuktikan *sudah login*, bukan *boleh akses halaman ini*.

| Halaman | CRUD | Risiko |
|---|---|---:|
| `Fixed_Asset/assets.php` | CRU | 13 |
| `Fixed_Asset/audit.php` | CRU | 12 |
| `Fixed_Asset/depreciation.php` | CRUD | 11 |
| `Fixed_Asset/index.php` | R | 4 |
| `Fixed_Asset/ops.php` | CRU | 10 |
| `Fixed_Asset/panduan.php` | — | 2 |
| `Fixed_Asset/tax_annual.php` | R | 4 |
| `absensi/admin.php` | — | 2 |
| `absensi/admin/api/rekap_dt.php` | R | 4 |
| `absensi/admin/api/rekap_dtbaru.php` | R | 4 |
| `absensi/admin/api/rekap_dtrevbaru.php` | R | 4 |
| `absensi/admin/approval.php` | RU | 10 |
| `absensi/admin/broadcast.php` | R | 4 |
| `absensi/admin/kiosk_poster.php` | R | 4 |
| `absensi/admin/late_penalty_settings.php` | CRUD | 9 |
| `absensi/admin/offices.php` | RU | 2 |
| `absensi/admin/payroll_gate.php` | R | 7 |
| `absensi/admin/pins.php` | RU | 8 |
| `absensi/admin/rekap.php` | R | 5 |
| `absensi/admin/rekaprev2809.php` | R | 5 |
| `absensi/admin/settings.php` | CU | 2 |
| `absensi/admin/shifts.php` | CRUD | 4 |
| `absensi/admin/users.php` | CRU | 7 |
| `absensi/approval.php` | — | 2 |
| `absensi/checkin.php` | CU | 2 |
| `absensi/checkout.php` | CU | 2 |
| `absensi/history.php` | R | 2 |
| `absensi/index.php` | R | 2 |
| `absensi/izin.php` | — | 2 |
| `absensi/kiosk.php` | CRU | 4 |
| `absensi/panduan.php` | — | 2 |
| `absensi/photo.php` | — | 4 |
| `absensi/request.php` | CRU | 4 |
| `api/internal/bank_recon_summary.php` | R | 4 |
| `api/internal/crm_lead_action.php` | CRU | 10 |
| `api/internal/crm_lead_detail.php` | R | 4 |
| `api/internal/crm_leads_listing.php` | R | 6 |
| `api/internal/crm_leads_summary.php` | R | 6 |
| `api/internal/gl_enqueue_posting.php` | CU | 4 |
| `api/internal/gl_journal_listing.php` | — | 4 |

… 143 halaman lain.

## 3. Backlog Prioritas (skor risiko tertinggi)

Skor = permukaan fitur + gap yang terdeteksi. Ini urutan kerja, bukan urutan 
pentingnya bisnis — itu perlu owner yang menetapkan.

| # | Skor | Halaman | CRUD | Filter | Aksi | Audit | Perm |
|---:|---:|---|---|---:|---:|:-:|:-:|
| 1 | 14 | `hrl_process/request_view.php` | CRUD | 1 | 11 | — | — |
| 2 | 14 | `payroll/loans.php` | CRUD | 5 | 4 | — | — |
| 3 | 13 | `Fixed_Asset/assets.php` | CRU | 8 | 2 | — | — |
| 4 | 13 | `dashboards/finance/dashboard_detail.php` | RU | 13 | 2 | — | — |
| 5 | 13 | `hrl/hrl_doc_view.php` | CRU | 2 | 8 | — | — |
| 6 | 13 | `hrl/hrl_tower.php` | CRU | 4 | 6 | — | — |
| 7 | 13 | `hrl_process/leave_adjustmentedit.php` | CRUD | 3 | 3 | — | — |
| 8 | 13 | `hrl_process/tower.php` | CRU | 5 | 2 | — | — |
| 9 | 13 | `payroll/index.php` | CRU | 2 | 1 | — | — |
| 10 | 13 | `payroll/payroll_run.php` | CRU | 1 | 12 | — | — |
| 11 | 13 | `stock/wqs_quarantine.php` | CRU | 3 | 5 | — | — |
| 12 | 12 | `Fixed_Asset/audit.php` | CRU | 2 | 1 | — | — |
| 13 | 12 | `hrl_process/employee_mutation_view.php` | RU | 1 | 6 | — | — |
| 14 | 12 | `hrl_process/employee_mutations.php` | CRU | 2 | 2 | — | — |
| 15 | 12 | `hrl_process/leave_adjustment.php` | CRU | 4 | 3 | — | — |
| 16 | 12 | `kpi/kpi_employee.php` | CRUD | 9 | 1 | — | — |
| 17 | 12 | `kpi/kpi_office.php` | CRUD | 8 | 1 | — | — |
| 18 | 12 | `kpi/kpi_purchases.php` | CRUD | 5 | 1 | — | — |
| 19 | 12 | `master_system_config.php` | CRUD | 3 | 1 | — | — |
| 20 | 12 | `mpr/mpr_budget_fin.php` | RU | 6 | 3 | — | — |
| 21 | 12 | `payroll/salary_matrix.php` | CRUD | 7 | 1 | — | — |
| 22 | 12 | `rbac/index.php` | CRUD | 7 | 28 | — | Ya |
| 23 | 12 | `sales/sales_do.php` | CRUD | 14 | 8 | — | Ya |
| 24 | 12 | `sales/sales_do_.php` | CRUD | 6 | 9 | — | Ya |
| 25 | 12 | `sales/sales_do_return.php` | CRUD | 4 | 9 | — | Ya |
| 26 | 12 | `stock/wqs_pr.php` | CRUD | 2 | 4 | — | Ya |
| 27 | 11 | `Fixed_Asset/depreciation.php` | CRUD | 1 | 1 | — | — |
| 28 | 11 | `dashboards/finance/adjustment_manage.php` | CRUD | 2 | 3 | — | — |
| 29 | 11 | `dashboards/finance/target_rekap.php` | CRUD | 4 | 3 | — | — |
| 30 | 11 | `hrl/hrl_docs.php` | CRU | 6 | 9 | — | — |
| 31 | 11 | `hrl_reg_alkes/reg_alkes_case.php` | CRU | 3 | 7 | — | Ya |
| 32 | 11 | `hrl_reg_alkes/reg_alkes_control_tower.php` | CRU | 6 | 8 | — | Ya |
| 33 | 11 | `kpi/kpi_do_sla.php` | CRU | 3 | 1 | — | — |
| 34 | 11 | `kpi/kpi_stock.php` | CRUD | 5 | 1 | — | — |
| 35 | 11 | `master/itc_reset_password.php` | CRU | 5 | 1 | — | Ya |
| 36 | 11 | `master/owner_activity_control.php` | R | 10 | 1 | — | — |
| 37 | 11 | `master/owner_activity_control26.php` | R | 10 | 1 | — | — |
| 38 | 11 | `master/owner_activity_control26_2.php` | R | 10 | 1 | — | — |
| 39 | 11 | `mpr/mpr_plan_view.php` | CRU | 1 | 8 | — | Ya |
| 40 | 11 | `mpr/mpr_plan_view_.php` | CRU | 1 | 8 | — | Ya |
| 41 | 11 | `mpr/mpr_plans.php` | CRU | 5 | 10 | — | Ya |
| 42 | 11 | `mpr/mpr_plansrev2809.php` | CRU | 5 | 10 | — | Ya |
| 43 | 11 | `mpr/mpr_visits.php` | CRU | 6 | 7 | — | Ya |
| 44 | 11 | `mpr/mpr_visitsrev2809.php` | CRU | 6 | 7 | — | Ya |
| 45 | 11 | `purchases/purchases_ceisa_pib_view.php` | CRU | 1 | 4 | — | Ya |
| 46 | 11 | `purchases/purchases_control_tower.php` | RU | 6 | 1 | — | Ya |
| 47 | 11 | `purchases/purchases_forwarding_tasks.php` | CRU | 2 | 3 | — | Ya |
| 48 | 11 | `sales/kpi_do_sla.php` | RU | 3 | 2 | — | Ya |
| 49 | 11 | `sales/kpi_do_sla_fixed_staff_v6.php` | RU | 3 | 2 | — | Ya |
| 50 | 11 | `sales/sales_control_tower.php` | RU | 12 | 2 | — | Ya |

## 4. Ringkasan per Modul

| Modul | Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Skor risiko |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `master` | 91 | 68 | 50 | 37 | 8 | 3 | 46 | 432 |
| `sales` | 59 | 32 | 29 | 13 | 28 | 5 | 26 | 210 |
| `dashboards` | 67 | 29 | 20 | 4 | 19 | 0 | 11 | 186 |
| `purchases` | 62 | 34 | 26 | 9 | 20 | 1 | 33 | 166 |
| `api` | 229 | 32 | 20 | 2 | 18 | 0 | 2 | 158 |
| `mpr` | 28 | 17 | 15 | 8 | 13 | 0 | 8 | 129 |
| `stock` | 36 | 19 | 19 | 13 | 9 | 8 | 19 | 127 |
| `hrl_process` | 21 | 10 | 8 | 7 | 7 | 0 | 0 | 108 |
| `absensi` | 40 | 21 | 11 | 3 | 4 | 0 | 0 | 107 |
| `kpi` | 20 | 14 | 13 | 7 | 10 | 0 | 4 | 100 |
| `payroll` | 21 | 12 | 11 | 8 | 5 | 0 | 0 | 98 |
| `Fixed_Asset` | 14 | 7 | 5 | 5 | 3 | 0 | 0 | 62 |
| `hrl` | 13 | 6 | 5 | 3 | 4 | 0 | 0 | 45 |
| `hrl_reg_alkes` | 15 | 7 | 5 | 3 | 3 | 0 | 8 | 37 |
| `manufacturer_portal` | 8 | 6 | 3 | 3 | 4 | 0 | 0 | 32 |
| `customer_portal` | 11 | 9 | 6 | 2 | 3 | 0 | 0 | 30 |
| `(root)` | 9 | 2 | 1 | 1 | 0 | 0 | 0 | 26 |
| `rbac` | 5 | 2 | 1 | 2 | 1 | 0 | 3 | 18 |
| `web` | 5 | 2 | 3 | 1 | 1 | 0 | 5 | 14 |
| `chat` | 11 | 6 | 2 | 0 | 1 | 0 | 5 | 8 |
| `config` | 6 | 0 | 0 | 0 | 0 | 0 | 0 | 5 |
| `tests` | 6 | 1 | 0 | 0 | 1 | 0 | 0 | 5 |
| `views` | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |

## 5. Daftar Lengkap per Modul

### `master` — 91 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `master/account_readiness.php` | RU | 0 | 2 | 0 | — | Ya | UNDETECTED |
| `master/api_partner_keys.php` | CRUD | 0 | 4 | 0 | — | Ya | UNDETECTED |
| `master/audit_logs.php` | R | 7 | 1 | 0 | — | Ya | UNDETECTED |
| `master/auth.php` | CRU | 0 | 0 | 5 | — | — | UNDETECTED |
| `master/auth_.php` | CRU | 0 | 0 | 5 | — | — | UNDETECTED |
| `master/auth__.php` | CRU | 0 | 0 | 5 | — | — | UNDETECTED |
| `master/company_bank_accounts.php` | CRUD | 7 | 4 | 0 | — | Ya | UNDETECTED |
| `master/doc_numbering_edit.php` | U | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/import_rekening_final.php` | CRU | 0 | 1 | 0 | — | Ya | UNDETECTED |
| `master/index.php` | — | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `master/itc_reset_password.php` | CRU | 5 | 1 | 1 | — | Ya | UNDETECTED |
| `master/jobs_monitor.php` | RU | 1 | 6 | 1 | — | Ya | UNDETECTED |
| `master/login.php` | CRU | 2 | 0 | 0 | — | — | UNDETECTED |
| `master/logout.php` | C | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/manufactures_docs.php` | CRUD | 9 | 0 | 0 | — | — | UNDETECTED |
| `master/manufactures_docsbaru.php` | CRU | 9 | 0 | 0 | — | — | UNDETECTED |
| `master/manufactures_docsrevbaru1609.php` | CRU | 9 | 0 | 0 | — | — | UNDETECTED |
| `master/master_customer_portal_users.php` | CRU | 1 | 4 | 0 | — | Ya | UNDETECTED |
| `master/master_customers lama.php` | CRUD | 1 | 1 | 0 | — | Ya | UNDETECTED |
| `master/master_customers.php` | CRUD | 3 | 1 | 0 | — | Ya | UNDETECTED |
| `master/master_customers_.php` | CRUD | 3 | 1 | 0 | — | Ya | UNDETECTED |
| `master/master_data.php` | — | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `master/master_departements.php` | CRUD | 2 | 0 | 0 | — | Ya | UNDETECTED |
| `master/master_emailcompany.php` | CRUD | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `master/master_employees.php` | CRU | 7 | 0 | 0 | — | Ya | UNDETECTED |
| `master/master_employeesdell.php` | CRUD | 7 | 0 | 0 | — | Ya | UNDETECTED |
| `master/master_employeesid.php` | CRUD | 7 | 0 | 0 | — | Ya | UNDETECTED |
| `master/master_employeesjaba.php` | CRUD | 7 | 0 | 0 | — | Ya | UNDETECTED |
| `master/master_employeesmasakontrak.php` | CRUD | 7 | 0 | 0 | — | Ya | UNDETECTED |
| `master/master_export_customers.php` | R | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `master/master_import_customers.php` | CRU | 1 | 4 | 0 | — | Ya | UNDETECTED |
| `master/master_import_products.php` | CRU | 1 | 4 | 0 | — | Ya | UNDETECTED |
| `master/master_import_vendors.php` | CRU | 0 | 4 | 0 | — | Ya | UNDETECTED |
| `master/master_manufacturer_portal_users.php` | CRU | 1 | 4 | 0 | — | Ya | UNDETECTED |
| `master/master_manufactures.php` | CRU | 7 | 1 | 0 | Ya | Ya | UNDETECTED |
| `master/master_office.php` | CRUD | 1 | 1 | 0 | — | Ya | UNDETECTED |
| `master/master_payment_terms.php` | CRUD | 3 | 0 | 0 | — | — | UNDETECTED |
| `master/master_pricelist.php` | CRU | 5 | 9 | 0 | Ya | — | UNDETECTED |
| `master/master_pricelist_sell.php` | R | 3 | 0 | 0 | — | Ya | UNDETECTED |
| `master/master_product_media_bulk.php` | CR | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `master/master_products.php` | CRUD | 14 | 1 | 1 | Ya | Ya | UNDETECTED |
| `master/master_products_doc.php` | CRU | 2 | 3 | 0 | — | — | UNDETECTED |
| `master/master_products_package.php` | CRU | 1 | 1 | 0 | — | Ya | UNDETECTED |
| `master/master_products_print.php` | R | 4 | 0 | 0 | — | — | UNDETECTED |
| `master/master_system_config.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/master_system_login.php` | CRU | 13 | 1 | 0 | — | Ya | UNDETECTED |
| `master/master_system_loginid.php` | CRU | 13 | 1 | 0 | — | Ya | UNDETECTED |
| `master/master_system_loginmalang.php` | CRU | 13 | 1 | 0 | — | Ya | UNDETECTED |
| `master/master_tax.php` | CRUD | 5 | 0 | 0 | — | — | UNDETECTED |
| `master/master_user.php` | CRUD | 7 | 1 | 0 | — | Ya | UNDETECTED |
| `master/master_user2809.php` | CRUD | 7 | 1 | 0 | — | Ya | UNDETECTED |
| `master/master_user_.php` | CRUD | 6 | 1 | 0 | — | Ya | UNDETECTED |
| `master/master_vendors.php` | CRUD | 14 | 0 | 0 | — | Ya | UNDETECTED |
| `master/mfa_admin_reset.php` | RU | 0 | 2 | 0 | — | — | UNDETECTED |
| `master/mfa_bypass.php` | CRU | 0 | 5 | 3 | — | Ya | UNDETECTED |
| `master/mfa_policy.php` | CRUD | 1 | 4 | 0 | — | Ya | UNDETECTED |
| `master/mfa_settings.php` | RU | 0 | 4 | 0 | — | — | UNDETECTED |
| `master/mfa_verify.php` | RU | 1 | 0 | 0 | — | — | UNDETECTED |
| `master/monitoring_center.php` | R | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `master/monitoring_centerrevhistory.php` | R | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `master/nav_manager.php` | RU | 6 | 0 | 0 | — | Ya | UNDETECTED |
| `master/org_structure_edit.php` | U | 0 | 3 | 0 | — | — | UNDETECTED |
| `master/owner_activity_control.php` | R | 10 | 1 | 0 | — | — | UNDETECTED |
| `master/owner_activity_control26.php` | R | 10 | 1 | 0 | — | — | UNDETECTED |
| `master/owner_activity_control26_2.php` | R | 10 | 1 | 0 | — | — | UNDETECTED |
| `master/panduan_account_readiness.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_company_bank_accounts.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_customers.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_data.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_departements.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_employees.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_import_customers.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_import_products.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_import_vendors.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_manufactures.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_office.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_payment_terms.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_pricelist.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_pricelist_sell.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_products.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_system_login.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_tax.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/panduan_master_vendors.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/products_media_view.php` | R | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `master/products_media_view_.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `master/rate_limit_policies.php` | CRUD | 1 | 3 | 0 | — | Ya | UNDETECTED |
| `master/scan_product_media_folder.php` | CRU | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `master/schema_mfa.php` | R | 0 | 0 | 1 | — | — | UNDETECTED |
| `master/security.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master/webauthn_api.php` | CRU | 3 | 4 | 0 | — | — | UNDETECTED |

### `sales` — 59 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `sales/act_do_tasks.php` | CRU | 8 | 5 | 8 | Ya | Ya | UNDETECTED |
| `sales/act_do_upload_diagnostic.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `sales/backfill_sales_do_audit_crm.php` | CR | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `sales/backfill_sales_do_audit_stages.php` | CR | 1 | 0 | 4 | — | Ya | UNDETECTED |
| `sales/crm_leads.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/export_kpi_do_csv.php` | R | 9 | 0 | 4 | — | — | UNDETECTED |
| `sales/fin_ar_import.php` | CRUD | 0 | 0 | 2 | — | Ya | UNDETECTED |
| `sales/fin_ar_recap.php` | CRU | 6 | 1 | 3 | — | Ya | UNDETECTED |
| `sales/fin_do_tasks.php` | CRU | 5 | 4 | 5 | Ya | Ya | UNDETECTED |
| `sales/kpi_do_audit.php` | R | 6 | 0 | 4 | — | Ya | UNDETECTED |
| `sales/kpi_do_sla.php` | RU | 3 | 2 | 7 | — | Ya | UNDETECTED |
| `sales/kpi_do_sla_fixed_staff_v6.php` | RU | 3 | 2 | 10 | — | Ya | UNDETECTED |
| `sales/one_time_fix_DO004_commercial_effect.php` | RU | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/one_time_fix_DO010_DO012_replacement_link.php` | RU | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan.php` | — | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `sales/panduan_act_do_tasks.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_control_tower.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_crm_lead_create.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_crm_lead_edit.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_crm_lead_view.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_crm_leads.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_do_tasks.php` | — | 0 | 0 | 3 | — | Ya | UNDETECTED |
| `sales/panduan_fin_do_tasks.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_kpi_do_audit.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_kpi_do_sla.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_sales_control_tower.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_sales_dashboard.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_sales_do.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_sales_do_print_cf.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_sales_do_rekap.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_sales_do_view.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_sales_order.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_scm_do_tasks.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_scm_tracker_mobile.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_scm_tracker_sop.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_tax_invoices.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/panduan_wqs_do_tasks.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/sales_control_tower.php` | RU | 12 | 2 | 8 | — | Ya | UNDETECTED |
| `sales/sales_dashboard.php` | R | 5 | 0 | 8 | — | Ya | UNDETECTED |
| `sales/sales_do.php` | CRUD | 14 | 8 | 12 | — | Ya | UNDETECTED |
| `sales/sales_do_.php` | CRUD | 6 | 9 | 5 | — | Ya | UNDETECTED |
| `sales/sales_do_doc_download.php` | R | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `sales/sales_do_print_cf.php` | R | 1 | 0 | 1 | — | Ya | UNDETECTED |
| `sales/sales_do_rekap.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `sales/sales_do_return.php` | CRUD | 4 | 9 | 9 | — | Ya | UNDETECTED |
| `sales/sales_do_view.php` | R | 3 | 0 | 8 | — | Ya | UNDETECTED |
| `sales/scm_delivery_recap.php` | R | 7 | 0 | 3 | — | Ya | UNDETECTED |
| `sales/scm_do_tasks.php` | CRU | 4 | 6 | 11 | Ya | Ya | UNDETECTED |
| `sales/scm_do_tasks__.php` | RU | 4 | 5 | 10 | Ya | Ya | UNDETECTED |
| `sales/scm_tracker_mobile.php` | R | 1 | 0 | 3 | — | — | UNDETECTED |
| `sales/scm_tracker_sop.php` | — | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `sales/scm_tracking_history.php` | R | 6 | 0 | 2 | — | Ya | UNDETECTED |
| `sales/tax_invoices.php` | CRU | 2 | 4 | 5 | — | Ya | UNDETECTED |
| `sales/tracking_public.php` | R | 2 | 0 | 2 | — | — | UNDETECTED |
| `sales/tracking_public_CARTO.php` | R | 2 | 0 | 2 | — | — | UNDETECTED |
| `sales/tracking_public_Google.php` | R | 2 | 0 | 2 | — | — | UNDETECTED |
| `sales/tracking_public_live.php` | R | 2 | 0 | 1 | — | — | UNDETECTED |
| `sales/wqs_do_tasks.php` | CRU | 1 | 6 | 11 | Ya | Ya | UNDETECTED |

### `dashboards` — 67 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `dashboards/act/act_dashboard.php` | R | 1 | 0 | 3 | — | — | UNDETECTED |
| `dashboards/act/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/act/panduan_act_dashboard.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/branch/branch_dashboard.php` | R | 1 | 0 | 9 | — | — | UNDETECTED |
| `dashboards/branch/branch_dashboardbranch.php` | R | 0 | 0 | 5 | — | — | UNDETECTED |
| `dashboards/branch/depo_dashboard.php` | R | 0 | 0 | 9 | — | — | UNDETECTED |
| `dashboards/branch/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/branch/panduan_branch_dashboard.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/dashboard_center.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/finance/adjustment_manage.php` | CRUD | 2 | 3 | 0 | — | — | UNDETECTED |
| `dashboards/finance/ap_rekap.php` | R | 5 | 0 | 2 | — | Ya | UNDETECTED |
| `dashboards/finance/ap_rekaphutang.php` | R | 4 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/finance/ar_ap_cash_dashboard.php` | R | 1 | 0 | 7 | — | — | UNDETECTED |
| `dashboards/finance/cek_crm_submitted.php` | R | 0 | 0 | 5 | — | — | UNDETECTED |
| `dashboards/finance/dashboard_detail.php` | RU | 13 | 2 | 5 | — | — | UNDETECTED |
| `dashboards/finance/gl_rekap.php` | R | 5 | 0 | 2 | — | — | UNDETECTED |
| `dashboards/finance/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/finance/panduan_ap_rekap.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/finance/panduan_ar_ap_cash_dashboard.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/finance/panduan_cek_crm_submitted.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/finance/panduan_dashboard_detail.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/finance/panduan_gl_rekap.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/finance/panduan_sales_do_rekap.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/finance/panduan_target_rekap.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/finance/reg_alkes_payments.php` | RU | 7 | 0 | 5 | — | Ya | UNDETECTED |
| `dashboards/finance/revbaruslaa.php` | R | 1 | 0 | 7 | — | — | UNDETECTED |
| `dashboards/finance/sales_do_rekap.php` | R | 8 | 0 | 0 | — | Ya | UNDETECTED |
| `dashboards/finance/target_rekap.php` | CRUD | 4 | 3 | 0 | — | — | UNDETECTED |
| `dashboards/funnels.php` | — | 2 | 0 | 2 | — | Ya | UNDETECTED |
| `dashboards/funnelsbaru.php` | R | 2 | 0 | 8 | — | Ya | UNDETECTED |
| `dashboards/funnelsrevnilai.php` | — | 2 | 0 | 6 | — | Ya | UNDETECTED |
| `dashboards/hrl/absensi_manual_alpha.php` | CRU | 2 | 3 | 0 | — | — | UNDETECTED |
| `dashboards/hrl/hrl_dashboard.php` | R | 3 | 0 | 7 | — | — | UNDETECTED |
| `dashboards/hrl/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/hrl/panduan_hrl_dashboard.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/index.php` | R | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/indexrevbaru.php` | R | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/indexrevbaru2509.php` | R | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/itc/itc_dashboard.php` | R | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/itc/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/itc/panduan_itc_dashboard.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/owner/exec_summary.php` | R | 6 | 0 | 16 | — | Ya | UNDETECTED |
| `dashboards/owner/panduan.php` | — | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `dashboards/owner/panduan_exec_summary.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/panduan_dashboard_center.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/panduan_funnels.php` | — | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `dashboards/panduan_funnelsbaru.php` | — | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `dashboards/panduan_index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/procurement/import_po_dashboard.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/procurement/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/procurement/panduan_import_po_dashboard.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/quality/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/quality/panduan_qc_complaint_dashboard.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/quality/qc_complaint_dashboard.php` | R | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/regulatory/license_docs_dashboard.php` | R | 0 | 0 | 4 | — | Ya | UNDETECTED |
| `dashboards/regulatory/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/regulatory/panduan_license_docs_dashboard.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/sales/panduan_sales_dashboard.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/scm/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/scm/panduan_scm_dashboard.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/scm/scm_dashboard.php` | R | 0 | 0 | 15 | — | — | UNDETECTED |
| `dashboards/warehouse/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/warehouse/panduan_wqs_dashboard.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/warehouse/wqs_dashboard.php` | R | 3 | 0 | 4 | — | — | UNDETECTED |
| `dashboards/warehouse/wqs_dashboard_export.php` | RU | 0 | 0 | 0 | — | — | UNDETECTED |
| `dashboards/warehouse/wqs_low_stock_office.php` | R | 3 | 0 | 0 | — | — | UNDETECTED |

### `purchases` — 62 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `purchases/bank_recon.php` | CRU | 2 | 6 | 1 | — | Ya | UNDETECTED |
| `purchases/bank_statement_import.php` | — | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `purchases/fin_gl_auto.php` | CRU | 7 | 2 | 1 | — | Ya | UNDETECTED |
| `purchases/gl_reversal_approvals.php` | RU | 0 | 3 | 3 | — | Ya | UNDETECTED |
| `purchases/panduan.php` | — | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `purchases/panduan_bank_recon.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_bank_statement_import.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_fin_gl_auto.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_gl_reversal_approvals.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_import_tower.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_ceisa_pib.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_ceisa_pib_view.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_dashboard.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_forwarder_invoice.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_forwarder_payment.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_forwarder_quotes.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_forwarding_tasks.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_gr.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_import_control_tower.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_import_control_view.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_invoice_ap.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_invoice_ap_edit.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_payment_ap.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_po.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_po_print.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_po_view.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_purchases_reports.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/panduan_stock_update_from_gr.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/pqp_rfq.php` | CRU | 4 | 1 | 5 | — | Ya | UNDETECTED |
| `purchases/pqp_rfq_download.php` | R | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `purchases/pqp_rfq_export.php` | R | 2 | 0 | 1 | — | Ya | UNDETECTED |
| `purchases/pqp_rfq_helper.php` | CR | 0 | 0 | 0 | — | — | UNDETECTED |
| `purchases/purchases_ap_import.php` | CRUD | 0 | 0 | 2 | — | Ya | UNDETECTED |
| `purchases/purchases_ap_importreplace.php` | C | 0 | 0 | 2 | — | Ya | UNDETECTED |
| `purchases/purchases_ap_importress.php` | CRUD | 0 | 0 | 2 | — | Ya | UNDETECTED |
| `purchases/purchases_ceisa_pib.php` | R | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `purchases/purchases_ceisa_pib_view.php` | CRU | 1 | 4 | 4 | — | Ya | UNDETECTED |
| `purchases/purchases_control_tower.php` | RU | 6 | 1 | 6 | — | Ya | UNDETECTED |
| `purchases/purchases_dashboard.php` | R | 0 | 0 | 13 | — | Ya | UNDETECTED |
| `purchases/purchases_dashboard_.php` | R | 0 | 0 | 7 | — | Ya | UNDETECTED |
| `purchases/purchases_fin_po_process.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `purchases/purchases_forwarder_invoice.php` | CRU | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `purchases/purchases_forwarder_payment.php` | CRU | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `purchases/purchases_forwarder_quotes.php` | CRU | 2 | 0 | 3 | — | Ya | UNDETECTED |
| `purchases/purchases_forwarding_tasks.php` | CRU | 2 | 3 | 8 | — | Ya | UNDETECTED |
| `purchases/purchases_gr.php` | — | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `purchases/purchases_gr_load_items.php` | R | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `purchases/purchases_import_control_tower.php` | R | 7 | 0 | 10 | — | Ya | UNDETECTED |
| `purchases/purchases_import_control_view.php` | CRU | 2 | 7 | 0 | — | Ya | UNDETECTED |
| `purchases/purchases_invoice_ap.php` | CRU | 3 | 0 | 2 | — | Ya | UNDETECTED |
| `purchases/purchases_invoice_ap_edit.php` | RU | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `purchases/purchases_payment_ap.php` | CRU | 2 | 0 | 3 | — | Ya | UNDETECTED |
| `purchases/purchases_po.php` | CRU | 13 | 4 | 7 | Ya | Ya | UNDETECTED |
| `purchases/purchases_po_fin_view.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `purchases/purchases_po_print.php` | R | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `purchases/purchases_po_readonly_view.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `purchases/purchases_po_view.php` | RU | 1 | 0 | 7 | — | Ya | UNDETECTED |
| `purchases/purchases_pr_api.php` | R | 2 | 0 | 0 | — | — | UNDETECTED |
| `purchases/purchases_reports.php` | R | 2 | 0 | 0 | — | Ya | UNDETECTED |
| `purchases/revbaru1.php` | R | 3 | 0 | 8 | — | Ya | UNDETECTED |
| `purchases/stock_update_from_gr.php` | — | 0 | 0 | 0 | — | Ya | UNDETECTED |

### `api` — 229 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `api/_lib/partner_auth.php` | RU | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/acl.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/attachment_download.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/attachment_preview.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/attachment_upload.php` | U | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/attachments/download.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/attachments/preview.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/channel_messages.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/channel_retention.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/channels.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/channels/mark_all_read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/channels/mute.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/channels/read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/channels/unmute.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/channels_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/channels_read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/emojis.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/events.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/export_create.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/export_download.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/exports.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/exports/download.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/mark_all_read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/message_context.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/message_delete.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/message_send.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/messages.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/messages/context.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/mute.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/pin.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/pin_policy.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/pins.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/prefs.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/presence.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/presence/ping.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/presence_get.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/presence_ping.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/reaction.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/read_mark.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/search.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/thread.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/typing.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/unmute.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/chat/users.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/health.php` | R | 0 | 0 | 1 | — | — | UNDETECTED |
| `api/internal/bank_recon_summary.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `api/internal/compliance/reg_alkes_summary.php` | R | 0 | 0 | 1 | — | — | UNDETECTED |
| `api/internal/crm_lead_action.php` | CRU | 0 | 4 | 5 | — | — | UNDETECTED |
| `api/internal/crm_lead_detail.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `api/internal/crm_leads_listing.php` | R | 7 | 0 | 5 | — | — | UNDETECTED |
| `api/internal/crm_leads_summary.php` | R | 2 | 0 | 5 | — | — | UNDETECTED |
| `api/internal/gl_enqueue_posting.php` | CU | 0 | 0 | 1 | — | — | UNDETECTED |
| `api/internal/gl_journal_listing.php` | — | 6 | 0 | 0 | — | — | UNDETECTED |
| `api/internal/gl_ledger.php` | — | 5 | 0 | 0 | — | — | UNDETECTED |
| `api/internal/gl_summary.php` | — | 2 | 0 | 0 | — | — | UNDETECTED |
| `api/internal/gl_trial_balance.php` | — | 6 | 0 | 0 | — | — | UNDETECTED |
| `api/internal/sales_scm_geo_ping.php` | CRU | 0 | 0 | 3 | — | — | UNDETECTED |
| `api/internal/sales_tracking_sync.php` | U | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/internal/tax_invoice_status.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `api/kpi_exec.json.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/auth/login.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/auth/logout.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/auth/me.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/auth/refresh.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/chat/attachment_download.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/chat/attachment_preview.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/chat/attachment_upload.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/chat/channels.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/chat/mark_all_read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/chat/messages.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/chat/mute.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/chat/presence.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/chat/presence/ping.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/chat/presence_get.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/chat/presence_ping.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/chat/read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/chat/search.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/chat/send.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/chat/unmute.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/dashboard/get.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/master/customers.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/master/products.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/master/vendors.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/notifications/list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/notifications/mark_read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/notifications/register_token.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/purchases/ap_invoice_create.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/purchases/ap_invoice_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/purchases/ap_payment_create.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/purchases/ap_payment_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/purchases/po_create.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/purchases/po_detail.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/purchases/po_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/purchases/pr_create.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/purchases/pr_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/sales/do_action.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/sales/do_detail.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/sales/do_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/stock/adjustment_approve.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/stock/adjustment_create.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/stock/audit.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/stock/incoming_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/stock/incoming_receive.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/stock/items.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/stock/opname_apply.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/stock/opname_create.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/stock/opname_detail.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/stock/opname_input_qty.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/stock/opname_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/mobile/tasks/my.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/acl.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/attachment_download.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/attachment_preview.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/attachment_upload.php` | U | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/attachments/download.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/attachments/preview.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/channel_messages.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/channel_retention.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/channels.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/channels/mark_all_read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/channels/mute.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/channels/read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/channels/unmute.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/channels_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/channels_read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/emojis.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/events.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/export_create.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/export_download.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/exports.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/exports/download.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/mark_all_read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/message_context.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/message_delete.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/message_send.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/messages.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/messages/context.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/mute.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/pin.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/pin_policy.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/pins.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/prefs.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/presence.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/presence/ping.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/presence_get.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/presence_ping.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/reaction.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/read_mark.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/search.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/thread.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/typing.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/unmute.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/chat/users.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/health.php` | R | 0 | 0 | 1 | — | — | UNDETECTED |
| `api/v1/internal/bank_recon_summary.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/internal/crm_lead_action.php` | CRU | 0 | 4 | 5 | — | — | UNDETECTED |
| `api/v1/internal/crm_lead_detail.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/internal/crm_leads_listing.php` | R | 7 | 0 | 5 | — | — | UNDETECTED |
| `api/v1/internal/crm_leads_summary.php` | R | 2 | 0 | 5 | — | — | UNDETECTED |
| `api/v1/internal/funnels_summary.php` | — | 3 | 0 | 0 | — | Ya | UNDETECTED |
| `api/v1/internal/funnels_summarybaru.php` | R | 3 | 0 | 8 | — | Ya | UNDETECTED |
| `api/v1/internal/gl_enqueue_posting.php` | CU | 0 | 0 | 1 | — | — | UNDETECTED |
| `api/v1/internal/gl_journal_listing.php` | — | 6 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/internal/gl_ledger.php` | — | 5 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/internal/gl_summary.php` | — | 2 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/internal/gl_trial_balance.php` | — | 6 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/internal/sales_scm_geo_ping.php` | CRU | 0 | 0 | 3 | — | — | UNDETECTED |
| `api/v1/internal/sales_scm_geo_pingbaru.php` | CRU | 0 | 0 | 1 | — | — | UNDETECTED |
| `api/v1/internal/sales_scm_geo_pingbranch.php` | CRU | 0 | 0 | 1 | — | — | UNDETECTED |
| `api/v1/internal/sales_scm_geo_pinglive.php` | CRU | 0 | 0 | 1 | — | — | UNDETECTED |
| `api/v1/internal/sales_scm_geo_pingrevbaru.php` | CRU | 0 | 0 | 1 | — | — | UNDETECTED |
| `api/v1/internal/sales_tracking_sync.php` | U | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/internal/tax_invoice_status.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/auth/login.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/auth/logout.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/auth/me.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/auth/refresh.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/chat/attachment_download.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/chat/attachment_preview.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/chat/attachment_upload.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/chat/channels.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/chat/mark_all_read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/chat/messages.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/chat/mute.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/chat/presence.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/chat/presence/ping.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/chat/presence_get.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/chat/presence_ping.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/chat/read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/chat/search.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/chat/send.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/chat/unmute.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/dashboard/get.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/master/customers.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/master/products.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/master/vendors.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/notifications/list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/notifications/mark_read.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/notifications/register_token.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/purchases/ap_invoice_create.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/purchases/ap_invoice_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/purchases/ap_payment_create.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/purchases/ap_payment_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/purchases/po_create.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/purchases/po_detail.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/purchases/po_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/purchases/pr_create.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/purchases/pr_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/sales/do_action.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/sales/do_detail.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/sales/do_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/stock/adjustment_approve.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/stock/adjustment_create.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/stock/audit.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/stock/incoming_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/stock/incoming_receive.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/stock/items.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/stock/opname_apply.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/stock/opname_create.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/stock/opname_detail.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/stock/opname_input_qty.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/stock/opname_list.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/mobile/tasks/my.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/partner/health.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/v1/partner/order_create.php` | CR | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/webhooks/marketplace_order.php` | CR | 0 | 0 | 0 | — | — | UNDETECTED |
| `api/webhooks/payment_callback.php` | CR | 0 | 0 | 0 | — | — | UNDETECTED |

### `mpr` — 28 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `mpr/mpr_access.php` | R | 0 | 0 | 0 | — | — | UNDETECTED |
| `mpr/mpr_accessbranch.php` | R | 0 | 0 | 0 | — | — | UNDETECTED |
| `mpr/mpr_api_contacts.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `mpr/mpr_budget_fin.php` | RU | 6 | 3 | 4 | — | — | UNDETECTED |
| `mpr/mpr_dashboard.php` | R | 2 | 0 | 1 | — | — | UNDETECTED |
| `mpr/mpr_gps_capture.php` | — | 2 | 0 | 0 | — | — | UNDETECTED |
| `mpr/mpr_ops_daily_fin.php` | R | 3 | 0 | 2 | — | — | UNDETECTED |
| `mpr/mpr_ops_daily_fin_detail.php` | R | 4 | 0 | 2 | — | — | UNDETECTED |
| `mpr/mpr_ops_daily_fin_detail_.php` | R | 4 | 0 | 2 | — | — | UNDETECTED |
| `mpr/mpr_ops_daily_fin_export.php` | R | 4 | 0 | 2 | — | Ya | UNDETECTED |
| `mpr/mpr_ops_daily_fin_pay.php` | CRU | 0 | 0 | 2 | — | — | UNDETECTED |
| `mpr/mpr_pipeline.php` | CRU | 5 | 5 | 0 | — | Ya | UNDETECTED |
| `mpr/mpr_plan_view.php` | CRU | 1 | 8 | 5 | — | Ya | UNDETECTED |
| `mpr/mpr_plan_view_.php` | CRU | 1 | 8 | 5 | — | Ya | UNDETECTED |
| `mpr/mpr_plans.php` | CRU | 5 | 10 | 5 | — | Ya | UNDETECTED |
| `mpr/mpr_plansrev2809.php` | CRU | 5 | 10 | 5 | — | Ya | UNDETECTED |
| `mpr/mpr_visits.php` | CRU | 6 | 7 | 1 | — | Ya | UNDETECTED |
| `mpr/mpr_visitsrev2809.php` | CRU | 6 | 7 | 1 | — | Ya | UNDETECTED |
| `mpr/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `mpr/panduan_index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `mpr/panduan_mpr_budget_fin.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `mpr/panduan_mpr_dashboard.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `mpr/panduan_mpr_ops_daily_fin.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `mpr/panduan_mpr_ops_daily_fin_detail.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `mpr/panduan_mpr_ops_daily_fin_export.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `mpr/panduan_mpr_ops_daily_fin_pay.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `mpr/panduan_mpr_plan_view.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `mpr/panduan_mpr_plans.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |

### `stock` — 36 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `stock/index.php` | — | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `stock/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/panduan_index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/panduan_wqs_allocation.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/panduan_wqs_incoming.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/panduan_wqs_incoming_view.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/panduan_wqs_picking.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/panduan_wqs_picking_view.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/panduan_wqs_pr.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/panduan_wqs_pr_print.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/panduan_wqs_pr_view.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/panduan_wqs_stock.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/panduan_wqs_stock_adjustment.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/panduan_wqs_stock_audit.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/panduan_wqs_stock_opname.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/panduan_wqs_stock_opname_report.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/panduan_wqs_stock_transfer.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `stock/wqs_allocation.php` | CRU | 3 | 3 | 0 | Ya | Ya | UNDETECTED |
| `stock/wqs_do_tasks.php` | CRU | 3 | 7 | 11 | Ya | Ya | UNDETECTED |
| `stock/wqs_incoming.php` | CRU | 4 | 3 | 2 | Ya | Ya | UNDETECTED |
| `stock/wqs_incoming_po_api.php` | R | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `stock/wqs_incoming_view.php` | RU | 1 | 3 | 0 | — | Ya | UNDETECTED |
| `stock/wqs_picking.php` | CRU | 2 | 3 | 1 | Ya | Ya | UNDETECTED |
| `stock/wqs_picking_view.php` | R | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `stock/wqs_pr.php` | CRUD | 2 | 4 | 4 | — | Ya | UNDETECTED |
| `stock/wqs_pr_print.php` | R | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `stock/wqs_pr_view.php` | RU | 2 | 0 | 3 | — | Ya | UNDETECTED |
| `stock/wqs_quarantine.php` | CRU | 3 | 5 | 1 | — | — | UNDETECTED |
| `stock/wqs_stock.php` | CRU | 6 | 4 | 0 | — | Ya | UNDETECTED |
| `stock/wqs_stock_.php` | CRU | 4 | 4 | 0 | — | Ya | UNDETECTED |
| `stock/wqs_stock_adjustment.php` | CRU | 2 | 2 | 0 | Ya | Ya | UNDETECTED |
| `stock/wqs_stock_adjustmentidstok.php` | CRU | 2 | 2 | 0 | Ya | Ya | UNDETECTED |
| `stock/wqs_stock_audit.php` | R | 4 | 0 | 0 | — | Ya | UNDETECTED |
| `stock/wqs_stock_opname.php` | CRU | 2 | 8 | 1 | Ya | Ya | UNDETECTED |
| `stock/wqs_stock_opname_report.php` | R | 7 | 0 | 5 | — | Ya | UNDETECTED |
| `stock/wqs_stock_transfer.php` | CRU | 3 | 4 | 1 | Ya | Ya | UNDETECTED |

### `hrl_process` — 21 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `hrl_process/debug_access.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_process/debug_accessmgr.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_process/download.php` | R | 2 | 0 | 0 | — | — | UNDETECTED |
| `hrl_process/employee_mutation_apply_due.php` | R | 0 | 0 | 1 | — | — | UNDETECTED |
| `hrl_process/employee_mutation_view.php` | RU | 1 | 6 | 5 | — | — | UNDETECTED |
| `hrl_process/employee_mutations.php` | CRU | 2 | 2 | 6 | — | — | UNDETECTED |
| `hrl_process/gps_debug.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_process/index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_process/indexmgr.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_process/leave_adjustment.php` | CRU | 4 | 3 | 1 | — | — | UNDETECTED |
| `hrl_process/leave_adjustmentedit.php` | CRUD | 3 | 3 | 1 | — | — | UNDETECTED |
| `hrl_process/my_pin.php` | CRU | 0 | 1 | 0 | — | — | UNDETECTED |
| `hrl_process/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_process/panduan_index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_process/panduan_my_pin.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_process/panduan_request_print.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_process/panduan_request_view.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_process/panduan_tower.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_process/request_print.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `hrl_process/request_view.php` | CRUD | 1 | 11 | 4 | — | — | UNDETECTED |
| `hrl_process/tower.php` | CRU | 5 | 2 | 7 | — | — | UNDETECTED |

### `absensi` — 40 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `absensi/admin.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/api/rekap_dt.php` | R | 11 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/api/rekap_dtbaru.php` | R | 11 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/api/rekap_dtrevbaru.php` | R | 11 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/approval.php` | RU | 0 | 1 | 3 | — | — | UNDETECTED |
| `absensi/admin/broadcast.php` | R | 3 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/kiosk_poster.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/late_penalty_settings.php` | CRUD | 0 | 4 | 0 | — | — | UNDETECTED |
| `absensi/admin/offices.php` | RU | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/panduan_approval.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/panduan_offices.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/panduan_payroll_gate.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/panduan_pins.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/panduan_rekap.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/panduan_users.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/payroll_gate.php` | R | 3 | 0 | 1 | — | — | UNDETECTED |
| `absensi/admin/pins.php` | RU | 0 | 1 | 0 | — | — | UNDETECTED |
| `absensi/admin/rekap.php` | R | 7 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/rekaprev2809.php` | R | 7 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/settings.php` | CU | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/shifts.php` | CRUD | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/admin/users.php` | CRU | 7 | 0 | 4 | — | — | UNDETECTED |
| `absensi/approval.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/checkin.php` | CU | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/checkout.php` | CU | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/history.php` | R | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/index.php` | R | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/izin.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/kiosk.php` | CRU | 1 | 0 | 0 | — | — | UNDETECTED |
| `absensi/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/panduan_admin.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/panduan_approval.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/panduan_checkin.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/panduan_checkout.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/panduan_history.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/panduan_index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/panduan_izin.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/panduan_request.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `absensi/photo.php` | — | 1 | 0 | 0 | — | — | UNDETECTED |
| `absensi/request.php` | CRU | 0 | 0 | 1 | — | — | UNDETECTED |

### `kpi` — 20 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `kpi/kpi_audit.php` | R | 6 | 0 | 0 | — | — | UNDETECTED |
| `kpi/kpi_auditaudit.php` | R | 4 | 0 | 0 | — | — | UNDETECTED |
| `kpi/kpi_auditbaru.php` | R | 4 | 0 | 0 | — | — | UNDETECTED |
| `kpi/kpi_center.php` | — | 0 | 0 | 1 | — | — | UNDETECTED |
| `kpi/kpi_dashboard_daily.php` | R | 2 | 0 | 3 | — | Ya | UNDETECTED |
| `kpi/kpi_dashboard_monthly.php` | R | 2 | 0 | 3 | — | Ya | UNDETECTED |
| `kpi/kpi_do_audit.php` | R | 8 | 0 | 3 | — | Ya | UNDETECTED |
| `kpi/kpi_do_auditaudit.php` | R | 5 | 0 | 0 | — | Ya | UNDETECTED |
| `kpi/kpi_do_sla.php` | CRU | 3 | 1 | 14 | — | — | UNDETECTED |
| `kpi/kpi_employee.php` | CRUD | 9 | 1 | 1 | — | — | UNDETECTED |
| `kpi/kpi_lib.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `kpi/kpi_office.php` | CRUD | 8 | 1 | 1 | — | — | UNDETECTED |
| `kpi/kpi_purchases.php` | CRUD | 5 | 1 | 1 | — | — | UNDETECTED |
| `kpi/kpi_snapshot.php` | CRU | 6 | 4 | 5 | — | — | UNDETECTED |
| `kpi/kpi_stock.php` | CRUD | 5 | 1 | 1 | — | — | UNDETECTED |
| `kpi/kpi_sync.php` | RU | 0 | 1 | 0 | — | — | UNDETECTED |
| `kpi/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `kpi/panduan_index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `kpi/panduan_kpi_dashboard_daily.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `kpi/panduan_kpi_dashboard_monthly.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |

### `payroll` — 21 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `payroll/audit.php` | R | 6 | 1 | 0 | — | — | UNDETECTED |
| `payroll/index.php` | CRU | 2 | 1 | 2 | — | — | UNDETECTED |
| `payroll/loans.php` | CRUD | 5 | 4 | 1 | — | — | UNDETECTED |
| `payroll/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `payroll/panduan_audit.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `payroll/panduan_index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `payroll/panduan_loans.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `payroll/panduan_payroll_run.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `payroll/panduan_payroll_settings.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `payroll/panduan_payslip.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `payroll/panduan_salary_matrix.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `payroll/panduanpaid.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `payroll/payroll_absensi_manual_fix.php` | RU | 1 | 1 | 0 | — | — | UNDETECTED |
| `payroll/payroll_calendar.php` | CRU | 2 | 3 | 0 | — | — | UNDETECTED |
| `payroll/payroll_fix_run_from_settings.php` | RU | 2 | 0 | 0 | — | — | UNDETECTED |
| `payroll/payroll_repair_run_matrix_absensi.php` | RU | 2 | 0 | 0 | — | — | UNDETECTED |
| `payroll/payroll_run.php` | CRU | 1 | 12 | 4 | — | — | UNDETECTED |
| `payroll/payroll_settings.php` | CRU | 3 | 1 | 0 | — | — | UNDETECTED |
| `payroll/payroll_sync_helpers.php` | R | 0 | 0 | 2 | — | — | UNDETECTED |
| `payroll/payslip.php` | R | 1 | 0 | 3 | — | — | UNDETECTED |
| `payroll/salary_matrix.php` | CRUD | 7 | 1 | 0 | — | — | UNDETECTED |

### `Fixed_Asset` — 14 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `Fixed_Asset/assets.php` | CRU | 8 | 2 | 3 | — | — | UNDETECTED |
| `Fixed_Asset/assets_receive.php` | CRU | 0 | 1 | 0 | — | — | UNDETECTED |
| `Fixed_Asset/audit.php` | CRU | 2 | 1 | 2 | — | — | UNDETECTED |
| `Fixed_Asset/depreciation.php` | CRUD | 1 | 1 | 0 | — | — | UNDETECTED |
| `Fixed_Asset/index.php` | R | 0 | 0 | 1 | — | — | UNDETECTED |
| `Fixed_Asset/ops.php` | CRU | 1 | 1 | 0 | — | — | UNDETECTED |
| `Fixed_Asset/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `Fixed_Asset/panduan_assets.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `Fixed_Asset/panduan_audit.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `Fixed_Asset/panduan_depreciation.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `Fixed_Asset/panduan_index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `Fixed_Asset/panduan_ops.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `Fixed_Asset/panduan_tax_annual.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `Fixed_Asset/tax_annual.php` | R | 5 | 0 | 0 | — | — | UNDETECTED |

### `hrl` — 13 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `hrl/hr_report_center.php` | R | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl/hrl_ack_report.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `hrl/hrl_doc_download.php` | R | 1 | 0 | 1 | — | — | UNDETECTED |
| `hrl/hrl_doc_view.php` | CRU | 2 | 8 | 5 | — | — | UNDETECTED |
| `hrl/hrl_docs.php` | CRU | 6 | 9 | 2 | — | — | UNDETECTED |
| `hrl/hrl_tower.php` | CRU | 4 | 6 | 4 | — | — | UNDETECTED |
| `hrl/panduan.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl/panduan_hrl_ack_report.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl/panduan_hrl_doc_view.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl/panduan_hrl_docs.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl/panduan_hrl_proses.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl/panduan_hrl_tower.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl/panduan_index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |

### `hrl_reg_alkes` — 15 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `hrl_reg_alkes/index.php` | R | 0 | 0 | 4 | — | Ya | UNDETECTED |
| `hrl_reg_alkes/panduan.php` | — | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `hrl_reg_alkes/panduan_index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_reg_alkes/panduan_reg_alkes.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_reg_alkes/panduan_reg_alkes_case.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_reg_alkes/panduan_reg_alkes_control_tower.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_reg_alkes/panduan_reg_alkes_expiry_check.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_reg_alkes/panduan_reg_alkes_export_compliance.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_reg_alkes/panduan_reg_alkes_sku_by_nie.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `hrl_reg_alkes/reg_alkes.php` | CRU | 6 | 1 | 0 | — | Ya | UNDETECTED |
| `hrl_reg_alkes/reg_alkes_case.php` | CRU | 3 | 7 | 5 | — | Ya | UNDETECTED |
| `hrl_reg_alkes/reg_alkes_control_tower.php` | CRU | 6 | 8 | 2 | — | Ya | UNDETECTED |
| `hrl_reg_alkes/reg_alkes_expiry_check.php` | R | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `hrl_reg_alkes/reg_alkes_export_compliance.php` | R | 3 | 0 | 0 | — | Ya | UNDETECTED |
| `hrl_reg_alkes/reg_alkes_sku_by_nie.php` | R | 1 | 0 | 0 | — | Ya | UNDETECTED |

### `manufacturer_portal` — 8 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `manufacturer_portal/case_detail.php` | CRU | 1 | 3 | 1 | — | — | UNDETECTED |
| `manufacturer_portal/cases.php` | R | 0 | 0 | 0 | — | — | UNDETECTED |
| `manufacturer_portal/index.php` | R | 0 | 0 | 1 | — | — | UNDETECTED |
| `manufacturer_portal/layout.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `manufacturer_portal/login.php` | RU | 1 | 0 | 0 | — | — | UNDETECTED |
| `manufacturer_portal/logout.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `manufacturer_portal/manufacture_docs.php` | CRU | 0 | 2 | 1 | — | — | UNDETECTED |
| `manufacturer_portal/rfq.php` | CRU | 1 | 1 | 3 | — | — | UNDETECTED |

### `customer_portal` — 11 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `customer_portal/cart.php` | U | 0 | 4 | 0 | — | — | UNDETECTED |
| `customer_portal/catalog.php` | RU | 1 | 0 | 0 | — | — | UNDETECTED |
| `customer_portal/checkout.php` | CRU | 0 | 0 | 0 | — | — | UNDETECTED |
| `customer_portal/do_print.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `customer_portal/download_doc.php` | R | 1 | 0 | 0 | — | — | UNDETECTED |
| `customer_portal/index.php` | R | 0 | 0 | 4 | — | — | UNDETECTED |
| `customer_portal/layout.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `customer_portal/login.php` | RU | 1 | 0 | 0 | — | — | UNDETECTED |
| `customer_portal/logout.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `customer_portal/order_detail.php` | CRU | 2 | 2 | 4 | — | — | UNDETECTED |
| `customer_portal/orders.php` | R | 3 | 0 | 4 | — | — | UNDETECTED |

### `(root)` — 9 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `bootstrap.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `config-db.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `config.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `db.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `helpers.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `master_product_resolver.php` | R | 0 | 0 | 0 | — | — | UNDETECTED |
| `master_system_config.php` | CRUD | 3 | 1 | 0 | — | — | UNDETECTED |
| `test_all_pages.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |

### `rbac` — 5 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `rbac/index.php` | CRUD | 7 | 28 | 1 | — | Ya | UNDETECTED |
| `rbac/nav_parallel_report.php` | U | 0 | 1 | 0 | — | Ya | UNDETECTED |
| `rbac/panduan.php` | — | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `rbac/panduan_index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `rbac/panduan_v1_legacy.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |

### `web` — 5 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `web/admin/ops/thresholds_edit.php` | U | 0 | 2 | 0 | — | Ya | UNDETECTED |
| `web/admin/ops/thresholds_view.php` | — | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `web/admin/rfc/approve.php` | U | 1 | 0 | 2 | — | Ya | UNDETECTED |
| `web/admin/rfc/index.php` | — | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `web/admin/rfc/view.php` | — | 1 | 0 | 0 | — | Ya | UNDETECTED |

### `chat` — 11 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `chat/admin/audit.php` | R | 4 | 0 | 0 | — | Ya | UNDETECTED |
| `chat/admin/channels.php` | CRUD | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `chat/admin/panduan_audit.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `chat/admin/panduan_channels.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `chat/admin_exports.php` | R | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `chat/admin_settings.php` | CRU | 0 | 0 | 0 | — | Ya | UNDETECTED |
| `chat/index.php` | U | 1 | 0 | 0 | — | Ya | UNDETECTED |
| `chat/panduan_admin_exports.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `chat/panduan_admin_settings.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `chat/panduan_index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `chat/views/index.php` | R | 0 | 0 | 1 | — | — | UNDETECTED |

### `config` — 6 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `config/doc_numbering.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `config/google_maps.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `config/page_registry.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `config/page_registry_panduan_generated.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `config/rbac_legacy_merge_map.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `config/rbac_permissions.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |

### `tests` — 6 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `tests/AuditActorTest.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `tests/BackupManifestTest.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `tests/HealthEndpointTest.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `tests/UserLifecycleTest.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `tests/bootstrap.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |
| `tests/run.php` | CRD | 0 | 0 | 3 | — | — | UNDETECTED |

### `views` — 1 halaman

| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |
|---|---|---:|---:|---:|:-:|:-:|---|
| `views/chat/index.php` | — | 0 | 0 | 0 | — | — | UNDETECTED |

## 6. Lampiran — Detail Field per Halaman

Hanya halaman yang punya filter / aksi / workflow / permission, agar file tidak
didominasi halaman kosong.

#### `Fixed_Asset/assets.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `_csrf`, `a`, `cat`, `dept`, `id`, `office`, `q`, `status`
- **Aksi** `_action`, `bulk_action`
- **State** `CANCELLED`, `DRAFT`, `Draft`

#### `Fixed_Asset/assets_receive.php`

- **CRUD** CRU · **Auth gate** — · **Audit** —
- **Aksi** `action`

#### `Fixed_Asset/audit.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `a`, `id`
- **Aksi** `_action`
- **State** `CLOSED`, `OPEN`

#### `Fixed_Asset/depreciation.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `ym`
- **Aksi** `_action`

#### `Fixed_Asset/index.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **State** `OPEN`

#### `Fixed_Asset/ops.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `tab`
- **Aksi** `_action`

#### `Fixed_Asset/tax_annual.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `check`, `dept_code`, `export`, `office_code`, `year`

#### `absensi/admin/api/rekap_dt.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `dept`, `draw`, `from`, `length`, `office`, `order`, `search`, `start`, `status`, `std_time`, `to`

#### `absensi/admin/api/rekap_dtbaru.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `dept`, `draw`, `from`, `length`, `office`, `order`, `search`, `start`, `status`, `std_time`, `to`

#### `absensi/admin/api/rekap_dtrevbaru.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `dept`, `draw`, `from`, `length`, `office`, `order`, `search`, `start`, `status`, `std_time`, `to`

#### `absensi/admin/approval.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Aksi** `act`
- **State** `APPROVED`, `PENDING`, `REJECTED`

#### `absensi/admin/broadcast.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `dept`, `office`, `status`

#### `absensi/admin/kiosk_poster.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `office`

#### `absensi/admin/late_penalty_settings.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Aksi** `action`, `add`, `delete`, `save_all`

#### `absensi/admin/payroll_gate.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `export`, `from`, `to`
- **State** `APPROVED`

#### `absensi/admin/pins.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Aksi** `op`

#### `absensi/admin/rekap.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `dept`, `export`, `from`, `office`, `status`, `tab`, `to`

#### `absensi/admin/rekaprev2809.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `dept`, `export`, `from`, `office`, `status`, `tab`, `to`

#### `absensi/admin/users.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `dept`, `hr_admin`, `month`, `office`, `q`, `role`, `setup`
- **State** `APPROVED`, `PENDING`, `approved`, `pending`

#### `absensi/kiosk.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `token`

#### `absensi/photo.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Filter** `f`

#### `absensi/request.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **State** `PENDING`

#### `api/health.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **State** `PENDING`

#### `api/internal/bank_recon_summary.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `period`

#### `api/internal/compliance/reg_alkes_summary.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **State** `OPEN`

#### `api/internal/crm_lead_action.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Aksi** `approve`, `cancel`, `close`, `submit`
- **State** `APPROVED`, `CANCELLED`, `CLOSED`, `DRAFT`, `SUBMITTED`

#### `api/internal/crm_lead_detail.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `id`

#### `api/internal/crm_leads_listing.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`, `page`, `per_page`, `priority`, `q`, `status`
- **State** `APPROVED`, `CANCELLED`, `CLOSED`, `DRAFT`, `SUBMITTED`

#### `api/internal/crm_leads_summary.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`
- **State** `APPROVED`, `CANCELLED`, `CLOSED`, `DRAFT`, `SUBMITTED`

#### `api/internal/gl_enqueue_posting.php`

- **CRUD** CU · **Auth gate** Ya · **Audit** —
- **State** `PENDING`

#### `api/internal/gl_journal_listing.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`, `page`, `per_page`, `source_module`, `source_ref`

#### `api/internal/gl_ledger.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Filter** `account_id`, `date_from`, `date_to`, `page`, `per_page`

#### `api/internal/gl_summary.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`

#### `api/internal/gl_trial_balance.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Filter** `account_type`, `date_from`, `date_to`, `page`, `per_page`, `search`

#### `api/internal/sales_scm_geo_ping.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **State** `delivered`, `on_delivery`, `ready_scm`

#### `api/internal/tax_invoice_status.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `sales_ref`

#### `api/v1/health.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **State** `PENDING`

#### `api/v1/internal/bank_recon_summary.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `period`

#### `api/v1/internal/crm_lead_action.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Aksi** `approve`, `cancel`, `close`, `submit`
- **State** `APPROVED`, `CANCELLED`, `CLOSED`, `DRAFT`, `SUBMITTED`

#### `api/v1/internal/crm_lead_detail.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `id`

#### `api/v1/internal/crm_leads_listing.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`, `page`, `per_page`, `priority`, `q`, `status`
- **State** `APPROVED`, `CANCELLED`, `CLOSED`, `DRAFT`, `SUBMITTED`

#### `api/v1/internal/crm_leads_summary.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`
- **State** `APPROVED`, `CANCELLED`, `CLOSED`, `DRAFT`, `SUBMITTED`

#### `api/v1/internal/funnels_summary.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`, `funnel`
- **Permission** `DASHBOARD.OWNER_SUMMARY`, `DASHBOARD.OWNER_VIEW`, `DASHBOARD.SALES_VIEW`, `DASHBOARD.VIEW`

#### `api/v1/internal/funnels_summarybaru.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`, `funnel`
- **State** `APPROVED`, `CANCELLED`, `CLOSED`, `DRAFT`, `OPEN`, `READY`, `SUBMITTED`, `paid`
- **Permission** `DASHBOARD.OWNER_SUMMARY`, `DASHBOARD.OWNER_VIEW`, `DASHBOARD.SALES_VIEW`, `DASHBOARD.VIEW`

#### `api/v1/internal/gl_enqueue_posting.php`

- **CRUD** CU · **Auth gate** Ya · **Audit** —
- **State** `PENDING`

#### `api/v1/internal/gl_journal_listing.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`, `page`, `per_page`, `source_module`, `source_ref`

#### `api/v1/internal/gl_ledger.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Filter** `account_id`, `date_from`, `date_to`, `page`, `per_page`

#### `api/v1/internal/gl_summary.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`

#### `api/v1/internal/gl_trial_balance.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Filter** `account_type`, `date_from`, `date_to`, `page`, `per_page`, `search`

#### `api/v1/internal/sales_scm_geo_ping.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **State** `closed`, `completed`, `on_delivery`

#### `api/v1/internal/sales_scm_geo_pingbaru.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **State** `on_delivery`

#### `api/v1/internal/sales_scm_geo_pingbranch.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **State** `on_delivery`

#### `api/v1/internal/sales_scm_geo_pinglive.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **State** `on_delivery`

#### `api/v1/internal/sales_scm_geo_pingrevbaru.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **State** `on_delivery`

#### `api/v1/internal/tax_invoice_status.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `sales_ref`

#### `chat/admin/audit.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `actor`, `channel_id`, `date_from`, `date_to`
- **Permission** `CHAT.ADMIN_SETTINGS`, `SYSTEM.CONFIG_MANAGE`

#### `chat/admin/channels.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Permission** `CHAT.ADMIN_SETTINGS`, `SYSTEM.CONFIG_MANAGE`

#### `chat/admin_exports.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Permission** `CHAT.ADMIN_SETTINGS`, `SYSTEM.CONFIG_MANAGE`

#### `chat/admin_settings.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Permission** `CHAT.ADMIN_SETTINGS`, `SYSTEM.CONFIG_MANAGE`

#### `chat/index.php`

- **CRUD** U · **Auth gate** Ya · **Audit** —
- **Filter** `context`
- **Permission** `CHAT.VIEW`

#### `chat/views/index.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **State** `Ready`

#### `customer_portal/cart.php`

- **CRUD** U · **Auth gate** — · **Audit** —
- **Aksi** `action`, `clear`, `remove`, `update`

#### `customer_portal/catalog.php`

- **CRUD** RU · **Auth gate** — · **Audit** —
- **Filter** `q`

#### `customer_portal/do_print.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **Filter** `id`

#### `customer_portal/download_doc.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **Filter** `id`

#### `customer_portal/index.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **State** `CANCELLED`, `OPEN`, `Open`, `PAID`

#### `customer_portal/login.php`

- **CRUD** RU · **Auth gate** — · **Audit** —
- **Filter** `next`

#### `customer_portal/order_detail.php`

- **CRUD** CRU · **Auth gate** — · **Audit** —
- **Filter** `created`, `id`
- **Aksi** `action`, `upload_doc`
- **State** `CANCELLED`, `OPEN`, `Open`, `PAID`

#### `customer_portal/orders.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **Filter** `date_from`, `date_to`, `status`
- **State** `CANCELLED`, `OPEN`, `Open`, `PAID`

#### `dashboards/act/act_dashboard.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `m`
- **State** `DRAFT`, `PENDING`, `delivered`

#### `dashboards/branch/branch_dashboard.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `office`
- **State** `DRAFT`, `SUBMITTED`, `cancelled`, `draft`, `on_delivery`, `paid`, `ready_scm`, `rejected`, `void`

#### `dashboards/branch/branch_dashboardbranch.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **State** `CANCELLED`, `OPEN`, `PAID`, `PENDING`, `SUBMITTED`

#### `dashboards/branch/depo_dashboard.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **State** `cancelled`, `closed`, `delivered`, `draft`, `on_delivery`, `paid`, `ready_scm`, `rejected`, `void`

#### `dashboards/finance/adjustment_manage.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `month`, `year`
- **Aksi** `action`, `add`, `delete`

#### `dashboards/finance/ap_rekap.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `as_of`, `office`, `q`, `source`, `status`
- **State** `CANCELLED`, `PAID`
- **Permission** `DASHBOARD.FINANCE_VIEW`, `PURCHASES.AP_INVOICE_VIEW`, `PURCHASES.AP_PAYMENT_VIEW`, `PURCHASES.REPORTS_VIEW`, `PURCHASES.VIEW`

#### `dashboards/finance/ap_rekaphutang.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `as_of`, `month`, `office`, `year`

#### `dashboards/finance/ar_ap_cash_dashboard.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `m`
- **State** `CANCELLED`, `DRAFT`, `PAID`, `POSTED`, `SUBMITTED`, `closed`, `paid`

#### `dashboards/finance/cek_crm_submitted.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **State** `closed`, `delivered`, `on_delivery`, `paid`, `ready_scm`

#### `dashboards/finance/dashboard_detail.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Filter** `anomaly_threshold`, `as_of`, `drilldown`, `export`, `kiosk`, `month`, `motion`, `office`, `refresh`, `segment`, `strict`, `tab`, `year`
- **Aksi** `action`, `approve_anomaly`
- **State** `approved`, `cancelled`, `draft`, `rejected`, `void`

#### `dashboards/finance/gl_rekap.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `as_of`, `category`, `month`, `office`, `year`
- **State** `DRAFT`, `POSTED`

#### `dashboards/finance/reg_alkes_payments.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Filter** `finreg_cmd`, `note`, `paid_date`, `payment_id`, `payment_status`, `q`, `status`
- **State** `CANCELLED`, `OPEN`, `PAID`, `Paid`, `SUBMITTED`
- **Permission** `DASHBOARD.FINANCE_VIEW`, `FIN.REG_ALKES_PAYMENT`, `FIN.REG_ALKES_PAYMENT_PAY`, `FIN.VIEW`, `SYS.VIEW`

#### `dashboards/finance/revbaruslaa.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `m`
- **State** `CANCELLED`, `DRAFT`, `PAID`, `POSTED`, `SUBMITTED`, `closed`, `paid`

#### `dashboards/finance/sales_do_rekap.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `all_period`, `as_of`, `month`, `office`, `revenue_only`, `segment`, `type`, `year`
- **Permission** `DASHBOARD.SALES_VIEW`, `SALES.DASHBOARD_VIEW`, `SALES.VIEW`

#### `dashboards/finance/target_rekap.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `month`, `office`, `segment`, `year`
- **Aksi** `action`, `delete_target`, `save_target`

#### `dashboards/funnels.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`
- **State** `CANCELLED`, `paid`
- **Permission** `DASHBOARD.OWNER_SUMMARY`, `DASHBOARD.OWNER_VIEW`, `DASHBOARD.SALES_VIEW`, `DASHBOARD.VIEW`

#### `dashboards/funnelsbaru.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`
- **State** `APPROVED`, `CANCELLED`, `CLOSED`, `DRAFT`, `OPEN`, `READY`, `SUBMITTED`, `paid`
- **Permission** `DASHBOARD.OWNER_SUMMARY`, `DASHBOARD.OWNER_VIEW`, `DASHBOARD.SALES_VIEW`, `DASHBOARD.VIEW`

#### `dashboards/funnelsrevnilai.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`
- **State** `APPROVED`, `CANCELLED`, `CLOSED`, `DRAFT`, `SUBMITTED`, `paid`
- **Permission** `DASHBOARD.OWNER_SUMMARY`, `DASHBOARD.OWNER_VIEW`, `DASHBOARD.SALES_VIEW`, `DASHBOARD.VIEW`

#### `dashboards/hrl/absensi_manual_alpha.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `d`, `office`
- **Aksi** `action`, `delete`, `save`

#### `dashboards/hrl/hrl_dashboard.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `d`, `dept`, `office`
- **State** `APPROVED`, `CLOSED`, `COMPLETED`, `DRAFT`, `PENDING`, `SUBMITTED`, `pending`

#### `dashboards/owner/exec_summary.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `as_of`, `expected_mtd`, `m`, `office`, `recon`, `refresh`
- **State** `APPROVED`, `CLOSED`, `DRAFT`, `OPEN`, `POSTED`, `READY`, `cancelled`, `closed`, `completed`, `delivered`, `draft`, `on_delivery`, `paid`, `ready_scm`, `rejected`, `void`
- **Permission** `DASHBOARD.OWNER_SUMMARY`

#### `dashboards/owner/panduan.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Permission** `DASHBOARD.OWNER_SUMMARY`

#### `dashboards/panduan_funnels.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Permission** `DASHBOARD.OWNER_SUMMARY`, `DASHBOARD.OWNER_VIEW`, `DASHBOARD.SALES_VIEW`, `DASHBOARD.VIEW`

#### `dashboards/panduan_funnelsbaru.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Permission** `DASHBOARD.OWNER_SUMMARY`, `DASHBOARD.OWNER_VIEW`, `DASHBOARD.SALES_VIEW`, `DASHBOARD.VIEW`

#### `dashboards/regulatory/license_docs_dashboard.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **State** `CLOSED`, `OPEN`, `closed`, `open`
- **Permission** `DASHBOARD.OWNER_SUMMARY`, `HRL.REG_ALKES_VIEW`, `MASTER.PRODUCT_VIEW`, `PQP.REGULATORY_VIEW`, `PQP.VIEW`, `WQS.VIEW`

#### `dashboards/scm/scm_dashboard.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **State** `CANCELLED`, `CLOSED`, `COMPLETED`, `DELIVERED`, `OPEN`, `PAID`, `READY`, `REJECTED`, `SUBMITTED`, `VOID`, `closed`, `completed`, `delivered`, `on_delivery`, `ready_scm`

#### `dashboards/warehouse/wqs_dashboard.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `from`, `range`, `to`
- **State** `DRAFT`, `OPEN`, `SUBMITTED`, `ready_scm`

#### `dashboards/warehouse/wqs_low_stock_office.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `office`, `q`, `threshold`

#### `hrl/hrl_ack_report.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **Filter** `unit`

#### `hrl/hrl_doc_download.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `vid`
- **State** `APPROVED`

#### `hrl/hrl_doc_view.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `code`, `id`
- **Aksi** `ack`, `action`, `approve_version`, `mark_obsolete`, `reject_version`, `set_active`, `submit_version`, `upload_version`
- **State** `APPROVED`, `DRAFT`, `REJECTED`, `Rejected`, `SUBMITTED`

#### `hrl/hrl_docs.php`

- **CRUD** CRU · **Auth gate** — · **Audit** —
- **Filter** `category`, `deleted`, `edit`, `scope`, `status`, `unit`
- **Aksi** `action`, `bulk`, `bulk_action`, `create_doc`, `import_csv`, `import_zip`, `restore`, `soft_delete`, `update_doc`
- **State** `APPROVED`, `DRAFT`

#### `hrl/hrl_tower.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `dept`, `q`, `unit`, `vstatus`
- **Aksi** `action`, `approve_version`, `reject_version`, `request_new`, `set_active`, `upload_revision`
- **State** `APPROVED`, `DRAFT`, `REJECTED`, `SUBMITTED`

#### `hrl_process/download.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `file_id`, `inline`

#### `hrl_process/employee_mutation_apply_due.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **State** `APPROVED`

#### `hrl_process/employee_mutation_view.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **Aksi** `action`, `approve`, `cancel`, `effective`, `reject`, `submit`
- **State** `APPROVED`, `CANCELLED`, `DRAFT`, `REJECTED`, `SUBMITTED`

#### `hrl_process/employee_mutations.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `q`, `status`
- **Aksi** `action`, `create`
- **State** `APPROVED`, `CANCELLED`, `DRAFT`, `REJECTED`, `SUBMITTED`, `draft`

#### `hrl_process/leave_adjustment.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `edit_employee_id`, `office`, `q`, `year`
- **Aksi** `action`, `resync_balance`, `save_adjustment`
- **State** `PAID`

#### `hrl_process/leave_adjustmentedit.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `office`, `q`, `year`
- **Aksi** `action`, `resync_balance`, `save_adjustment`
- **State** `PAID`

#### `hrl_process/my_pin.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Aksi** `action`

#### `hrl_process/request_print.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `id`

#### `hrl_process/request_view.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **Aksi** `action`, `approve_fin`, `approve_hrl`, `approve_mgr`, `mark_paid`, `need_document`, `reject`, `save`, `sick_contact`, `soft_delete`, `submit`
- **State** `DRAFT`, `PAID`, `REJECTED`, `SUBMITTED`

#### `hrl_process/tower.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `q`, `req_type`, `show_deleted`, `status`, `type`
- **Aksi** `action`, `sick_contact`
- **State** `DRAFT`, `Draft`, `PAID`, `REJECTED`, `SUBMITTED`, `Submitted`, `draft`

#### `hrl_reg_alkes/index.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **State** `CLOSED`, `OPEN`, `closed`, `open`
- **Permission** `HRL.REG_ALKES_VIEW`, `HRL.VIEW`, `PQP.VIEW`

#### `hrl_reg_alkes/panduan.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Permission** `HRL.REG_ALKES_VIEW`, `HRL.VIEW`, `PQP.VIEW`

#### `hrl_reg_alkes/reg_alkes.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `akl_no_q`, `case`, `code`, `csrf_token`, `dl`, `t`
- **Aksi** `action`
- **Permission** `HRL.REG_ALKES_VIEW`, `HRL.VIEW`, `PQP.VIEW`

#### `hrl_reg_alkes/reg_alkes_case.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `dl`, `dl_manu`, `id`
- **Aksi** `action`, `close_case`, `next_stage`, `reopen_case`, `save_payment`, `update_case`, `upload_doc`
- **State** `CANCELLED`, `CLOSED`, `OPEN`, `PAID`, `SUBMITTED`
- **Permission** `HRL.REG_ALKES_VIEW`, `HRL.VIEW`, `PQP.VIEW`

#### `hrl_reg_alkes/reg_alkes_control_tower.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `created`, `created_id`, `manu`, `q`, `stage`, `status`
- **Aksi** `action`, `create_case`, `g`, `k`, `m`, `quick_close`, `quick_next`, `quick_reopen`
- **State** `CLOSED`, `OPEN`
- **Permission** `HRL.REG_ALKES_VIEW`, `HRL.VIEW`, `PQP.VIEW`

#### `hrl_reg_alkes/reg_alkes_expiry_check.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Permission** `HRL.REG_ALKES_VIEW`, `HRL.VIEW`, `PQP.VIEW`

#### `hrl_reg_alkes/reg_alkes_export_compliance.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `format`, `from`, `to`
- **Permission** `HRL.COMPLIANCE_EXPORT`, `HRL.REG_ALKES_VIEW`, `HRL.VIEW`, `PQP.VIEW`

#### `hrl_reg_alkes/reg_alkes_sku_by_nie.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **Permission** `HRL.REG_ALKES_VIEW`, `HRL.VIEW`, `PQP.VIEW`

#### `kpi/kpi_audit.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **Filter** `a`, `export`, `from`, `m`, `to`, `u`

#### `kpi/kpi_auditaudit.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **Filter** `a`, `export`, `m`, `u`

#### `kpi/kpi_auditbaru.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **Filter** `a`, `export`, `m`, `u`

#### `kpi/kpi_center.php`

- **CRUD** — · **Auth gate** — · **Audit** —
- **State** `READY`

#### `kpi/kpi_dashboard_daily.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `d`, `office`
- **State** `DRAFT`, `OPEN`, `SUBMITTED`
- **Permission** `DASHBOARD.KPI_VIEW`, `KPI.VIEW`

#### `kpi/kpi_dashboard_monthly.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `m`, `office`
- **State** `DRAFT`, `OPEN`, `SUBMITTED`
- **Permission** `DASHBOARD.KPI_VIEW`, `KPI.VIEW`

#### `kpi/kpi_do_audit.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **Filter** `actor`, `dept`, `do_code`, `do_id`, `export`, `month`, `office`, `type`
- **State** `REVISION`, `revision`, `void`
- **Permission** `KPI.VIEW`, `SALES.AUDIT`

#### `kpi/kpi_do_auditaudit.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **Filter** `actor`, `dept`, `do_id`, `export`, `month`
- **Permission** `KPI.VIEW`, `SALES.AUDIT`

#### `kpi/kpi_do_sla.php`

- **CRUD** CRU · **Auth gate** — · **Audit** —
- **Filter** `export`, `month`, `office`
- **Aksi** `_action`
- **State** `CLOSED`, `DELIVERED`, `OPEN`, `PAID`, `READY_SCM`, `cancelled`, `delivered`, `draft`, `on_delivery`, `open`, `paid`, `pending`, `ready_scm`, `void`

#### `kpi/kpi_employee.php`

- **CRUD** CRUD · **Auth gate** — · **Audit** —
- **Filter** `auto`, `auto_dept`, `auto_emp`, `auto_month`, `dept`, `edit`, `emp`, `export`, `month`
- **Aksi** `_action`
- **State** `DRAFT`

#### `kpi/kpi_office.php`

- **CRUD** CRUD · **Auth gate** — · **Audit** —
- **Filter** `auto`, `auto_month`, `auto_office`, `edit`, `export`, `month`, `office`, `status`
- **Aksi** `_action`
- **State** `DRAFT`

#### `kpi/kpi_purchases.php`

- **CRUD** CRUD · **Auth gate** — · **Audit** —
- **Filter** `edit`, `export`, `month`, `office`, `status`
- **Aksi** `_action`
- **State** `DRAFT`

#### `kpi/kpi_snapshot.php`

- **CRUD** CRU · **Auth gate** — · **Audit** —
- **Filter** `approval_checker`, `approval_month`, `approval_status`, `rejected`, `submitted`, `view`
- **Aksi** `action`, `approve`, `reject`, `submit_approval`
- **State** `APPROVED`, `PENDING`, `REJECTED`, `rejected`, `submitted`

#### `kpi/kpi_stock.php`

- **CRUD** CRUD · **Auth gate** — · **Audit** —
- **Filter** `edit`, `export`, `month`, `office`, `status`
- **Aksi** `_action`
- **State** `DRAFT`

#### `kpi/kpi_sync.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Aksi** `_action`

#### `manufacturer_portal/case_detail.php`

- **CRUD** CRU · **Auth gate** — · **Audit** —
- **Filter** `id`
- **Aksi** `action`, `upload_doc`, `upload_manu_doc`
- **State** `pending`

#### `manufacturer_portal/index.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **State** `OPEN`

#### `manufacturer_portal/login.php`

- **CRUD** RU · **Auth gate** — · **Audit** —
- **Filter** `next`

#### `manufacturer_portal/manufacture_docs.php`

- **CRUD** CRU · **Auth gate** — · **Audit** —
- **Aksi** `action`, `upload_manu_doc`
- **State** `pending`

#### `manufacturer_portal/rfq.php`

- **CRUD** CRU · **Auth gate** — · **Audit** —
- **Filter** `filter`
- **Aksi** `action`
- **State** `open`, `pending`, `submitted`

#### `master/account_readiness.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Aksi** `action`, `set_inactive`
- **Permission** `SYSTEM.ACCOUNT_READINESS`, `SYSTEM.USER_MANAGE`

#### `master/api_partner_keys.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Aksi** `action`, `create`, `delete`, `toggle`
- **Permission** `SYSTEM.API_PARTNER_KEYS`, `SYSTEM.CONFIG_MANAGE`

#### `master/audit_logs.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `action`, `date_from`, `date_to`, `ip`, `limit`, `module`, `username`
- **Aksi** `action`
- **Permission** `MASTER.ADMIN_CENTER`, `SYSTEM.AUDIT_LOG_VIEW`, `SYSTEM.CONFIG_MANAGE`, `SYSTEM.JOBS_MONITOR`, `SYSTEM.USER_MANAGE`

#### `master/auth.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **State** `delivered`, `draft`, `on_delivery`, `paid`, `ready_scm`

#### `master/auth_.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **State** `delivered`, `draft`, `on_delivery`, `paid`, `ready_scm`

#### `master/auth__.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **State** `delivered`, `draft`, `on_delivery`, `paid`, `ready_scm`

#### `master/company_bank_accounts.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `active`, `deleted`, `export`, `id`, `ok`, `purpose`, `q`
- **Aksi** `action`, `delete`, `save`, `toggle`
- **Permission** `MASTER.COMPANY_BANK_CREATE`, `MASTER.COMPANY_BANK_EDIT`, `MASTER.COMPANY_BANK_VIEW`, `PURCHASES.AP_PAYMENT_CREATE`, `PURCHASES.AP_PAYMENT_EDIT`, `PURCHASES.AP_PAYMENT_VIEW`

#### `master/import_rekening_final.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Aksi** `action`
- **Permission** `HRL.IMPORT_REKENING`

#### `master/index.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Permission** `MASTER.VIEW`

#### `master/itc_reset_password.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `department`, `office_code`, `q`, `reset`, `status`
- **Aksi** `op`
- **State** `open`
- **Permission** `SYSTEM.USER_MANAGE`, `TOOLS.ITC_RESET_PASSWORD`

#### `master/jobs_monitor.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Filter** `status`
- **Aksi** `action`, `bulk_cancel`, `bulk_retry`, `cancel`, `retry`, `run_now`
- **State** `PENDING`
- **Permission** `SYSTEM.CONFIG_MANAGE`, `SYSTEM.JOBS_MONITOR`, `SYSTEM.USER_MANAGE`

#### `master/login.php`

- **CRUD** CRU · **Auth gate** — · **Audit** —
- **Filter** `next`, `reason`

#### `master/manufactures_docs.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `code`, `del`, `dl_md`, `hrl`, `ign`, `int`, `ok`, `type`, `up`

#### `master/manufactures_docsbaru.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `code`, `del`, `dl_md`, `hrl`, `ign`, `int`, `ok`, `type`, `up`

#### `master/manufactures_docsrevbaru1609.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `code`, `del`, `dl_md`, `hrl`, `ign`, `int`, `ok`, `type`, `up`

#### `master/master_customer_portal_users.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `from_mpr`
- **Aksi** `action`, `create`, `reset_password`, `toggle_status`
- **Permission** `MASTER.CUSTOMER_CREATE`, `MASTER.CUSTOMER_EDIT`, `MASTER.CUSTOMER_VIEW`, `MASTER.VIEW`

#### `master/master_customers lama.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `edit`
- **Aksi** `bulk_action`
- **Permission** `MASTER.CUSTOMER_CREATE`, `MASTER.CUSTOMER_EDIT`, `MASTER.CUSTOMER_VIEW`

#### `master/master_customers.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `download_customers_import`, `download_template`, `edit`
- **Aksi** `bulk_action`
- **Permission** `MASTER.CUSTOMER_CREATE`, `MASTER.CUSTOMER_EDIT`, `MASTER.CUSTOMER_VIEW`

#### `master/master_customers_.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `download_customers_import`, `download_template`, `edit`
- **Aksi** `bulk_action`
- **Permission** `MASTER.CUSTOMER_CREATE`, `MASTER.CUSTOMER_EDIT`, `MASTER.CUSTOMER_VIEW`

#### `master/master_data.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Permission** `MASTER.ADMIN_CENTER`, `MASTER.VIEW`

#### `master/master_departements.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `download_template`, `edit`
- **Permission** `MASTER.DEPARTMENT_CREATE`, `MASTER.DEPARTMENT_EDIT`, `MASTER.DEPARTMENT_VIEW`, `MASTER.VIEW`

#### `master/master_emailcompany.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `edit`
- **Permission** `MASTER.EMAIL_COMPANY_CREATE`, `MASTER.EMAIL_COMPANY_EDIT`, `MASTER.EMAIL_COMPANY_VIEW`

#### `master/master_employees.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `download_template`, `edit`, `filter_dept`, `filter_level`, `filter_office`, `filter_search`, `filter_status`
- **Permission** `MASTER.EMPLOYEE_CREATE`, `MASTER.EMPLOYEE_EDIT`, `MASTER.EMPLOYEE_VIEW`

#### `master/master_employeesdell.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `download_template`, `edit`, `filter_dept`, `filter_level`, `filter_office`, `filter_search`, `filter_status`
- **Permission** `MASTER.EMPLOYEE_CREATE`, `MASTER.EMPLOYEE_EDIT`, `MASTER.EMPLOYEE_VIEW`

#### `master/master_employeesid.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `download_template`, `edit`, `filter_dept`, `filter_level`, `filter_office`, `filter_search`, `filter_status`
- **Permission** `MASTER.EMPLOYEE_CREATE`, `MASTER.EMPLOYEE_EDIT`, `MASTER.EMPLOYEE_VIEW`

#### `master/master_employeesjaba.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `download_template`, `edit`, `filter_dept`, `filter_level`, `filter_office`, `filter_search`, `filter_status`
- **Permission** `MASTER.EMPLOYEE_CREATE`, `MASTER.EMPLOYEE_EDIT`, `MASTER.EMPLOYEE_VIEW`

#### `master/master_employeesmasakontrak.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `download_template`, `edit`, `filter_dept`, `filter_level`, `filter_office`, `filter_search`, `filter_status`
- **Permission** `MASTER.EMPLOYEE_CREATE`, `MASTER.EMPLOYEE_EDIT`, `MASTER.EMPLOYEE_VIEW`

#### `master/master_export_customers.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `format`
- **Permission** `MASTER.CUSTOMER_EXPORT`

#### `master/master_import_customers.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `download_template`
- **Aksi** `action`, `clear_preview`, `do_import`, `upload_preview`
- **Permission** `MASTER.IMPORT_CUSTOMERS`

#### `master/master_import_products.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `download_template`
- **Aksi** `action`, `clear_preview`, `do_import`, `upload_preview`
- **Permission** `MASTER.IMPORT_PRODUCTS`

#### `master/master_import_vendors.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Aksi** `action`, `clear_preview`, `do_import`, `upload_preview`
- **Permission** `MASTER.IMPORT_VENDORS`

#### `master/master_manufacturer_portal_users.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `from_manufacture`
- **Aksi** `action`, `create`, `reset_password`, `toggle_status`
- **Permission** `MASTER.MANUFACTURE_CREATE`, `MASTER.MANUFACTURE_EDIT`, `MASTER.MANUFACTURE_VIEW`, `MASTER.VIEW`, `PQP.VIEW`

#### `master/master_manufactures.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** Ya
- **Filter** `edit`, `filter_city`, `filter_country`, `filter_origin`, `filter_status`, `search`, `show_deleted`
- **Aksi** `bulk_action`
- **Permission** `MASTER.MANUFACTURE_CREATE`, `MASTER.MANUFACTURE_EDIT`, `MASTER.MANUFACTURE_VIEW`
- **Event audit** `WRITE`

#### `master/master_office.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `edit`
- **Aksi** `bulk_action`
- **Permission** `MASTER.OFFICE_CREATE`, `MASTER.OFFICE_EDIT`, `MASTER.OFFICE_VIEW`

#### `master/master_payment_terms.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `edit`, `filter_status`, `search`

#### `master/master_pricelist.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** Ya
- **Filter** `ajax`, `customers_code`, `download`, `office_code`, `q`
- **Aksi** `action`, `activate`, `bulk`, `deactivate`, `delete`, `import_csv`, `op`, `restore`, `save`
- **Event audit** `CREATE`, `POSTING`, `UPDATE`

#### `master/master_pricelist_sell.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `customers_code`, `office_code`, `q`
- **Permission** `MASTER.ADMIN_CENTER`, `MASTER.PRICELIST_BUY_CREATE`, `MASTER.PRICELIST_BUY_EDIT`, `MASTER.PRICELIST_BUY_VIEW`, `MASTER.PRICELIST_SELL_CREATE`, `MASTER.PRICELIST_SELL_EDIT`, `MASTER.PRICELIST_SELL_VIEW`, `MPR.VIEW`

#### `master/master_product_media_bulk.php`

- **CRUD** CR · **Auth gate** Ya · **Audit** —
- **Permission** `MASTER.PRODUCT.MANAGE`, `MASTER.PRODUCT.VIEW`, `SYSTEM.MASTER_MANAGE`

#### `master/master_products.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** Ya
- **Filter** `business_group`, `cat`, `delete_master`, `delete_office_stock`, `download_template`, `edit_id`, `id`, `mid`, `office_code`, `q`, `status`, `toggle`, `type`, `upload_media`
- **Aksi** `bulk_action`
- **State** `draft`
- **Permission** `MASTER.IMPORT_PRODUCTS`, `MASTER.PRODUCT_CREATE`, `MASTER.PRODUCT_EDIT`, `MASTER.PRODUCT_MEDIA_UPLOAD`, `MASTER.PRODUCT_VIEW`
- **Event audit** `CREATE`, `DELETE`, `POSTING`, `UPDATE`

#### `master/master_products_doc.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `product_id`, `q`
- **Aksi** `action`, `delete`, `upload`

#### `master/master_products_package.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `edit_id`
- **Aksi** `action`
- **Permission** `MASTER.PRODUCT_PACKAGE_CREATE`, `MASTER.PRODUCT_PACKAGE_EDIT`, `MASTER.PRODUCT_PACKAGE_VIEW`, `MASTER.VIEW`

#### `master/master_products_print.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `manufacture_id`, `product_type`, `q`, `status`

#### `master/master_system_login.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `department`, `edit`, `export_csv`, `holder_setup`, `last_login`, `level`, `mfa`, `office_code`, `q`, `role`, `status`, `template_csv`, `template_holder_csv`
- **Aksi** `op`
- **Permission** `SYSTEM.RBAC_MANAGE`, `SYSTEM.RBAC_VIEW`, `SYSTEM.USER_MANAGE`

#### `master/master_system_loginid.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `department`, `edit`, `export_csv`, `holder_setup`, `last_login`, `level`, `mfa`, `office_code`, `q`, `role`, `status`, `template_csv`, `template_holder_csv`
- **Aksi** `op`
- **Permission** `SYSTEM.RBAC_MANAGE`, `SYSTEM.RBAC_VIEW`, `SYSTEM.USER_MANAGE`

#### `master/master_system_loginmalang.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `department`, `edit`, `export_csv`, `holder_setup`, `last_login`, `level`, `mfa`, `office_code`, `q`, `role`, `status`, `template_csv`, `template_holder_csv`
- **Aksi** `op`
- **Permission** `SYSTEM.RBAC_MANAGE`, `SYSTEM.RBAC_VIEW`, `SYSTEM.USER_MANAGE`

#### `master/master_tax.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `edit`, `filter_level`, `filter_status`, `filter_type`, `search`

#### `master/master_user.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `delete`, `download_customer_reference`, `download_mpr_import`, `download_template`, `edit`, `filter_customer`, `toggle_status`
- **Aksi** `bulk_action`
- **Permission** `MASTER.PIC_CUSTOMER_CREATE`, `MASTER.PIC_CUSTOMER_EDIT`, `MASTER.PIC_CUSTOMER_VIEW`, `MASTER.VIEW`, `SYSTEM.USER_MANAGE`

#### `master/master_user2809.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `delete`, `download_customer_reference`, `download_mpr_import`, `download_template`, `edit`, `filter_customer`, `toggle_status`
- **Aksi** `bulk_action`
- **Permission** `MASTER.PIC_CUSTOMER_CREATE`, `MASTER.PIC_CUSTOMER_EDIT`, `MASTER.PIC_CUSTOMER_VIEW`, `MASTER.VIEW`, `SYSTEM.USER_MANAGE`

#### `master/master_user_.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `delete`, `download_customer_reference`, `download_template`, `edit`, `filter_customer`, `toggle_status`
- **Aksi** `bulk_action`
- **Permission** `MASTER.PIC_CUSTOMER_CREATE`, `MASTER.PIC_CUSTOMER_EDIT`, `MASTER.PIC_CUSTOMER_VIEW`, `MASTER.VIEW`, `SYSTEM.USER_MANAGE`

#### `master/master_vendors.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `ajax_check`, `ajax_suggest`, `download_template`, `edit`, `exclude_id`, `filter_category`, `filter_duplicate`, `filter_office`, `filter_status`, `filter_type`, `name`, `office_code`, `q`, `search`
- **Permission** `MASTER.VENDOR_CREATE`, `MASTER.VENDOR_DELETE`, `MASTER.VENDOR_EDIT`, `MASTER.VENDOR_VIEW`

#### `master/mfa_admin_reset.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Aksi** `action`, `clear_mfa`

#### `master/mfa_bypass.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Aksi** `action`, `approve`, `create`, `reject`, `revoke`
- **State** `APPROVED`, `PENDING`, `REJECTED`
- **Permission** `SYSTEM.MFA_BYPASS_MANAGE`, `SYSTEM.USER_MANAGE`

#### `master/mfa_policy.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **Aksi** `action`, `apply_phase`, `delete_policy`, `save_policy`
- **Permission** `SYSTEM.CONFIG_MANAGE`, `SYSTEM.MFA_POLICY_MANAGE`, `SYSTEM.USER_MANAGE`

#### `master/mfa_settings.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Aksi** `action`, `confirm_enroll`, `disable_mfa`, `start_enroll`

#### `master/mfa_verify.php`

- **CRUD** RU · **Auth gate** — · **Audit** —
- **Filter** `next`

#### `master/monitoring_center.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Permission** `MASTER.ADMIN_CENTER`, `SYSTEM.AUDIT_LOG_VIEW`, `SYSTEM.CONFIG_MANAGE`, `SYSTEM.JOBS_MONITOR`, `SYSTEM.USER_MANAGE`

#### `master/monitoring_centerrevhistory.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Permission** `MASTER.ADMIN_CENTER`, `SYSTEM.AUDIT_LOG_VIEW`, `SYSTEM.CONFIG_MANAGE`, `SYSTEM.JOBS_MONITOR`, `SYSTEM.USER_MANAGE`

#### `master/nav_manager.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Filter** `advanced`, `dept`, `error`, `reset`, `saved`, `tab`
- **Permission** `MASTER.ADMIN_CENTER`, `SYSTEM.CONFIG_MANAGE`, `SYSTEM.USER_MANAGE`

#### `master/org_structure_edit.php`

- **CRUD** U · **Auth gate** Ya · **Audit** —
- **Aksi** `action`, `reset_default`, `save`

#### `master/owner_activity_control.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `action`, `date_from`, `date_to`, `department`, `limit`, `module`, `office`, `q`, `record_code`, `username`
- **Aksi** `action`

#### `master/owner_activity_control26.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `action`, `date_from`, `date_to`, `department`, `limit`, `module`, `office`, `q`, `record_code`, `username`
- **Aksi** `action`

#### `master/owner_activity_control26_2.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `action`, `date_from`, `date_to`, `department`, `limit`, `module`, `office`, `q`, `record_code`, `username`
- **Aksi** `action`

#### `master/products_media_view.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `products_code`
- **Permission** `MASTER.PRODUCT_VIEW`, `MASTER_PRODUCTS.MEDIA_VIEW`

#### `master/products_media_view_.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `products_code`

#### `master/rate_limit_policies.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **Aksi** `action`, `delete`, `save`
- **Permission** `SYSTEM.CONFIG_MANAGE`, `SYSTEM.RATE_LIMIT_MANAGE`

#### `master/scan_product_media_folder.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Permission** `MASTER.PRODUCT_EDIT`, `MASTER.PRODUCT_MEDIA_UPLOAD`

#### `master/schema_mfa.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **State** `PENDING`

#### `master/webauthn_api.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `action`, `next`, `verbose`
- **Aksi** `auth_options`, `auth_verify`, `register_options`, `register_verify`

#### `master_system_config.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `delete`, `edit`, `toggle`
- **Aksi** `bulk_action`

#### `mpr/mpr_api_contacts.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `customer_id`

#### `mpr/mpr_budget_fin.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`, `export`, `page`, `q`, `status`
- **Aksi** `action`, `approve`, `reject`
- **State** `APPROVED`, `DRAFT`, `REJECTED`, `SUBMITTED`

#### `mpr/mpr_dashboard.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `month`, `year`
- **State** `SUBMITTED`

#### `mpr/mpr_gps_capture.php`

- **CRUD** — · **Auth gate** — · **Audit** —
- **Filter** `return`, `target`

#### `mpr/mpr_ops_daily_fin.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `from`, `office_code`, `to`
- **State** `PAID`, `paid`

#### `mpr/mpr_ops_daily_fin_detail.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `date`, `employee_code`, `office_code`, `username`
- **State** `PAID`, `paid`

#### `mpr/mpr_ops_daily_fin_detail_.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `date`, `employee_code`, `office_code`, `username`
- **State** `PAID`, `paid`

#### `mpr/mpr_ops_daily_fin_export.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `from`, `office_code`, `scope`, `to`
- **State** `PAID`, `paid`
- **Permission** `MPR.PLAN_EXPORT`, `MPR.PLAN_VIEW`, `MPR.VIEW`

#### `mpr/mpr_ops_daily_fin_pay.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **State** `PAID`, `paid`

#### `mpr/mpr_pipeline.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `edit`, `export`, `q`, `stage`, `who`
- **Aksi** `action`, `create`, `delete`, `handover_crm`, `update`
- **Permission** `MPR.PLAN_CREATE`, `MPR.VIEW`

#### `mpr/mpr_plan_view.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **Aksi** `action`, `add_budget`, `add_progress`, `add_visit`, `delete_budget`, `delete_progress`, `delete_visit`, `submit_budget`
- **State** `APPROVED`, `DRAFT`, `PENDING`, `REJECTED`, `SUBMITTED`
- **Permission** `MPR.PLAN_CREATE`, `MPR.PLAN_DELETE`, `MPR.PLAN_EDIT`, `MPR.PLAN_VIEW`, `MPR.VIEW`

#### `mpr/mpr_plan_view_.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **Aksi** `action`, `add_budget`, `add_progress`, `add_visit`, `delete_budget`, `delete_progress`, `delete_visit`, `submit_budget`
- **State** `APPROVED`, `DRAFT`, `PENDING`, `REJECTED`, `SUBMITTED`
- **Permission** `MPR.PLAN_CREATE`, `MPR.PLAN_DELETE`, `MPR.PLAN_EDIT`, `MPR.PLAN_VIEW`, `MPR.VIEW`

#### `mpr/mpr_plans.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `edit`, `export`, `q`, `show_deleted`, `status`
- **Aksi** `action`, `approve`, `bulk_action`, `create`, `import_csv`, `reject`, `restore`, `soft_delete`, `submit`, `update`
- **State** `APPROVED`, `CANCELLED`, `DRAFT`, `REJECTED`, `SUBMITTED`
- **Permission** `MPR.PLAN_CREATE`, `MPR.PLAN_DELETE`, `MPR.PLAN_EDIT`, `MPR.PLAN_VIEW`, `MPR.VIEW`

#### `mpr/mpr_plansrev2809.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `edit`, `export`, `q`, `show_deleted`, `status`
- **Aksi** `action`, `approve`, `bulk_action`, `create`, `import_csv`, `reject`, `restore`, `soft_delete`, `submit`, `update`
- **State** `APPROVED`, `CANCELLED`, `DRAFT`, `REJECTED`, `SUBMITTED`
- **Permission** `MPR.PLAN_CREATE`, `MPR.PLAN_DELETE`, `MPR.PLAN_EDIT`, `MPR.PLAN_VIEW`, `MPR.VIEW`

#### `mpr/mpr_visits.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `edit`, `export`, `month`, `q`, `type`, `who`
- **Aksi** `action`, `create`, `delete`, `g`, `k`, `m`, `update`
- **State** `PENDING`
- **Permission** `MPR.PLAN_CREATE`, `MPR.VIEW`

#### `mpr/mpr_visitsrev2809.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `edit`, `export`, `month`, `q`, `type`, `who`
- **Aksi** `action`, `create`, `delete`, `g`, `k`, `m`, `update`
- **State** `PENDING`
- **Permission** `MPR.PLAN_CREATE`, `MPR.VIEW`

#### `payroll/audit.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `action`, `export`, `from`, `q`, `to`, `view`
- **Aksi** `action`

#### `payroll/index.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `export`, `id`
- **Aksi** `action`
- **State** `DRAFT`, `PAID`

#### `payroll/loans.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `edit`, `export`, `q`, `status`, `type`
- **Aksi** `action`, `delete_loan`, `save_loan`, `set_status`
- **State** `CLOSED`

#### `payroll/payroll_absensi_manual_fix.php`

- **CRUD** RU · **Auth gate** — · **Audit** —
- **Filter** `run_id`
- **Aksi** `action`

#### `payroll/payroll_calendar.php`

- **CRUD** CRU · **Auth gate** — · **Audit** —
- **Filter** `edit`, `month`
- **Aksi** `action`, `save`, `toggle`

#### `payroll/payroll_fix_run_from_settings.php`

- **CRUD** RU · **Auth gate** — · **Audit** —
- **Filter** `do`, `run_id`

#### `payroll/payroll_repair_run_matrix_absensi.php`

- **CRUD** RU · **Auth gate** — · **Audit** —
- **Filter** `do`, `run_id`

#### `payroll/payroll_run.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **Aksi** `action`, `mark_paid`, `post_payroll`, `recalc_absensi`, `record_payment`, `sync_employee_profile`, `sync_late_deduction`, `sync_loans`, `sync_matrix`, `sync_overtime`, `sync_payroll_components`, `update_item`
- **State** `APPROVED`, `DRAFT`, `PAID`, `POSTED`

#### `payroll/payroll_settings.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `employee_id`, `id`, `q`
- **Aksi** `action`

#### `payroll/payroll_sync_helpers.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **State** `APPROVED`, `PAID`

#### `payroll/payslip.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **Filter** `item_id`
- **State** `DRAFT`, `PAID`, `POSTED`

#### `payroll/salary_matrix.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `delete`, `edit`, `export`, `q`, `status`, `template`, `year`
- **Aksi** `action`

#### `purchases/bank_recon.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `period`, `recon_id`
- **Aksi** `action`, `auto_match`, `create_bank_account`, `create_statement`, `manual_match`, `set_status`
- **State** `DRAFT`
- **Permission** `MASTER.COMPANY_BANK_CRUD`, `PURCHASES.AP_PAYMENT_CRUD`, `PURCHASES.REPORTS_VIEW`, `PURCHASES.VIEW`

#### `purchases/bank_statement_import.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Permission** `MASTER.COMPANY_BANK_CRUD`, `PURCHASES.AP_PAYMENT_CRUD`, `PURCHASES.VIEW`

#### `purchases/fin_gl_auto.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `account_id`, `date_from`, `date_to`, `export`, `source_module`, `source_ref`, `view`
- **Aksi** `action`, `reverse_journal`
- **State** `PENDING`
- **Permission** `PURCHASES.AP_INVOICE_CRUD`, `PURCHASES.AP_PAYMENT_CRUD`, `PURCHASES.REPORTS_VIEW`, `PURCHASES.VIEW`

#### `purchases/gl_reversal_approvals.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Aksi** `action`, `approve`, `reject`
- **State** `APPROVED`, `PENDING`, `REJECTED`
- **Permission** `MASTER.ADMIN_CENTER`, `PURCHASES.ADMIN_GL_AUTO`, `PURCHASES.AP_PAYMENT_CREATE`, `PURCHASES.AP_PAYMENT_EDIT`, `PURCHASES.AP_PAYMENT_VIEW`

#### `purchases/panduan.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Permission** `DASHBOARD.PROCUREMENT_VIEW`, `PANDUAN.PURCHASES_VIEW`

#### `purchases/pqp_rfq.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`, `id`, `status`
- **Aksi** `action`
- **State** `cancelled`, `closed`, `draft`, `open`, `submitted`
- **Permission** `PQP.VIEW`

#### `purchases/pqp_rfq_download.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **Permission** `PQP.VIEW`

#### `purchases/pqp_rfq_export.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `format`, `id`
- **State** `submitted`
- **Permission** `PQP.VIEW`

#### `purchases/purchases_ap_import.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **State** `CANCELLED`, `PAID`
- **Permission** `PURCHASES.AP_INVOICE_CREATE`, `PURCHASES.AP_INVOICE_EDIT`, `PURCHASES.AP_PAYMENT_CREATE`, `PURCHASES.REPORTS_VIEW`, `PURCHASES.VIEW`

#### `purchases/purchases_ap_importreplace.php`

- **CRUD** C · **Auth gate** Ya · **Audit** —
- **State** `CANCELLED`, `PAID`
- **Permission** `PURCHASES.AP_INVOICE_CREATE`, `PURCHASES.AP_INVOICE_EDIT`, `PURCHASES.AP_PAYMENT_CREATE`, `PURCHASES.REPORTS_VIEW`, `PURCHASES.VIEW`

#### `purchases/purchases_ap_importress.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **State** `CANCELLED`, `PAID`
- **Permission** `PURCHASES.AP_INVOICE_CREATE`, `PURCHASES.AP_INVOICE_EDIT`, `PURCHASES.AP_PAYMENT_CREATE`, `PURCHASES.REPORTS_VIEW`, `PURCHASES.VIEW`

#### `purchases/purchases_ceisa_pib.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Permission** `PURCHASES.CEISA_EDIT`, `PURCHASES.CEISA_PIB`, `PURCHASES.CEISA_VIEW`

#### `purchases/purchases_ceisa_pib_view.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **Aksi** `action`, `add_pay`, `save_ceisa`, `upload_doc`
- **State** `APPROVED`, `DRAFT`, `REJECTED`, `SUBMITTED`
- **Permission** `PURCHASES.CEISA_PIB`, `PURCHASES.CEISA_VIEW`

#### `purchases/purchases_control_tower.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Filter** `office_code`, `pay`, `pay_from`, `pay_to`, `q`, `status`
- **Aksi** `action`
- **State** `CANCELLED`, `CLOSED`, `DRAFT`, `OPEN`, `PAID`, `READY`
- **Permission** `PURCHASES.IMPORT_CONTROL`, `PURCHASES.PO_VIEW`, `PURCHASES.VIEW`

#### `purchases/purchases_dashboard.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **State** `CANCELLED`, `CLOSED`, `COMPLETED`, `DELIVERED`, `IN_PROGRESS`, `OPEN`, `PAID`, `PENDING`, `READY`, `SUBMITTED`, `VOID`, `open`, `submitted`
- **Permission** `DASHBOARD.PROCUREMENT_VIEW`, `PURCHASES.VIEW`

#### `purchases/purchases_dashboard_.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **State** `CANCELLED`, `CLOSED`, `OPEN`, `READY`, `SUBMITTED`, `open`, `submitted`
- **Permission** `DASHBOARD.PROCUREMENT_VIEW`, `PURCHASES.VIEW`

#### `purchases/purchases_fin_po_process.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `id`

#### `purchases/purchases_forwarder_invoice.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `po_id`
- **Permission** `PURCHASES.AP_INVOICE_CREATE`, `PURCHASES.AP_INVOICE_EDIT`, `PURCHASES.AP_INVOICE_VIEW`, `PURCHASES.FORWARDING_CREATE`, `PURCHASES.FORWARDING_EDIT`, `PURCHASES.FORWARDING_VIEW`

#### `purchases/purchases_forwarder_payment.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `fap_id`
- **Permission** `PURCHASES.AP_PAYMENT_CREATE`, `PURCHASES.AP_PAYMENT_EDIT`, `PURCHASES.AP_PAYMENT_VIEW`, `PURCHASES.FORWARDING_CREATE`, `PURCHASES.FORWARDING_EDIT`, `PURCHASES.FORWARDING_VIEW`

#### `purchases/purchases_forwarder_quotes.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `download_template`, `po_id`
- **State** `DRAFT`, `IN_PROGRESS`, `REJECTED`
- **Permission** `PURCHASES.FORWARDING_CREATE`, `PURCHASES.FORWARDING_EDIT`, `PURCHASES.FORWARDING_VIEW`

#### `purchases/purchases_forwarding_tasks.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `fw_status`, `po_status`
- **Aksi** `action`, `delete_doc`, `save`
- **State** `CANCELLED`, `CLOSED`, `COMPLETED`, `DELIVERED`, `IN_PROGRESS`, `PAID`, `PENDING`, `VOID`
- **Permission** `PURCHASES.FORWARDING_CREATE`, `PURCHASES.FORWARDING_EDIT`, `PURCHASES.FORWARDING_VIEW`

#### `purchases/purchases_gr.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Permission** `PURCHASES.GR_PROCESS`, `PURCHASES.GR_VIEW`, `WQS.INCOMING_CREATE`, `WQS.INCOMING_EDIT`, `WQS.INCOMING_VIEW`

#### `purchases/purchases_gr_load_items.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `po_id`
- **Permission** `PURCHASES.GR_PROCESS`, `WQS.INCOMING_CREATE`, `WQS.INCOMING_EDIT`, `WQS.INCOMING_VIEW`

#### `purchases/purchases_import_control_tower.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `office_code`, `pay`, `pay_from`, `pay_to`, `pay_type`, `q`, `status`
- **State** `APPROVED`, `CANCELLED`, `CLOSED`, `DRAFT`, `OPEN`, `PAID`, `READY`, `REJECTED`, `SUBMITTED`, `paid`
- **Permission** `PURCHASES.IMPORT_CONTROL`

#### `purchases/purchases_import_control_view.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `id`, `po_code`
- **Aksi** `action`, `delete_doc`, `save_prod`, `save_ship`, `sync_to_pricelist`, `upload_doc`, `upload_prod_media`
- **Permission** `PURCHASES.IMPORT_CONTROL`

#### `purchases/purchases_invoice_ap.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `invoice_type`, `percent`, `po_id`
- **State** `CANCELLED`, `VOID`
- **Permission** `PURCHASES.AP_INVOICE_CREATE`, `PURCHASES.AP_INVOICE_EDIT`, `PURCHASES.AP_INVOICE_VIEW`, `PURCHASES.AP_PAYMENT_CREATE`, `PURCHASES.AP_PAYMENT_EDIT`, `PURCHASES.AP_PAYMENT_VIEW`, `PURCHASES.VIEW`

#### `purchases/purchases_invoice_ap_edit.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **Permission** `PURCHASES.AP_INVOICE_CREATE`, `PURCHASES.AP_INVOICE_EDIT`, `PURCHASES.AP_INVOICE_VIEW`, `PURCHASES.AP_PAYMENT_CREATE`, `PURCHASES.AP_PAYMENT_EDIT`, `PURCHASES.AP_PAYMENT_VIEW`

#### `purchases/purchases_payment_ap.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `ap_id`, `opening_ap_id`
- **State** `CANCELLED`, `PAID`, `VOID`
- **Permission** `PURCHASES.AP_INVOICE_CREATE`, `PURCHASES.AP_INVOICE_EDIT`, `PURCHASES.AP_INVOICE_VIEW`, `PURCHASES.AP_PAYMENT_CREATE`, `PURCHASES.AP_PAYMENT_EDIT`, `PURCHASES.AP_PAYMENT_VIEW`, `PURCHASES.VIEW`

#### `purchases/purchases_po.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** Ya
- **Filter** `csrf_token`, `delete`, `flow`, `from`, `id`, `office_code`, `overdue`, `pr_id`, `restore`, `set_status`, `show_deleted`, `status`, `to`
- **Aksi** `action`, `cancel_trial_po`, `create_po`, `return_pr_revision`
- **State** `CANCELLED`, `CLOSED`, `DRAFT`, `OPEN`, `READY`, `SUBMITTED`, `VOID`
- **Permission** `PURCHASES.PO_CREATE`, `PURCHASES.PO_EDIT`, `PURCHASES.PO_VIEW`, `PURCHASES.VIEW`
- **Event audit** `CREATE`, `DELETE`, `UPDATE`

#### `purchases/purchases_po_fin_view.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `id`

#### `purchases/purchases_po_print.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **Permission** `PURCHASES.PO_CREATE`, `PURCHASES.PO_EDIT`, `PURCHASES.PO_VIEW`, `PURCHASES.VIEW`

#### `purchases/purchases_po_readonly_view.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `id`

#### `purchases/purchases_po_view.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **State** `APPROVED`, `CANCELLED`, `CLOSED`, `DRAFT`, `OPEN`, `READY`, `VOID`
- **Permission** `PURCHASES.PO_CREATE`, `PURCHASES.PO_EDIT`, `PURCHASES.PO_VIEW`, `PURCHASES.VIEW`

#### `purchases/purchases_pr_api.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `pr_code`, `pr_id`

#### `purchases/purchases_reports.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `from`, `to`
- **Permission** `PURCHASES.AP_INVOICE_CRUD`, `PURCHASES.AP_PAYMENT_CRUD`, `PURCHASES.REPORTS_VIEW`

#### `purchases/revbaru1.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `office_code`, `q`, `status`
- **State** `APPROVED`, `CANCELLED`, `CLOSED`, `DRAFT`, `OPEN`, `READY`, `REJECTED`, `SUBMITTED`
- **Permission** `PURCHASES.IMPORT_CONTROL`

#### `purchases/stock_update_from_gr.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Permission** `PURCHASES.ADMIN_STOCK_UPDATE`, `PURCHASES.GR_PROCESS`, `WQS.INCOMING_CREATE`, `WQS.INCOMING_EDIT`, `WQS.INCOMING_VIEW`

#### `rbac/index.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `action`, `dept_code`, `effective_user`, `hl_dept`, `hl_user`, `rbac_layout`, `role_code`
- **Aksi** `APPLY_PRESET`, `BASELINE`, `COPY`, `COPY_FROM`, `DELETE`, `GRANT`, `GRANT_ALL`, `GRANT_ALL_SYS`, `IMPORT`, `PRESET`, `PRUNE`, `PRUNE_ORPHAN`, `SAVE`, `SAVE_MATRIX`, `SYNC`, `SYNC_PERMISSIONS`, `UPDATE`, `action`, `add_perm`, `apply_baseline`, `apply_preset`, `copy_from`, `grant_all_sys`, `import_json`, `prune_orphan_permissions`, `save_matrix`, `sync_permissions`, `toggle_user_perm`
- **State** `open`
- **Permission** `SYSTEM.RBAC_MANAGE`, `SYSTEM.RBAC_VIEW`

#### `rbac/nav_parallel_report.php`

- **CRUD** U · **Auth gate** Ya · **Audit** —
- **Aksi** `action`
- **Permission** `SYSTEM.RBAC_MANAGE`, `SYSTEM.RBAC_VIEW`

#### `rbac/panduan.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Permission** `SYSTEM.RBAC_MANAGE`, `SYSTEM.RBAC_VIEW`

#### `sales/act_do_tasks.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** Ya
- **Filter** `f_date_fr`, `f_date_to`, `f_exchange`, `f_exchange_date_fr`, `f_exchange_date_to`, `f_page`, `f_q`, `f_status`
- **Aksi** `action`, `save`, `save_exchange`, `save_tax_recovery`, `send_fin`
- **State** `DELIVERED`, `Open`, `Pending`, `completed`, `delivered`, `open`, `paid`, `pending`
- **Permission** `SALES.EDIT`, `SALES.VIEW`
- **Event audit** `APPROVE`, `UPDATE`

#### `sales/act_do_upload_diagnostic.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `do_code`

#### `sales/backfill_sales_do_audit_crm.php`

- **CRUD** CR · **Auth gate** Ya · **Audit** —
- **Filter** `run`
- **Permission** `SYSTEM.CONFIG_MANAGE`, `SYSTEM.USER_MANAGE`

#### `sales/backfill_sales_do_audit_stages.php`

- **CRUD** CR · **Auth gate** Ya · **Audit** —
- **Filter** `run`
- **State** `closed`, `completed`, `paid`, `pending`
- **Permission** `SYSTEM.CONFIG_MANAGE`, `SYSTEM.USER_MANAGE`

#### `sales/export_kpi_do_csv.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `customer`, `date_from`, `date_to`, `debug`, `department`, `ignore_date`, `include_locked`, `pic`, `status`
- **State** `approved`, `closed`, `completed`, `paid`

#### `sales/fin_ar_import.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **State** `CANCELLED`, `PAID`
- **Permission** `SALES.EDIT`, `SALES.VIEW`

#### `sales/fin_ar_recap.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `date_fr`, `date_to`, `msg`, `office`, `q`, `status`
- **Aksi** `action`
- **State** `CANCELLED`, `PAID`, `paid`
- **Permission** `SALES.VIEW`

#### `sales/fin_do_tasks.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** Ya
- **Filter** `f_date_fr`, `f_date_to`, `f_q`, `f_status`, `page`
- **Aksi** `action`, `approve_revision`, `paid`, `save`
- **State** `Open`, `Pending`, `closed`, `completed`, `paid`
- **Permission** `SALES.EDIT`, `SALES.VIEW`
- **Event audit** `APPROVE`, `POSTING`, `UPDATE`

#### `sales/kpi_do_audit.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `d1`, `d2`, `dept`, `from`, `q`, `to`
- **State** `delivered`, `on_delivery`, `paid`, `ready_scm`
- **Permission** `KPI.VIEW`, `SALES.AUDIT`, `SALES.VIEW`

#### `sales/kpi_do_sla.php`

- **CRUD** RU · **Auth gate** — · **Audit** —
- **Filter** `export`, `month`, `office`
- **Aksi** `_action`, `sla_act`
- **State** `delivered`, `draft`, `on_delivery`, `open`, `paid`, `pending`, `ready_scm`
- **Permission** `KPI.DO_VIEW`, `SALES.AUDIT`

#### `sales/kpi_do_sla_fixed_staff_v6.php`

- **CRUD** RU · **Auth gate** — · **Audit** —
- **Filter** `export`, `month`, `office`
- **Aksi** `_action`, `sla_act`
- **State** `DELIVERED`, `PAID`, `READY_SCM`, `delivered`, `draft`, `on_delivery`, `open`, `paid`, `pending`, `ready_scm`
- **Permission** `KPI.DO_VIEW`, `SALES.AUDIT`

#### `sales/panduan.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Permission** `DASHBOARD.SALES_VIEW`, `PANDUAN.SALES_VIEW`, `SALES.VIEW`

#### `sales/panduan_do_tasks.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **State** `delivered`, `on_delivery`, `ready_scm`
- **Permission** `SALES.EDIT`, `SALES.VIEW`

#### `sales/sales_control_tower.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Filter** `ajax`, `business_group`, `category`, `date_from`, `date_to`, `item_category`, `office_code`, `paid`, `paid_from`, `paid_to`, `q`, `status`
- **Aksi** `action`, `refresh_tracking`
- **State** `PAID`, `cancelled`, `closed`, `completed`, `delivered`, `on_delivery`, `paid`, `ready_scm`
- **Permission** `MASTER.ADMIN_CENTER`, `MASTER.VIEW`, `SALES.CONTROL_TOWER_VIEW`, `SALES.EDIT`

#### `sales/sales_dashboard.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `date_from`, `date_to`, `department`, `include_locked`, `status`
- **State** `cancelled`, `closed`, `delivered`, `draft`, `on_delivery`, `paid`, `ready_scm`, `void`
- **Permission** `DASHBOARD.SALES_VIEW`, `SALES.VIEW`

#### `sales/sales_do.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `ajax`, `business_group`, `customers_code`, `do_business_group`, `do_category`, `edit`, `exclude_do_id`, `id`, `office_code`, `q`, `replacement_for`, `return_id`, `sku`, `source`
- **Aksi** `crm_to_wqs`, `delivered`, `on_delivery`, `paid`, `ready_scm`, `sent_wqs`, `wait_payment`, `wqs_processing`
- **State** `COMPLETED`, `PAID`, `Pending`, `cancelled`, `closed`, `completed`, `delivered`, `on_delivery`, `paid`, `ready_scm`, `rejected`, `void`
- **Permission** `SALES.CREATE`, `SALES.DELETE`, `SALES.EDIT`, `SALES.EXPORT`, `SALES.VIEW`

#### `sales/sales_do_.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `ajax`, `edit`, `id`, `office_code`, `q`, `source`
- **Aksi** `bulk_action`, `crm_to_wqs`, `delivered`, `on_delivery`, `paid`, `ready_scm`, `sent_wqs`, `wait_payment`, `wqs_processing`
- **State** `PAID`, `delivered`, `on_delivery`, `paid`, `ready_scm`
- **Permission** `SALES.CREATE`, `SALES.DELETE`, `SALES.EDIT`, `SALES.EXPORT`, `SALES.VIEW`

#### `sales/sales_do_doc_download.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **Permission** `SALES.CREATE`, `SALES.EDIT`, `SALES.VIEW`

#### `sales/sales_do_print_cf.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **State** `DRAFT`
- **Permission** `SALES.PRINT`, `WQS.DO_TASKS`

#### `sales/sales_do_return.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `do_id`, `id`, `queue`, `return_id`
- **Aksi** `action`, `cancel_final_return_operator_error`, `complete_scm`, `correct_condition`, `correct_final_return_item`, `create_return`, `qc_complete`, `receive_return`, `set_return_commercial_effect`
- **State** `cancelled`, `closed`, `completed`, `delivered`, `on_delivery`, `paid`, `ready_scm`, `rejected`, `void`
- **Permission** `SALES.EDIT`, `SALES.VIEW`, `WQS.DO_TASKS`

#### `sales/sales_do_view.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `do_code`, `id`, `mode`
- **State** `cancelled`, `closed`, `delivered`, `on_delivery`, `paid`, `ready_scm`, `rejected`, `void`
- **Permission** `SALES.VIEW`

#### `sales/scm_delivery_recap.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `date_fr`, `date_to`, `export`, `mode`, `office`, `pod`, `q`
- **State** `closed`, `delivered`, `paid`
- **Permission** `SALES.EDIT`, `SALES.VIEW`

#### `sales/scm_do_tasks.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** Ya
- **Filter** `f_date_fr`, `f_date_to`, `f_q`, `f_status`
- **Aksi** `action`, `delivered`, `on_delivery`, `refresh_tracking`, `request_revision`, `save`
- **State** `DELIVERED`, `ON_DELIVERY`, `Open`, `PENDING`, `Pending`, `closed`, `completed`, `delivered`, `on_delivery`, `paid`, `ready_scm`
- **Permission** `SALES.EDIT`, `SALES.VIEW`
- **Event audit** `UPDATE`

#### `sales/scm_do_tasks__.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** Ya
- **Filter** `f_date_fr`, `f_date_to`, `f_q`, `f_status`
- **Aksi** `action`, `delivered`, `on_delivery`, `refresh_tracking`, `save`
- **State** `DELIVERED`, `ON_DELIVERY`, `Open`, `PENDING`, `Pending`, `closed`, `delivered`, `on_delivery`, `paid`, `ready_scm`
- **Permission** `SALES.EDIT`, `SALES.VIEW`
- **Event audit** `UPDATE`

#### `sales/scm_tracker_mobile.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `do_id`
- **State** `closed`, `completed`, `on_delivery`

#### `sales/scm_tracker_sop.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Permission** `MASTER.ADMIN_CENTER`, `SALES.EDIT`

#### `sales/scm_tracking_history.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `date`, `date_fr`, `do_id`, `office`, `pic_uid`, `q`
- **State** `delivered`, `on_delivery`
- **Permission** `MASTER.ADMIN_CENTER`, `SALES.EDIT`, `SALES.VIEW`, `SCM.SHIPMENT.TRACK.UPDATE`, `SCM.SHIPMENT.TRACK.VIEW`

#### `sales/tax_invoices.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `export`, `status`
- **Aksi** `action`, `create`, `set_status`, `upload_doc`
- **State** `CANCELLED`, `DRAFT`, `closed`, `delivered`, `paid`
- **Permission** `MASTER.ADMIN_CENTER`, `SALES.EDIT`

#### `sales/tracking_public.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **Filter** `demo`, `t`
- **State** `on_delivery`, `ready_scm`

#### `sales/tracking_public_CARTO.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **Filter** `demo`, `t`
- **State** `on_delivery`, `ready_scm`

#### `sales/tracking_public_Google.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **Filter** `demo`, `t`
- **State** `on_delivery`, `ready_scm`

#### `sales/tracking_public_live.php`

- **CRUD** R · **Auth gate** — · **Audit** —
- **Filter** `demo`, `t`
- **State** `on_delivery`

#### `sales/wqs_do_tasks.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** Ya
- **Filter** `show_cancelled`
- **Aksi** `action`, `archive_legacy_trials`, `cancel`, `ready`, `save`, `start`
- **State** `Cancelled`, `Open`, `Pending`, `VOID`, `cancelled`, `delivered`, `on_delivery`, `paid`, `ready`, `ready_scm`, `void`
- **Permission** `SALES.EDIT`, `SALES.VIEW`, `WQS.DO_TASKS`
- **Event audit** `APPROVE`, `UPDATE`

#### `stock/index.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Permission** `STOCK.VIEW`, `WQS.INCOMING_VIEW`, `WQS.PICKING_VIEW`, `WQS.PR_VIEW`, `WQS.VIEW`

#### `stock/wqs_allocation.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** Ya
- **Filter** `code`, `download`, `view`
- **Aksi** `action`, `allocate_one`, `import_csv`
- **Permission** `WQS.ALLOCATION_CREATE`, `WQS.ALLOCATION_EDIT`, `WQS.ALLOCATION_VIEW`, `WQS.INCOMING_CREATE`, `WQS.INCOMING_EDIT`, `WQS.INCOMING_VIEW`, `WQS.VIEW`
- **Event audit** `CREATE`, `POSTING`

#### `stock/wqs_do_tasks.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** Ya
- **Filter** `date_from`, `date_to`, `show_cancelled`
- **Aksi** `action`, `archive_legacy_trials`, `cancel`, `handover_save`, `ready`, `save`, `start`
- **State** `Cancelled`, `Open`, `Pending`, `VOID`, `cancelled`, `delivered`, `on_delivery`, `paid`, `ready`, `ready_scm`, `void`
- **Permission** `SALES.EDIT`, `SALES.VIEW`, `WQS.DO_TASKS`
- **Event audit** `APPROVE`, `UPDATE`

#### `stock/wqs_incoming.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** Ya
- **Filter** `date_from`, `date_to`, `download`, `filter_office`
- **Aksi** `action`, `create_incoming`, `import_csv`
- **State** `OPEN`, `READY`
- **Permission** `PURCHASES.GR_PROCESS`, `WQS.INCOMING_CREATE`, `WQS.INCOMING_EDIT`, `WQS.INCOMING_VIEW`, `WQS.VIEW`
- **Event audit** `POSTING`

#### `stock/wqs_incoming_po_api.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `po_code`
- **Permission** `PURCHASES.GR_PROCESS`, `WQS.INCOMING_CREATE`, `WQS.INCOMING_EDIT`, `WQS.INCOMING_VIEW`, `WQS.VIEW`

#### `stock/wqs_incoming_view.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Filter** `code`
- **Aksi** `action`, `delete_doc`, `upload_doc`
- **Permission** `PURCHASES.GR_PROCESS`, `WQS.INCOMING_CREATE`, `WQS.INCOMING_EDIT`, `WQS.INCOMING_VIEW`

#### `stock/wqs_picking.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** Ya
- **Filter** `do_code`, `download`
- **Aksi** `action`, `import_csv`, `save_picking`
- **State** `VOID`
- **Permission** `SALES.EDIT`, `WQS.PICKING_CREATE`, `WQS.PICKING_EDIT`, `WQS.PICKING_VIEW`, `WQS.VIEW`
- **Event audit** `POSTING`

#### `stock/wqs_picking_view.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **Permission** `SALES.EDIT`, `WQS.PICKING_CREATE`, `WQS.PICKING_EDIT`, `WQS.PICKING_VIEW`

#### `stock/wqs_pr.php`

- **CRUD** CRUD · **Auth gate** Ya · **Audit** —
- **Filter** `edit_pr`, `status`
- **Aksi** `action`, `bulk_action`, `bulk_delete`, `bulk_submit`
- **State** `CANCELLED`, `DRAFT`, `SUBMITTED`, `VOID`
- **Permission** `PURCHASES.PO_CREATE`, `PURCHASES.PO_EDIT`, `PURCHASES.PO_VIEW`, `WQS.PR_CREATE`, `WQS.PR_EDIT`, `WQS.PR_VIEW`, `WQS.VIEW`

#### `stock/wqs_pr_print.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `id`
- **Permission** `PURCHASES.PO_CREATE`, `PURCHASES.PO_EDIT`, `PURCHASES.PO_VIEW`, `WQS.PR_CREATE`, `WQS.PR_EDIT`, `WQS.PR_VIEW`

#### `stock/wqs_pr_view.php`

- **CRUD** RU · **Auth gate** Ya · **Audit** —
- **Filter** `id`, `recovered`
- **State** `CANCELLED`, `DRAFT`, `VOID`
- **Permission** `PURCHASES.PO_CREATE`, `PURCHASES.PO_EDIT`, `PURCHASES.PO_VIEW`, `WQS.PR_CREATE`, `WQS.PR_EDIT`, `WQS.PR_VIEW`, `WQS.VIEW`

#### `stock/wqs_quarantine.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `office`, `q`, `status`
- **Aksi** `action`, `hold`, `release`, `return_supplier`, `scrap`
- **State** `OPEN`

#### `stock/wqs_stock.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `business_group`, `mnf`, `office`, `product_type`, `q`, `zero`
- **Aksi** `action`, `import_csv`, `lock_baseline`, `set_stock`
- **Permission** `STOCK.CREATE`, `WQS.INCOMING_CREATE`, `WQS.INCOMING_EDIT`, `WQS.INCOMING_VIEW`, `WQS.PICKING_CREATE`, `WQS.PICKING_EDIT`, `WQS.PICKING_VIEW`, `WQS.PR_CREATE`, `WQS.PR_EDIT`, `WQS.PR_VIEW`

#### `stock/wqs_stock_.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** —
- **Filter** `mnf`, `office`, `q`, `zero`
- **Aksi** `action`, `import_csv`, `lock_baseline`, `set_stock`
- **Permission** `STOCK.CREATE`, `WQS.INCOMING_CREATE`, `WQS.INCOMING_EDIT`, `WQS.INCOMING_VIEW`, `WQS.PICKING_CREATE`, `WQS.PICKING_EDIT`, `WQS.PICKING_VIEW`, `WQS.PR_CREATE`, `WQS.PR_EDIT`, `WQS.PR_VIEW`

#### `stock/wqs_stock_adjustment.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** Ya
- **Filter** `action`, `sku`
- **Aksi** `action`, `add_adjustment`
- **Permission** `STOCK.CREATE`
- **Event audit** `POSTING`

#### `stock/wqs_stock_adjustmentidstok.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** Ya
- **Filter** `action`, `sku`
- **Aksi** `action`, `add_adjustment`
- **Permission** `STOCK.CREATE`
- **Event audit** `POSTING`

#### `stock/wqs_stock_audit.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `from`, `only_diff`, `sku`, `to`
- **Permission** `STOCK.AUDIT`, `STOCK.CREATE`, `WQS.INCOMING_CREATE`, `WQS.INCOMING_EDIT`, `WQS.INCOMING_VIEW`

#### `stock/wqs_stock_opname.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** Ya
- **Filter** `err`, `id`
- **Aksi** `action`, `add_items`, `apply`, `create`, `save`, `submit`, `upload_photo`, `verify_apply`
- **State** `DRAFT`
- **Permission** `STOCK.CREATE`, `WQS.INCOMING_CREATE`, `WQS.INCOMING_EDIT`, `WQS.INCOMING_VIEW`, `WQS.PICKING_CREATE`, `WQS.PICKING_EDIT`, `WQS.PICKING_VIEW`
- **Event audit** `POSTING`, `UPDATE`

#### `stock/wqs_stock_opname_report.php`

- **CRUD** R · **Auth gate** Ya · **Audit** —
- **Filter** `depo`, `export`, `from`, `id`, `office`, `status`, `to`
- **State** `DRAFT`, `delivered`, `on_delivery`, `paid`, `ready_scm`
- **Permission** `STOCK.CREATE`, `WQS.INCOMING_CREATE`, `WQS.INCOMING_EDIT`, `WQS.INCOMING_VIEW`, `WQS.PICKING_CREATE`, `WQS.PICKING_EDIT`, `WQS.PICKING_VIEW`

#### `stock/wqs_stock_transfer.php`

- **CRUD** CRU · **Auth gate** Ya · **Audit** Ya
- **Filter** `office`, `status`, `view`
- **Aksi** `action`, `create`, `receive`, `send`
- **State** `DRAFT`
- **Permission** `WQS.TRANSFER_CREATE`, `WQS.TRANSFER_VIEW`
- **Event audit** `CREATE`, `POSTING`, `UPDATE`

#### `tests/run.php`

- **CRUD** CRD · **Auth gate** — · **Audit** —
- **State** `DRAFT`, `POSTED`, `void`

#### `web/admin/ops/thresholds_edit.php`

- **CRUD** U · **Auth gate** Ya · **Audit** —
- **Aksi** `action`, `apply`
- **Permission** `SYSTEM.CONFIG_MANAGE`, `TOOLS.VIEW`

#### `web/admin/ops/thresholds_view.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Filter** `download`
- **Permission** `SYSTEM.CONFIG_MANAGE`, `TOOLS.VIEW`

#### `web/admin/rfc/approve.php`

- **CRUD** U · **Auth gate** Ya · **Audit** —
- **Filter** `rfc`
- **State** `APPROVED`, `approved`
- **Permission** `SYSTEM.CONFIG_MANAGE`, `TOOLS.VIEW`

#### `web/admin/rfc/index.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Permission** `SYSTEM.CONFIG_MANAGE`, `TOOLS.VIEW`

#### `web/admin/rfc/view.php`

- **CRUD** — · **Auth gate** Ya · **Audit** —
- **Filter** `rfc`
- **Permission** `SYSTEM.CONFIG_MANAGE`, `TOOLS.VIEW`

