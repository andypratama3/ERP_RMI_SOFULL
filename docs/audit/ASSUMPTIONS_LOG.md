# Assumptions Log — E2E Trial Agent

> **Rule:** Jika ada ambiguity → FAIL SAFE, tulis di sini. Jangan tanya user.

## Source of Truth

- `storage/logs/assumptions_last.json` — JSON terbaru
- Dokumen ini — log human-readable

## Assumptions Terdaftar

| ID | Context | Assumption | Reason |
|----|---------|------------|--------|
| A001 | E2E_TRIAL_BASE | LAN_BASE_URL = http://10.10.60.20/ERP_RMI_SOFULL | BASE CONFIG |
| A002 | E2E_TRIAL_BASE | Office: BGR,BKS,TGR,BDG,SLO,SMG; Depo: JGY,KAL | BASE CONFIG |
| A003 | E2E_TRIAL_GATE | CUTOVER_REQUIRE_BUSINESS_SIGNOFF=0 saat trial | Trial fokus route + audit |
| A004 | E2E_TRIAL_READONLY | No mutation kecuali ALLOW_MUTATION_NONPROD=YES | Read-only default |

## Update

Saat agent menemukan ambiguity baru, tambahkan ke `assumptions_last.json` dan tabel di atas.
