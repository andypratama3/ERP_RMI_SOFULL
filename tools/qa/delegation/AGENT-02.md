# AGENT-02 — Sales / CRM
Tasks: SO, DO, CRM, WQS handoff, filtering, update, workflow, audit
Features: 40 (P0:0 P1:12) | Files: 40

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
- `sales/_ar_helper.php`
- `sales/_audit_helper.php`
- `sales/_debug_branch.php`
- `sales/_do_office_scope.php`
- `sales/_do_task_helpers.php`
- `sales/act_do_tasks.php`
- `sales/act_do_upload_diagnostic.php`
- `sales/backfill_sales_do_audit_crm.php`
- `sales/backfill_sales_do_audit_stages.php`
- `sales/crm_leads.php`
- `sales/export_kpi_do_csv.php`
- `sales/fin_ar_import.php`
- `sales/fin_ar_recap.php`
- `sales/fin_do_tasks.php`
- `sales/kpi_do_audit.php`
- `sales/kpi_do_sla.php`
- `sales/kpi_do_sla_fixed_staff_v6.php`
- `sales/one_time_fix_DO004_commercial_effect.php`
- `sales/one_time_fix_DO010_DO012_replacement_link.php`
- `sales/sales_control_tower.php`
- `sales/sales_dashboard.php`
- `sales/sales_do.php`
- `sales/sales_do_.php`
- `sales/sales_do_doc_download.php`
- `sales/sales_do_print_cf.php`
- `sales/sales_do_rekap.php`
- `sales/sales_do_return.php`
- `sales/sales_do_view.php`
- `sales/scm_delivery_recap.php`
- `sales/scm_do_tasks.php`
- `sales/scm_do_tasks__.php`
- `sales/scm_tracker_mobile.php`
- `sales/scm_tracker_sop.php`
- `sales/scm_tracking_history.php`
- `sales/tax_invoices.php`
- `sales/tracking_public.php`
- `sales/tracking_public_CARTO.php`
- `sales/tracking_public_Google.php`
- `sales/tracking_public_live.php`
- `sales/wqs_do_tasks.php`

## Fitur P0 (kerjakan pertama)

## Fitur P1
- _do_task_helpers `sales/_do_task_helpers.php`
- act_do_tasks `sales/act_do_tasks.php`
- export_kpi_do_csv `sales/export_kpi_do_csv.php`
- fin_ar_import `sales/fin_ar_import.php`
- fin_ar_recap `sales/fin_ar_recap.php`
- fin_do_tasks `sales/fin_do_tasks.php`
- sales_do_print_cf `sales/sales_do_print_cf.php`
- sales_do_rekap `sales/sales_do_rekap.php`
- scm_delivery_recap `sales/scm_delivery_recap.php`
- scm_do_tasks `sales/scm_do_tasks.php`
- scm_do_tasks__ `sales/scm_do_tasks__.php`
- wqs_do_tasks `sales/wqs_do_tasks.php`

## Format hasil kembali ke LEAD
TASK_ID | FILES_CHANGED | TESTS_RUN | FAILURES_FOUND | FIXES_APPLIED | REGRESSION_RISK | STATUS (TODO/IN_PROGRESS/BLOCKED/FIXED/RETEST/PASS/FAIL/WAIVED) + evidence per PASS.
