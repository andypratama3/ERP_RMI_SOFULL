# AGENT-12 — Portal / API
Tasks: portal, RFQ, API, auth, scope, integration, audit
Features: 26 (P0:4 P1:4) | Files: 26

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
- `api/health.php`
- `api/kpi_exec.json.php`
- `customer_portal/_auth.php`
- `customer_portal/_bootstrap.php`
- `customer_portal/cart.php`
- `customer_portal/catalog.php`
- `customer_portal/checkout.php`
- `customer_portal/do_print.php`
- `customer_portal/download_doc.php`
- `customer_portal/index.php`
- `customer_portal/layout.php`
- `customer_portal/login.php`
- `customer_portal/logout.php`
- `customer_portal/order_detail.php`
- `customer_portal/orders.php`
- `manufacturer_portal/_auth.php`
- `manufacturer_portal/_bootstrap.php`
- `manufacturer_portal/_lang.php`
- `manufacturer_portal/case_detail.php`
- `manufacturer_portal/cases.php`
- `manufacturer_portal/index.php`
- `manufacturer_portal/layout.php`
- `manufacturer_portal/login.php`
- `manufacturer_portal/logout.php`
- `manufacturer_portal/manufacture_docs.php`
- `manufacturer_portal/rfq.php`

## Fitur P0 (kerjakan pertama)
- _auth `customer_portal/_auth.php` perms=
- login `customer_portal/login.php` perms=
- _auth `manufacturer_portal/_auth.php` perms=
- login `manufacturer_portal/login.php` perms=

## Fitur P1
- _bootstrap `customer_portal/_bootstrap.php`
- do_print `customer_portal/do_print.php`
- _bootstrap `manufacturer_portal/_bootstrap.php`
- rfq `manufacturer_portal/rfq.php`

## Format hasil kembali ke LEAD
TASK_ID | FILES_CHANGED | TESTS_RUN | FAILURES_FOUND | FIXES_APPLIED | REGRESSION_RISK | STATUS (TODO/IN_PROGRESS/BLOCKED/FIXED/RETEST/PASS/FAIL/WAIVED) + evidence per PASS.
