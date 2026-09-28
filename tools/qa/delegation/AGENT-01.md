# AGENT-01 — Master Data
Tasks: inventory, CRUD, filtering, update, RBAC, audit
Features: 76 (P0:8 P1:22) | Files: 76

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
- `master/_audit_helper.php`
- `master/_audit_master.php`
- `master/_guard.php`
- `master/_import_tools.php`
- `master/account_readiness.php`
- `master/api_partner_keys.php`
- `master/audit_logs.php`
- `master/auth.php`
- `master/auth_.php`
- `master/auth__.php`
- `master/company_bank_accounts.php`
- `master/doc_numbering_edit.php`
- `master/import_rekening_final.php`
- `master/index.php`
- `master/itc_reset_password.php`
- `master/jobs_monitor.php`
- `master/login.php`
- `master/logout.php`
- `master/manufactures_docs.php`
- `master/manufactures_docsbaru.php`
- `master/manufactures_docsrevbaru1609.php`
- `master/master_customer_portal_users.php`
- `master/master_customers lama.php`
- `master/master_customers.php`
- `master/master_customers_.php`
- `master/master_data.php`
- `master/master_departements.php`
- `master/master_emailcompany.php`
- `master/master_employees.php`
- `master/master_employeesdell.php`
- `master/master_employeesid.php`
- `master/master_employeesjaba.php`
- `master/master_employeesmasakontrak.php`
- `master/master_export_customers.php`
- `master/master_import_customers.php`
- `master/master_import_products.php`
- `master/master_import_vendors.php`
- `master/master_manufacturer_portal_users.php`
- `master/master_manufactures.php`
- `master/master_office.php`
- `master/master_payment_terms.php`
- `master/master_pricelist.php`
- `master/master_pricelist_sell.php`
- `master/master_product_media_bulk.php`
- `master/master_products.php`
- `master/master_products_doc.php`
- `master/master_products_package.php`
- `master/master_products_print.php`
- `master/master_system_config.php`
- `master/master_system_login.php`
- `master/master_system_loginid.php`
- `master/master_system_loginmalang.php`
- `master/master_tax.php`
- `master/master_user.php`
- `master/master_user2809.php`
- `master/master_user_.php`
- `master/master_vendors.php`
- `master/mfa_admin_reset.php`
- `master/mfa_bypass.php`
- `master/mfa_policy.php`
- `master/mfa_settings.php`
- `master/mfa_verify.php`
- `master/monitoring_center.php`
- `master/monitoring_centerrevhistory.php`
- `master/nav_manager.php`
- `master/org_structure_edit.php`
- `master/owner_activity_control.php`
- `master/owner_activity_control26.php`
- `master/owner_activity_control26_2.php`
- `master/products_media_view.php`
- `master/products_media_view_.php`
- `master/rate_limit_policies.php`
- `master/scan_product_media_folder.php`
- `master/schema_mfa.php`
- `master/security.php`
- `master/webauthn_api.php`

## Fitur P0 (kerjakan pertama)
- auth `master/auth.php` perms=
- auth_ `master/auth_.php` perms=
- auth__ `master/auth__.php` perms=
- login `master/login.php` perms=
- master_system_login `master/master_system_login.php` perms=SYSTEM.USER_MANAGE
- master_system_loginid `master/master_system_loginid.php` perms=SYSTEM.USER_MANAGE
- master_system_loginmalang `master/master_system_loginmalang.php` perms=SYSTEM.USER_MANAGE
- webauthn_api `master/webauthn_api.php` perms=

## Fitur P1
- _import_tools `master/_import_tools.php`
- api_partner_keys `master/api_partner_keys.php`
- import_rekening_final `master/import_rekening_final.php`
- master_customer_portal_users `master/master_customer_portal_users.php`
- master_export_customers `master/master_export_customers.php`
- master_import_customers `master/master_import_customers.php`
- master_import_products `master/master_import_products.php`
- master_import_vendors `master/master_import_vendors.php`
- master_manufacturer_portal_users `master/master_manufacturer_portal_users.php`
- master_payment_terms `master/master_payment_terms.php`
- master_pricelist `master/master_pricelist.php`
- master_pricelist_sell `master/master_pricelist_sell.php`
- master_product_media_bulk `master/master_product_media_bulk.php`
- master_products `master/master_products.php`
- master_products_doc `master/master_products_doc.php`
- master_products_package `master/master_products_package.php`
- master_products_print `master/master_products_print.php`
- mfa_policy `master/mfa_policy.php`
- products_media_view `master/products_media_view.php`
- products_media_view_ `master/products_media_view_.php`
- rate_limit_policies `master/rate_limit_policies.php`
- scan_product_media_folder `master/scan_product_media_folder.php`

## Format hasil kembali ke LEAD
TASK_ID | FILES_CHANGED | TESTS_RUN | FAILURES_FOUND | FIXES_APPLIED | REGRESSION_RISK | STATUS (TODO/IN_PROGRESS/BLOCKED/FIXED/RETEST/PASS/FAIL/WAIVED) + evidence per PASS.
