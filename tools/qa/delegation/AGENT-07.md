# AGENT-07 — FIN
Tasks: central approval, maker-checker, MgrFIN_BGR, SYS. Verify unauthorized FIN roles cannot central-approve
Features: 0 (P0:0 P1:0) | Files: 10

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
- `mpr/mpr_budget_fin.php`
- `mpr/mpr_ops_daily_fin.php`
- `mpr/mpr_ops_daily_fin_detail.php`
- `mpr/mpr_ops_daily_fin_export.php`
- `mpr/mpr_ops_daily_fin_pay.php`
- `purchases/fin_gl_auto.php`
- `purchases/purchases_fin_po_process.php`
- `purchases/purchases_po_fin_view.php`
- `sales/fin_ar_import.php`
- `sales/fin_ar_recap.php`

## Fitur P0 (kerjakan pertama)

## Fitur P1

## Format hasil kembali ke LEAD
TASK_ID | FILES_CHANGED | TESTS_RUN | FAILURES_FOUND | FIXES_APPLIED | REGRESSION_RISK | STATUS (TODO/IN_PROGRESS/BLOCKED/FIXED/RETEST/PASS/FAIL/WAIVED) + evidence per PASS.

## Catatan konflik (Wave 1: TEST-ONLY, edit oleh pemilik)
- `mpr/mpr_budget_fin.php` → edit Wave1: AGENT-09; test-only: AGENT-07
- `purchases/fin_gl_auto.php` → edit Wave1: AGENT-03; test-only: AGENT-07
- `sales/fin_ar_import.php` → edit Wave1: AGENT-02; test-only: AGENT-07
