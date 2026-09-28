# AGENT-10 — Dashboard / Chat / Help
Tasks: dashboard, landing, chat, context linking, documents, help center, panduan interaktif+tombol
Features: 28 (P0:0 P1:6) | Files: 28

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
- `chat/_chat_bootstrap.php`
- `chat/_chat_context.php`
- `chat/admin_exports.php`
- `chat/admin_settings.php`
- `chat/index.php`
- `dashboards/_audit_log_widget.php`
- `dashboards/_bootstrap.php`
- `dashboards/_dashboard_bootstrap.php`
- `dashboards/_dashboard_bootstraprevbaru1609.php`
- `dashboards/_dashboard_bootstraprevbaru2409.php`
- `dashboards/_funnels_data.php`
- `dashboards/_funnels_datarevnilai.php`
- `dashboards/_health.php`
- `dashboards/_manager_scope.php`
- `dashboards/_manager_scopeactok.php`
- `dashboards/_manager_scopecrmok.php`
- `dashboards/_manager_scoperevbaru2109.php`
- `dashboards/_manager_scoperevnilai.php`
- `dashboards/_manager_scopewqsok.php`
- `dashboards/_panduan_dashboard_hub.inc.php`
- `dashboards/_panduan_helpers.php`
- `dashboards/dashboard_center.php`
- `dashboards/funnels.php`
- `dashboards/funnelsbaru.php`
- `dashboards/funnelsrevnilai.php`
- `dashboards/index.php`
- `dashboards/indexrevbaru.php`
- `dashboards/indexrevbaru2509.php`

## Fitur P0 (kerjakan pertama)

## Fitur P1
- _bootstrap `dashboards/_bootstrap.php`
- _dashboard_bootstrap `dashboards/_dashboard_bootstrap.php`
- _dashboard_bootstraprevbaru1609 `dashboards/_dashboard_bootstraprevbaru1609.php`
- _dashboard_bootstraprevbaru2409 `dashboards/_dashboard_bootstraprevbaru2409.php`
- _chat_bootstrap `chat/_chat_bootstrap.php`
- admin_exports `chat/admin_exports.php`

## Format hasil kembali ke LEAD
TASK_ID | FILES_CHANGED | TESTS_RUN | FAILURES_FOUND | FIXES_APPLIED | REGRESSION_RISK | STATUS (TODO/IN_PROGRESS/BLOCKED/FIXED/RETEST/PASS/FAIL/WAIVED) + evidence per PASS.
