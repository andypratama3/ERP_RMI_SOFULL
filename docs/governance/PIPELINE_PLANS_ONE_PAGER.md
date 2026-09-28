# Pipeline Plans One-Pager

**Generated:** 2026-02-28T21:07:55+00:00

| Plan ID | Kapan dipakai | Siapa pakai | Durasi | Apa yang dicek | Gate PASS |
|---------|---------------|-------------|--------|----------------|----------|
| dev_fast_local | Quick local check before commit | dev | 5 min | lint, contract, smoke_http_core | lint=0 err, contract ok, smoke fail=0 |
| staging_gate_before_merge | PR gate, fast | qa,dev | 3 min | lint, contract, tools_dashboard_smoke | all steps exit 0 |
| staging_gate_before_merge_strict_http | PR gate with full HTTP smoke | qa,dev | 8 min | lint, contract, smoke_http_core, tools_dashboard_s | all steps exit 0, no PHP Warning/Notice/ |
| staging_cutover_checks | Pre-cutover validation | ops,qa | 10 min | preflight, cutover, smoke, contract | cutover overall_ok |
| nightly_ops_smoke | Cron nightly | ops | 5 min | smoke, contract, ops_metrics | smoke+contract ok |
| weekly_ops_report | Weekly ops summary | ops,management | 5 min | weekly report md+json, exec summary html | artifacts generated |
| dr_drill_monthly | Monthly DR drill | ops | 15 min | backup, verify checksum, restore dry-run | dr_drill ok |
