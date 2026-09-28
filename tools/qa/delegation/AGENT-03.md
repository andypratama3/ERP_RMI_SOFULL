# AGENT-03 — Purchases / Procurement
Tasks: PR, PQP, PO, approval, GR, AP, filtering, update, workflow
Features: 40 (P0:1 P1:26) | Files: 40

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
- `purchases/_audit_helper.php`
- `purchases/_purchases_bootstrap.php`
- `purchases/_purchases_lib.php`
- `purchases/bank_recon.php`
- `purchases/bank_statement_import.php`
- `purchases/fin_gl_auto.php`
- `purchases/gl_reversal_approvals.php`
- `purchases/pqp_rfq.php`
- `purchases/pqp_rfq_download.php`
- `purchases/pqp_rfq_export.php`
- `purchases/pqp_rfq_helper.php`
- `purchases/purchases_ap_import.php`
- `purchases/purchases_ap_importreplace.php`
- `purchases/purchases_ap_importress.php`
- `purchases/purchases_ceisa_pib.php`
- `purchases/purchases_ceisa_pib_view.php`
- `purchases/purchases_control_tower.php`
- `purchases/purchases_dashboard.php`
- `purchases/purchases_dashboard_.php`
- `purchases/purchases_fin_po_process.php`
- `purchases/purchases_forwarder_invoice.php`
- `purchases/purchases_forwarder_payment.php`
- `purchases/purchases_forwarder_quotes.php`
- `purchases/purchases_forwarding_tasks.php`
- `purchases/purchases_gr.php`
- `purchases/purchases_gr_load_items.php`
- `purchases/purchases_import_control_tower.php`
- `purchases/purchases_import_control_view.php`
- `purchases/purchases_invoice_ap.php`
- `purchases/purchases_invoice_ap_edit.php`
- `purchases/purchases_payment_ap.php`
- `purchases/purchases_po.php`
- `purchases/purchases_po_fin_view.php`
- `purchases/purchases_po_print.php`
- `purchases/purchases_po_readonly_view.php`
- `purchases/purchases_po_view.php`
- `purchases/purchases_pr_api.php`
- `purchases/purchases_reports.php`
- `purchases/revbaru1.php`
- `purchases/stock_update_from_gr.php`

## Fitur P0 (kerjakan pertama)
- gl_reversal_approvals `purchases/gl_reversal_approvals.php` perms=MASTER.ADMIN_CENTER,PURCHASES.ADMIN_GL_AUTO,PURCHASES.AP_PAYMENT_CREATE,PURCHASES.AP_PAYMENT_EDIT,PURCHASES.AP_PAYMENT_VIEW

## Fitur P1
- _purchases_bootstrap `purchases/_purchases_bootstrap.php`
- bank_statement_import `purchases/bank_statement_import.php`
- pqp_rfq `purchases/pqp_rfq.php`
- pqp_rfq_download `purchases/pqp_rfq_download.php`
- pqp_rfq_export `purchases/pqp_rfq_export.php`
- pqp_rfq_helper `purchases/pqp_rfq_helper.php`
- purchases_ap_import `purchases/purchases_ap_import.php`
- purchases_ap_importreplace `purchases/purchases_ap_importreplace.php`
- purchases_ap_importress `purchases/purchases_ap_importress.php`
- purchases_fin_po_process `purchases/purchases_fin_po_process.php`
- purchases_forwarder_payment `purchases/purchases_forwarder_payment.php`
- purchases_gr `purchases/purchases_gr.php`
- purchases_gr_load_items `purchases/purchases_gr_load_items.php`
- purchases_import_control_tower `purchases/purchases_import_control_tower.php`
- purchases_import_control_view `purchases/purchases_import_control_view.php`
- purchases_invoice_ap `purchases/purchases_invoice_ap.php`
- purchases_invoice_ap_edit `purchases/purchases_invoice_ap_edit.php`
- purchases_payment_ap `purchases/purchases_payment_ap.php`
- purchases_po `purchases/purchases_po.php`
- purchases_po_fin_view `purchases/purchases_po_fin_view.php`
- purchases_po_print `purchases/purchases_po_print.php`
- purchases_po_readonly_view `purchases/purchases_po_readonly_view.php`
- purchases_po_view `purchases/purchases_po_view.php`
- purchases_pr_api `purchases/purchases_pr_api.php`
- purchases_reports `purchases/purchases_reports.php`
- stock_update_from_gr `purchases/stock_update_from_gr.php`

## Format hasil kembali ke LEAD
TASK_ID | FILES_CHANGED | TESTS_RUN | FAILURES_FOUND | FIXES_APPLIED | REGRESSION_RISK | STATUS (TODO/IN_PROGRESS/BLOCKED/FIXED/RETEST/PASS/FAIL/WAIVED) + evidence per PASS.
