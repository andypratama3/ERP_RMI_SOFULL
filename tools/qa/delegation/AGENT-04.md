# AGENT-04 — WQS / Warehouse / Stock
Tasks: stock, incoming, allocation, picking, DO task, transfer, adjustment, opname, filtering, update
Features: 24 (P0:0 P1:12) | Files: 24

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
- `stock/_audit_helper.php`
- `stock/_stock_office_helper.php`
- `stock/_stock_office_helperalurdo.php`
- `stock/_wqs_bootstrap.php`
- `stock/index.php`
- `stock/wqs_allocation.php`
- `stock/wqs_do_tasks.php`
- `stock/wqs_incoming.php`
- `stock/wqs_incoming_po_api.php`
- `stock/wqs_incoming_view.php`
- `stock/wqs_picking.php`
- `stock/wqs_picking_view.php`
- `stock/wqs_pr.php`
- `stock/wqs_pr_print.php`
- `stock/wqs_pr_view.php`
- `stock/wqs_quarantine.php`
- `stock/wqs_stock.php`
- `stock/wqs_stock_.php`
- `stock/wqs_stock_adjustment.php`
- `stock/wqs_stock_adjustmentidstok.php`
- `stock/wqs_stock_audit.php`
- `stock/wqs_stock_opname.php`
- `stock/wqs_stock_opname_report.php`
- `stock/wqs_stock_transfer.php`

## Fitur P0 (kerjakan pertama)

## Fitur P1
- _wqs_bootstrap `stock/_wqs_bootstrap.php`
- wqs_allocation `stock/wqs_allocation.php`
- wqs_do_tasks `stock/wqs_do_tasks.php`
- wqs_incoming `stock/wqs_incoming.php`
- wqs_incoming_po_api `stock/wqs_incoming_po_api.php`
- wqs_incoming_view `stock/wqs_incoming_view.php`
- wqs_picking `stock/wqs_picking.php`
- wqs_picking_view `stock/wqs_picking_view.php`
- wqs_pr `stock/wqs_pr.php`
- wqs_pr_print `stock/wqs_pr_print.php`
- wqs_pr_view `stock/wqs_pr_view.php`
- wqs_stock_opname_report `stock/wqs_stock_opname_report.php`

## Format hasil kembali ke LEAD
TASK_ID | FILES_CHANGED | TESTS_RUN | FAILURES_FOUND | FIXES_APPLIED | REGRESSION_RISK | STATUS (TODO/IN_PROGRESS/BLOCKED/FIXED/RETEST/PASS/FAIL/WAIVED) + evidence per PASS.
