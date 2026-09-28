# Tools Negative Tests

- Run at: 2026-03-11T21:02:52+07:00
- Overall: PASS
- Score: 100/100

## Results
- corrupt_json_state: PASS (`invalid_json`)
- missing_state_file: PASS (`missing`)
- backup_lock: PASS (`[2026-03-11T14:02:52Z] BACKUP_DONE status=failed reason=lock_exists | Backup already running (lock exists).`)
- smoke_lock: PASS (`[2026-03-11T14:02:52Z] START nightly smoke base=https://localhost/ERP_RMI_SOFULL | [2026-03-11T14:02:52Z] FAIL smoke lock exists`)
- run_cutover_checks: PASS (`skipped_by_flag`)
- rotate_history_dry_run: PASS (`skipped_by_flag`)
