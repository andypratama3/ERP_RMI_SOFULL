# AGENT-08 — HRL / HR / Absensi / Payroll
Tasks: requests, approvals, attendance, payroll, filtering, update, audit
Features: 57 (P0:1 P1:15) | Files: 57

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
- `absensi/_layout_bottom.php`
- `absensi/_layout_top.php`
- `absensi/admin.php`
- `absensi/approval.php`
- `absensi/checkin.php`
- `absensi/checkout.php`
- `absensi/history.php`
- `absensi/index.php`
- `absensi/izin.php`
- `absensi/kiosk.php`
- `absensi/photo.php`
- `absensi/request.php`
- `hrl/_layout_bottom.php`
- `hrl/_layout_top.php`
- `hrl/hr_report_center.php`
- `hrl/hrl_ack_report.php`
- `hrl/hrl_doc_download.php`
- `hrl/hrl_doc_view.php`
- `hrl/hrl_docs.php`
- `hrl/hrl_tower.php`
- `hrl_process/_layout_bottom.php`
- `hrl_process/_layout_top.php`
- `hrl_process/debug_access.php`
- `hrl_process/debug_accessmgr.php`
- `hrl_process/download.php`
- `hrl_process/employee_mutation_apply_due.php`
- `hrl_process/employee_mutation_view.php`
- `hrl_process/employee_mutations.php`
- `hrl_process/gps_debug.php`
- `hrl_process/index.php`
- `hrl_process/indexmgr.php`
- `hrl_process/leave_adjustment.php`
- `hrl_process/leave_adjustmentedit.php`
- `hrl_process/my_pin.php`
- `hrl_process/request_print.php`
- `hrl_process/request_view.php`
- `hrl_process/tower.php`
- `hrl_reg_alkes/_stage_log_helper.php`
- `hrl_reg_alkes/index.php`
- `hrl_reg_alkes/reg_alkes.php`
- `hrl_reg_alkes/reg_alkes_case.php`
- `hrl_reg_alkes/reg_alkes_control_tower.php`
- `hrl_reg_alkes/reg_alkes_expiry_check.php`
- `hrl_reg_alkes/reg_alkes_export_compliance.php`
- `hrl_reg_alkes/reg_alkes_sku_by_nie.php`
- `payroll/audit.php`
- `payroll/index.php`
- `payroll/loans.php`
- `payroll/payroll_absensi_manual_fix.php`
- `payroll/payroll_calendar.php`
- `payroll/payroll_fix_run_from_settings.php`
- `payroll/payroll_repair_run_matrix_absensi.php`
- `payroll/payroll_run.php`
- `payroll/payroll_settings.php`
- `payroll/payroll_sync_helpers.php`
- `payroll/payslip.php`
- `payroll/salary_matrix.php`

## Fitur P0 (kerjakan pertama)
- approval `absensi/approval.php` perms=

## Fitur P1
- hr_report_center `hrl/hr_report_center.php`
- hrl_ack_report `hrl/hrl_ack_report.php`
- debug_accessmgr `hrl_process/debug_accessmgr.php`
- employee_mutation_apply_due `hrl_process/employee_mutation_apply_due.php`
- indexmgr `hrl_process/indexmgr.php`
- request_print `hrl_process/request_print.php`
- reg_alkes_export_compliance `hrl_reg_alkes/reg_alkes_export_compliance.php`
- payroll_absensi_manual_fix `payroll/payroll_absensi_manual_fix.php`
- payroll_calendar `payroll/payroll_calendar.php`
- payroll_fix_run_from_settings `payroll/payroll_fix_run_from_settings.php`
- payroll_repair_run_matrix_absensi `payroll/payroll_repair_run_matrix_absensi.php`
- payroll_run `payroll/payroll_run.php`
- payroll_settings `payroll/payroll_settings.php`
- payroll_sync_helpers `payroll/payroll_sync_helpers.php`
- payslip `payroll/payslip.php`

## Format hasil kembali ke LEAD
TASK_ID | FILES_CHANGED | TESTS_RUN | FAILURES_FOUND | FIXES_APPLIED | REGRESSION_RISK | STATUS (TODO/IN_PROGRESS/BLOCKED/FIXED/RETEST/PASS/FAIL/WAIVED) + evidence per PASS.
