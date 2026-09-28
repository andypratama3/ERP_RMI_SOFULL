# PATCH DIFF — APP ROOT LOCK

## Files Added

| File | Purpose |
|------|---------|
| `.expected_app_root` | Source of truth (1 line path) |
| `storage/logs/assumptions_app_root_lock.md` | Assumptions log |
| `docs/governance/OPS_APP_ROOT_LOCK.md` | Documentation |

## Files Modified

| File | Change |
|------|--------|
| `tools/_shared/app_root_guard.php` | Rewrite: read .expected_app_root, tools_assert_expected_app_root() |
| `tools/_lib/bootstrap.php` | Add require app_root_guard + tools_assert_expected_app_root() |
| `tools/qa/run_cutover_checks.php` | Add guard at top (fail fast) |
| `tools/qa/run_all_checks.php` | Add guard at top (fail fast) |
| `tools/index.php` | Add APP_ROOT LOCK badge (optional) |
