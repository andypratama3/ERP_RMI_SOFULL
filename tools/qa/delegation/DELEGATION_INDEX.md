# DELEGATION INDEX — ERP_RMI_SOFULL QA
_Root lokal: `/Users/andypratama3/Development/ERP_RMI_SOFULL` | Produksi: `/volume4/web/ERP_RMI_SOFULL`_

## Agen & scope
| Agen | Core | Features | P0/P1 | Brief |
|------|------|----------|-------|-------|
| AGENT-01 | Master Data | 76 | 8/22 | `tools/qa/delegation/AGENT-01.md` |
| AGENT-02 | Sales / CRM | 40 | 0/12 | `tools/qa/delegation/AGENT-02.md` |
| AGENT-03 | Purchases / Procurement | 40 | 1/26 | `tools/qa/delegation/AGENT-03.md` |
| AGENT-04 | WQS / Warehouse / Stock | 24 | 0/12 | `tools/qa/delegation/AGENT-04.md` |
| AGENT-05 | SCM / Logistics (cross-cut, test-first) | 12 file | - | `tools/qa/delegation/AGENT-05.md` |
| AGENT-06 | ACT / Accounting | 7 | 0/1 | `tools/qa/delegation/AGENT-06.md` |
| AGENT-07 | FIN (cross-cut, maker-checker) | 10 file | - | `tools/qa/delegation/AGENT-07.md` |
| AGENT-08 | HRL/Absensi/Payroll | 57 | 1/15 | `tools/qa/delegation/AGENT-08.md` |
| AGENT-09 | KPI / MPR | 40 | 0/21 | `tools/qa/delegation/AGENT-09.md` |
| AGENT-10 | Dashboard / Chat / Help | 28 | 0/6 | `tools/qa/delegation/AGENT-10.md` |
| AGENT-11 | RBAC / Security | 2 | 0/1 | `tools/qa/delegation/AGENT-11.md` |
| AGENT-12 | Portal / API | 26 | 4/4 | `tools/qa/delegation/AGENT-12.md` |
| AGENT-13 | QA / Tools / Ops | 6 | 2/1 | `tools/qa/delegation/AGENT-13.md` |

## Konflik & gelombang
- Peta: `tools/qa/delegation/CONFLICT_MAP.json` (22 file overlap + 1 global shared `master/auth.php`).
- **Wave 1 (paralel):** tiap agen di file eksklusifnya; file overlap hanya pemilik yang boleh edit, sisanya TEST-ONLY.
- **Wave 2 (berurutan):** file overlap + shared global, satu agen satu waktu via LEAD.
- **Wave 3:** regresi → E2E → final gate di NAS → laporan akhir.

## Tracker
`tools/qa/ERP_MASTER_TASK_TRACKER.md` tetap satu-satunya master. Agen melapor dengan format: TASK_ID | FILES_CHANGED | TESTS_RUN | FAILURES_FOUND | FIXES_APPLIED | REGRESSION_RISK | STATUS + evidence.
