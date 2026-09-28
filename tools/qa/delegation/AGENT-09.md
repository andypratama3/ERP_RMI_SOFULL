# AGENT-09 — KPI / MPR / Fixed Asset
Tasks: KPI, MPR, assets, depreciation, reports, filtering, update
Features: 40 (P0:0 P1:21) | Files: 40

## Aturan absolut
- DILARANG melemahkan RBAC/permission/CSRF/auth/office-scope/audit/workflow demi PASS.
- DILARANG menghapus fungsi/menu agar tes lolos. DILARANG menyembunyikan error.
- PASS wajib evidence: command+result+file/route+timestamp. Tanpa evidence = bukan PASS.
- Yang tak bisa dites karena dependensi luar (mis. NAS /volume4) = BLOCKED + blocker tertulis, BUKAN PASS.
- `php -l` wajib bersih untuk setiap file PHP yang diubah.
- File SHARED (lihat CONFLICT_MAP) HANYA di Wave 2, satu agen dalam satu waktu.

## Urutan kerja
1. P0 dulu, lalu P1, lalu P2. 2. Tiap fitur: baca source → reproduce → root cause → fix terkecil → php -l → focused test → RBAC test (guest/tanpa-izin/beda-office/beda-dept/direct URL) → audit test → filtering (F01-F12) utk list → update (U01-U10) utk edit → retest → tulis evidence ke tracker.

## Standar uji (ringkas)
- Filtering F01-F12: keyword, exact, multi, date-range, status, office, dept, PIC, pagination, reset, empty, permission-scoped.
- Update U01-U10: valid, invalid, unauthorized, wrong-office, wrong-dept, CSRF-fail, stale, double-submit, workflow-invalid, audit-after-update.
- RBAC: guest, tanpa permission, role benar, dept salah, office salah, privilege rendah/tinggi, SYS bila relevan; UI + DIRECT URL; unauthorized != HTTP 200 sukses.

## File scope (EKSKLUSIF di Wave 1 — jangan sentuh file agen lain)
- `kpi/_kpi_bootstrap.php`
- `kpi/_kpi_metrics.php`
- `kpi/_kpi_policy.php`
- `kpi/kpi_audit.php`
- `kpi/kpi_auditaudit.php`
- `kpi/kpi_auditbaru.php`
- `kpi/kpi_center.php`
- `kpi/kpi_dashboard_daily.php`
- `kpi/kpi_dashboard_monthly.php`
- `kpi/kpi_do_audit.php`
- `kpi/kpi_do_auditaudit.php`
- `kpi/kpi_do_sla.php`
- `kpi/kpi_employee.php`
- `kpi/kpi_lib.php`
- `kpi/kpi_office.php`
- `kpi/kpi_purchases.php`
- `kpi/kpi_snapshot.php`
- `kpi/kpi_stock.php`
- `kpi/kpi_sync.php`
- `mpr/_layout_bottom.php`
- `mpr/_layout_top.php`
- `mpr/_opcache_fix.php`
- `mpr/mpr_access.php`
- `mpr/mpr_accessbranch.php`
- `mpr/mpr_api_contacts.php`
- `mpr/mpr_budget_fin.php`
- `mpr/mpr_dashboard.php`
- `mpr/mpr_gps_capture.php`
- `mpr/mpr_ops_daily_fin.php`
- `mpr/mpr_ops_daily_fin_detail.php`
- `mpr/mpr_ops_daily_fin_detail_.php`
- `mpr/mpr_ops_daily_fin_export.php`
- `mpr/mpr_ops_daily_fin_pay.php`
- `mpr/mpr_pipeline.php`
- `mpr/mpr_plan_view.php`
- `mpr/mpr_plan_view_.php`
- `mpr/mpr_plans.php`
- `mpr/mpr_plansrev2809.php`
- `mpr/mpr_visits.php`
- `mpr/mpr_visitsrev2809.php`

## Fitur P0 (kerjakan pertama)

## Fitur P1
- mpr_access `mpr/mpr_access.php`
- mpr_accessbranch `mpr/mpr_accessbranch.php`
- mpr_api_contacts `mpr/mpr_api_contacts.php`
- mpr_budget_fin `mpr/mpr_budget_fin.php`
- mpr_dashboard `mpr/mpr_dashboard.php`
- mpr_gps_capture `mpr/mpr_gps_capture.php`
- mpr_ops_daily_fin `mpr/mpr_ops_daily_fin.php`
- mpr_ops_daily_fin_detail `mpr/mpr_ops_daily_fin_detail.php`
- mpr_ops_daily_fin_detail_ `mpr/mpr_ops_daily_fin_detail_.php`
- mpr_ops_daily_fin_export `mpr/mpr_ops_daily_fin_export.php`
- mpr_ops_daily_fin_pay `mpr/mpr_ops_daily_fin_pay.php`
- mpr_pipeline `mpr/mpr_pipeline.php`
- mpr_plan_view `mpr/mpr_plan_view.php`
- mpr_plan_view_ `mpr/mpr_plan_view_.php`
- mpr_plans `mpr/mpr_plans.php`
- mpr_plansrev2809 `mpr/mpr_plansrev2809.php`
- mpr_visits `mpr/mpr_visits.php`
- mpr_visitsrev2809 `mpr/mpr_visitsrev2809.php`
- _kpi_bootstrap `kpi/_kpi_bootstrap.php`
- _kpi_policy `kpi/_kpi_policy.php`
- kpi_snapshot `kpi/kpi_snapshot.php`

## Format hasil kembali ke LEAD
TASK_ID | FILES_CHANGED | TESTS_RUN | FAILURES_FOUND | FIXES_APPLIED | REGRESSION_RISK | STATUS (TODO/IN_PROGRESS/BLOCKED/FIXED/RETEST/PASS/FAIL/WAIVED) + evidence per PASS.
