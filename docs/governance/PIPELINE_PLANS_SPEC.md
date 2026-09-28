# Pipeline Plans Spec

## NAS Workdir Lock: /volume4/web/ERP_RMI_SOFULL

- **Source of truth:** Satu-satunya folder kerja Tools: `/volume4/web/ERP_RMI_SOFULL`
- **Lock file:** `storage/logs/app_root_lock.json` — wajib ada sebelum menjalankan CLI runner
- **Cara lock:** `cd /volume4/web/ERP_RMI_SOFULL && php tools/dev/lock_app_root.php`
- **Semua CLI runner** (tools_doctor, run_plan, run_pipeline_final, generate_weekly_ops_report, generate_executive_summary) **wajib assert** `app_root_lock.json`
- **Kalau mismatch** (path kerja ≠ locked app_root) → **STOP** (exit non-zero, anti salah folder)
- **TOOLS_BASE_URL:** Wajib set di env NAS untuk HTTP smoke. Jika missing → smoke FAIL (bukan skip)

## Plan IDs

| Plan ID | Kapan | Gate |
|---------|-------|------|
| staging_gate_before_merge | PR kecil | lint, contract, tools_dashboard_smoke |
| staging_gate_before_merge_strict_http | PR besar / banyak ubah route/API | + smoke_http_core |
| nightly_ops_smoke | Cron nightly | smoke, contract, ops_metrics |
| weekly_ops_report | Weekly | weekly report md+json |
| dr_drill_monthly | Monthly DR | backup, verify, restore dry-run |

## Run Plan (NAS strict)

```bash
cd /volume4/web/ERP_RMI_SOFULL
php tools/dev/lock_app_root.php
php tools/qa/plans/run_plan.php --id=staging_gate_before_merge_strict_http --env=staging --write-last --strict
```

Evidence: `storage/logs/plan_runs/staging_gate_before_merge_strict_http.last.json`
