# Landing Page Masing-masing Dept — Detail

## Alur Umum

1. **Setelah login** (tanpa `?next=`): redirect memakai **`auth_post_login_landing_path()`** = **`auth_landing_path_for_dept(department)`** — berlaku untuk **semua** user termasuk SYS/ADMIN/SUPERADMIN. Override per dept di **Master → Nav Manager → tab Landing** (`nav_overrides.json`).
2. **Default dept SYS** (tanpa override): **`/dashboards/index.php`** (Dashboard Center). Ingin langsung **Master System Login** → set di Nav Manager (preset atau custom).
3. **Dashboard Center**: Admin/privileged **tetap** bisa melihat hub kartu; user non-admin dari URL Dashboard Center bisa di-redirect ke landing dept (lihat `dashboards/index.php`).
4. **Masing-masing Dept**: Klik kartu → masuk ke landing page spesifik dept

---

## 1. Owner Executive Summary


| Item          | Detail                                                                                                  |
| ------------- | ------------------------------------------------------------------------------------------------------- |
| **Path**      | `/dashboards/owner/exec_summary.php`                                                                    |
| **Dept/Role** | SYS                                                                                                     |
| **Deskripsi** | Ringkasan bisnis untuk Owner                                                                            |
| **Konten**    | Revenue, AR, Inventory (proxy), PO pipeline, Cash balance, Weekly burn, OPEX (manual via system_config) |
| **Fitur**     | Live KPIs + Manual KPIs, fail-soft jika tabel/kolom belum ada                                           |


---

## 2. CRM / Sales Dashboard


| Item            | Detail                                                                                                                                            |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Path**        | `/sales/sales_dashboard.php` (via `/dashboards/sales/sales_dashboard.php` redirect)                                                               |
| **Dept**        | CRM, BRANCH                                                                                                                                       |
| **Deskripsi**   | Flow CRM → WQS → SCM → ACT → FIN + SLA (manager view)                                                                                             |
| **Konten**      | Stage counts (CRM/WQS/SCM/ACT/FIN), overdue per stage, CRM lead status (DRAFT/SUBMITTED/APPROVED/CLOSED/CANCELLED), filter date/department/status |
| **Quick Links** | Sales Control Tower, Tax Invoice, ACT/FIN tasks                                                                                                   |


---

## 3. MPR Dashboard (Medical Representative)


| Item          | Detail                                                                          |
| ------------- | ------------------------------------------------------------------------------- |
| **Path**      | `/mpr/mpr_dashboard.php`                                                        |
| **Dept**      | MPR, BRANCH                                                                     |
| **Deskripsi** | Plans, visits, progress, budget                                                 |
| **Konten**    | Plan aktif, submitted, kunjungan bulan ini, progress mingguan, budget submitted |
| **Filter**    | Scope by dept_code, office_code (non-admin)                                     |


---

## 4. Warehouse (WQS) Dashboard


| Item          | Detail                                                                         |
| ------------- | ------------------------------------------------------------------------------ |
| **Path**      | `/dashboards/warehouse/wqs_dashboard.php`                                      |
| **Dept**      | WQS, SCM, BRANCH                                                               |
| **Deskripsi** | Stock, Incoming, Allocation, Picking + expiry risk                             |
| **Konten**    | SKU onhand, incoming MTD, allocation, picking, expiry risk, chart 7/30/90 hari |
| **Export**    | `wqs_dashboard_export.php`                                                     |


---

## 5. Procurement / Import Dashboard


| Item          | Detail                                                                                 |
| ------------- | -------------------------------------------------------------------------------------- |
| **Path**      | `/purchases/purchases_import_control_tower.php` (fallback: `purchases_dashboard.php`)  |
| **Dept**      | PQP, SCM, FIN, ACT, WQS, BRANCH                                                        |
| **Deskripsi** | PR → PO → AP → Incoming (PQP/FIN/SCM/WQS)                                              |
| **Konten**    | PO list, forwarder status, production/ETD/ETA/arrived, CEISA/PIB, filter office/status |


---

## 6. Finance Dashboard (AR/AP)


| Item            | Detail                                                                                                    |
| --------------- | --------------------------------------------------------------------------------------------------------- |
| **Path**        | `/dashboards/finance/ar_ap_cash_dashboard.php`                                                            |
| **Dept**        | FIN, ACT, BRANCH                                                                                          |
| **Deskripsi**   | AR/AP summary + link ke task FIN                                                                          |
| **Konten**      | Cash balance, burn, runway, OPEX, AR outstanding/overdue, aging (0-30, 31-60, 61-90, 90+), filter periode |
| **Quick Links** | Finance Report, Config                                                                                    |


---

## 7. Finance Detail (Excel Style)


| Item          | Detail                                                                                 |
| ------------- | -------------------------------------------------------------------------------------- |
| **Path**      | `/dashboards/finance/dashboard_detail.php`                                             |
| **Dept**      | FIN, ACT, BRANCH                                                                       |
| **Deskripsi** | Target vs Pencapaian + Finance Detail per office (MTD As-Of)                           |
| **Konten**    | Tab target, drilldown per office/segment, anomaly threshold, motion mode (live/manual) |
| **Service**   | `App\Dashboard\DashboardDetailService`                                                 |


---

## 8. Regulatory & Compliance


| Item          | Detail                                                                                          |
| ------------- | ----------------------------------------------------------------------------------------------- |
| **Path**      | `/dashboards/regulatory/license_docs_dashboard.php`                                             |
| **Dept**      | PQP, HRL, FIN, ACT, BRANCH                                                                      |
| **Deskripsi** | Expiry NIE/AKL/AKD (dari master_products) + link dossier                                        |
| **Konten**    | Total produk, punya reg, missing reg, expiring 90 hari, expired, top 20 produk mendekati expiry |
| **Sumber**    | `master_products` (akl_reg_no, no_akl, licence_number, exp_date)                                |


---

## 9. Quality & Complaint


| Item          | Detail                                                                                                          |
| ------------- | --------------------------------------------------------------------------------------------------------------- |
| **Path**      | `/dashboards/quality/qc_complaint_dashboard.php`                                                                |
| **Dept**      | PQP, WQS, SCM, ACT, BRANCH                                                                                      |
| **Deskripsi** | Incoming QC, completeness lot/serial/exp, stock anomaly                                                         |
| **Konten**    | Incoming 30 hari, missing exp/lot/serial, stock total/zero/negative, baseline lock, produk missing barcode/unit |
| **Catatan**   | Placeholder untuk complaint/CAPA (schema belum ada)                                                             |


---

## 10. SCM Dashboard


| Item            | Detail                                                   |
| --------------- | -------------------------------------------------------- |
| **Path**        | `/dashboards/scm/scm_dashboard.php`                      |
| **Dept**        | SCM, BRANCH                                              |
| **Deskripsi**   | Supply Chain Management — Import, Procurement, Logistics |
| **Konten**      | PR submitted, PO open, Incoming MTD, backlog wqs_pr      |
| **Quick Links** | WQS PR, Purchases PO, Incoming                           |


---

## 11. HRL Dashboard


| Item            | Detail                                               |
| --------------- | ---------------------------------------------------- |
| **Path**        | `/dashboards/hrl/hrl_dashboard.php`                  |
| **Dept**        | HRL, BRANCH                                          |
| **Deskripsi**   | Human Resource & Legal — Docs, Process, Absensi, KPI |
| **Konten**      | Total dokumen HRL, absensi hari ini, karyawan aktif  |
| **Quick Links** | Dashboard Center, KPI Center                         |


---

## 12. ITC Dashboard


| Item            | Detail                                                                                                       |
| --------------- | ------------------------------------------------------------------------------------------------------------ |
| **Path**        | `/dashboards/itc/itc_dashboard.php`                                                                          |
| **Dept**        | ITC                                                                                                          |
| **Deskripsi**   | IT & Cloud — Master Data, RBAC, Tools                                                                        |
| **Konten**      | Quick links ke RBAC, Tools, Reset Password, API Keys, MFA, Security, Health, Absensi, KPI                    |
| **Quick Links** | HRL Process, RBAC, Tools, ITC Reset Password, API Partner Keys, MFA Settings, Security, Health, Absensi, KPI |


---

## 13. ACT Dashboard (Accounting & Tax)


| Item          | Detail                                                                                                                                                     |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Path**      | `/dashboards/act/act_dashboard.php`                                                                                                                        |
| **Dept**      | ACT, BRANCH                                                                                                                                                |
| **Deskripsi** | Accounting & Tax — Fixed Asset, Finance, AR/AP                                                                                                             |
| **Konten**    | Quick links ke Tax Invoice, HRL Process, Fixed Asset, ACT Task DO, AP Invoice/Payment, Bank Recon, Rekening, Tax Annual, Absensi, KPI, Sales Control Tower |


---

## Mapping RBAC (Dept → Dashboard)


| Dept   | Dashboard yang bisa diakses                           |
| ------ | ----------------------------------------------------- |
| CRM    | CRM Dashboard                                         |
| BRANCH | Semua (kecuali Owner, ITC)                            |
| MPR    | MPR Dashboard                                         |
| WQS    | Warehouse, Quality                                    |
| SCM    | Warehouse, Procurement, SCM, Quality                  |
| PQP    | Procurement, Regulatory, Quality                      |
| FIN    | Procurement, Finance, Finance Detail, Regulatory      |
| ACT    | Procurement, Finance, Finance Detail, Regulatory, ACT |
| HRL    | Regulatory, HRL                                       |
| ITC    | ITC Dashboard                                         |
| SYS    | Semua menu + Executive Summary                        |


---

## Modul Index (Redirect ke Dashboard)


| Modul     | Path                   | Redirect ke               |
| --------- | ---------------------- | ------------------------- |
| sales     | `/sales/index.php`     | `sales_dashboard.php`     |
| purchases | `/purchases/index.php` | `purchases_dashboard.php` |
| stock     | `/stock/index.php`     | `wqs_stock.php`           |


