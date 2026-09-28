# E2E Trial — Cara Menjalankan

## WAJIB: Jalankan dari NAS

```
cd /volume4/web/ERP_RMI_SOFULL
./tools/nas/erp.sh ./tools/qa/e2e_trial_full.sh
```

Dari Mac mount (`/Volumes/...`) → **FAIL** (erp.sh detect Darwin, exit 2).

## Output

- Evidence: `storage/logs/e2e_trial/<RUN_ID>/`
- Report: `storage/logs/e2e_trial/<RUN_ID>/REPORT.md`
- Summary: `storage/logs/e2e_trial/<RUN_ID>/SUMMARY.txt`
- ZIP: `storage/logs/e2e_trial/<RUN_ID>/EVIDENCE_PACK.zip`

## Gate Logic

- Jika cutover FAIL → E2E trail **SKIP** (triage gate dulu)
- Audit realtime tetap jalan
- Report tetap di-generate

## Assumptions

Lihat `storage/logs/assumptions_last.json` dan `docs/audit/ASSUMPTIONS_LOG.md`.
