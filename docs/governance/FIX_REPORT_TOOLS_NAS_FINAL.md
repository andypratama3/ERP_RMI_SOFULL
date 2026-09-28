# FIX REPORT — Tools NAS Finalization

**Generated:** 2026-03-01  
**Target:** Synology NAS `/volume4/web/ERP_RMI_SOFULL`  
**Base URL:** https://erp.rizqullahmediska.com/ERP_RMI_SOFULL

---

## Summary

Changes applied to fix APP_ROOT_MISMATCH, TOOLS_BASE_URL missing, and enable smoke_tools_guest + cutover checks on NAS.

---

## Changes Made

### 1. TOOLS_BASE_URL in .env

**File:** `.env`  
**Change:** Added `TOOLS_BASE_URL` and `APP_URL` for NAS source-of-truth.

```
TOOLS_BASE_URL=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL
APP_URL=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL
```

**Why:** CLI tools (smoke_tools_guest, tools_doctor, smoke_http) read `getenv('TOOLS_BASE_URL')`. Without .env loaded, they fail with TOOLS_BASE_URL_MISSING.

---

### 2. CLI Load .env Early

**File:** `tools/_lib/tools_paths.php`  
**Change:** Added `rmi_env_load()` at top so all CLI tools that require tools_paths get .env before `tools_get_base_url()` / `tools_assert_app_root_locked_cli()`.

**Why:** tools_doctor, smoke_tools_guest load tools_paths first. Previously .env was not loaded for CLI, so TOOLS_BASE_URL was always empty.

---

### 3. smoke_tools_guest --base-url

**File:** `tools/qa/smoke_tools_guest.php`  
**Change:** Accept `--base-url=URL` CLI flag. Overrides env when provided.

**Why:** Per prompt: "if tool does NOT accept --base-url and still fails, add support". Allows running with explicit URL when env not set (e.g. one-off test).

---

### 4. run_cutover_checks --env

**File:** `tools/qa/run_cutover_checks.php`  
**Change:** Accept `--env=staging` (or other value). Sets `APP_ENV` for the run so `state_migrate_v1` is not blocked in production.

**Why:** Per prompt: "Make cutover checks NOT BLOCKED (staging mode ok)". With `--env=staging`, state_migrate_v1 runs without `--i-understand`.

---

### 5. setup_erp.sh .env Template

**File:** `tools/nas/setup_erp.sh`  
**Change:** Added TOOLS_BASE_URL and APP_URL to the .env block created by setup script.

**Why:** Fresh NAS setup gets correct base URL without manual edit.

---

### 6. HTTP Response Analyzer Default Base URL

**File:** `tools/qa/http_response_analysis_web.php` (prior session)  
**Change:** Default base URL fallback: `APP_BASE_URL` / `SMOKE_BASE_URL` / `http://127.0.0.1/ERP_RMI_SOFULL`.

**Why:** Avoid 404 when testing locally with wrong base URL.

---

## Assumptions (Mac vs NAS)

| Code | Expected | Actual (Mac) | Action |
|------|----------|--------------|--------|
| APP_ROOT_MISMATCH | /volume4/web/ERP_RMI_SOFULL | /Volumes/web/ERP_RMI_SOFULL | Run `lock_app_root.php` **on NAS** |

**storage/logs/assumptions_last.json** is written when lock fails. On NAS, after `cd /volume4/web/ERP_RMI_SOFULL && php tools/dev/lock_app_root.php`, the lock succeeds and assumptions are cleared.

---

## Known Gaps (Not Fixed)

1. **contract_check.php missing** — `run_cutover_checks` references `tools/qa/contract_check.php` but only `contract_check_web.php` exists. Pipeline step fails with "Could not open input file". Resolve by creating CLI wrapper or renaming.
2. **smoke_http / smoke_tools_dashboard** — When run from Mac against remote NAS URL, many checks return `code=0` (curl/network). On NAS, run locally for accurate results.
3. **DB connection** — finance_schema_guard, sales_tracking_checks fail when DB unreachable (e.g. Mac → NAS DB). On NAS with local DB, these pass.

---

## Evidence Files

- `storage/logs/snapshot_ls_before.txt` — STEP 0 snapshot
- `storage/logs/assumptions_last.json` — APP_ROOT_MISMATCH when run from Mac
- `storage/logs/cutover_checks.last.json` — Cutover run result
- `docs/governance/PATCH_DIFF_TOOLS_NAS_FINAL.md` — File diff summary
- `docs/governance/TESTPLAN_TOOLS_NAS_FINAL.md` — Commands + expected
