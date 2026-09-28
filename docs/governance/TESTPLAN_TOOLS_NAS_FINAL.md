# TESTPLAN — Tools NAS Finalization

**Target:** Synology NAS `/volume4/web/ERP_RMI_SOFULL`  
**Base URL:** https://erp.rizqullahmediska.com/ERP_RMI_SOFULL

---

## Prerequisites

1. SSH to NAS: `ssh admin@[NAS-IP]`
2. `cd /volume4/web/ERP_RMI_SOFULL`
3. Ensure `.env` has:
   ```
   TOOLS_BASE_URL=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL
   APP_URL=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL
   ```

---

## STEP 0 — Safety Snapshot

```bash
cd /volume4/web/ERP_RMI_SOFULL
pwd  # MUST output /volume4/web/ERP_RMI_SOFULL
ls -la > storage/logs/snapshot_ls_before.txt
```

**Expected:** File created, no error.

---

## STEP 1 — Lock APP_ROOT

```bash
cd /volume4/web/ERP_RMI_SOFULL
php tools/dev/lock_app_root.php
```

**Expected:**
```
OK: app_root locked at /volume4/web/ERP_RMI_SOFULL
  Lock file: [APP_ROOT]/storage/logs/app_root_lock.json
```
Exit code: 0.

**Verify:** `storage/logs/app_root_lock.json` exists, `app_root` = `/volume4/web/ERP_RMI_SOFULL`.

---

## STEP 2 — Verify TOOLS_BASE_URL

```bash
cd /volume4/web/ERP_RMI_SOFULL
php -r "
require_once '_shared/env.php';
rmi_env_load();
echo getenv('TOOLS_BASE_URL') ?: 'MISSING';
"
```

**Expected:** `https://erp.rizqullahmediska.com/ERP_RMI_SOFULL`  
**If MISSING:** Add to .env and re-run.

---

## STEP 3 — Smoke Tools Guest

```bash
cd /volume4/web/ERP_RMI_SOFULL
php tools/qa/smoke_tools_guest.php --write-last
```

**Alternative (explicit URL):**
```bash
php tools/qa/smoke_tools_guest.php --base-url="https://erp.rizqullahmediska.com/ERP_RMI_SOFULL" --write-last
```

**Expected:**
- `storage/logs/smoke_tools_guest_last.json` with `"ok": true`
- Exit code: 0

---

## STEP 4 — Tools Doctor

```bash
cd /volume4/web/ERP_RMI_SOFULL
php tools/qa/tools_doctor.php --check
```

**Expected:** No critical failures. If findings, optionally:
```bash
php tools/qa/tools_doctor.php --apply --i-understand
```

**Output:** `storage/logs/tools_doctor_last.json`

---

## STEP 5 — Run All Checks

```bash
cd /volume4/web/ERP_RMI_SOFULL
php tools/qa/run_all_checks.php
```

**Expected:** `storage/logs/all_checks.last.json`, `overall_ok` true or documented failures.

---

## STEP 6 — Cutover Checks (Staging Mode)

```bash
cd /volume4/web/ERP_RMI_SOFULL
php tools/qa/run_cutover_checks.php --env=staging
```

**Expected:**
- `storage/logs/cutover_checks.last.json`
- `overall_ok=true` OR `critical_fail_count=0` with non-critical reasons
- `state_migrate_v1` not blocked (--env=staging)

---

## Gate Criteria (FINAL)

| Check | Expected |
|-------|----------|
| app_root_lock.json | exists, app_root = /volume4/web/ERP_RMI_SOFULL |
| smoke_tools_guest_last.json | ok=true |
| tools_doctor_last.json | critical_fail_count=0 |
| cutover_checks.last.json | overall_ok=true or critical_fail_count=0 |
| assumptions_last.json | no APP_ROOT_MISMATCH (or empty) |

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| APP_ROOT_MISMATCH | Run from `/volume4/web/ERP_RMI_SOFULL` on NAS, not Mac |
| TOOLS_BASE_URL_MISSING | Add to .env, ensure rmi_env_load in tools_paths |
| smoke 404 | Verify base URL includes /ERP_RMI_SOFULL |
| contract_check "Could not open" | contract_check.php missing; use contract_check_web or create CLI wrapper |
| DB connection refused | Ensure DB reachable from NAS (config-db.php / .env) |
