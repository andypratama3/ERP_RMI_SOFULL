# FINAL GATE DUAL-LAYER — ERP_RMI_SOFULL

**Base (WAJIB):** `/volume4/web/ERP_RMI_SOFULL`  
**CRITICAL:** Tidak ada `/Volumes/` di tools/ & artifacts.  
**Updated:** 2026-03-17

---

## Command (NAS)

```bash
cd /volume4/web/ERP_RMI_SOFULL || exit 1

# Set base URL kedua layer (atau biarkan dari .env)
export TOOLS_BASE_URL_INTERNAL="http://10.10.60.20/ERP_RMI_SOFULL"
export TOOLS_BASE_URL_PUBLIC="https://erp.rizqullahmediska.com/ERP_RMI_SOFULL"
export SALES_TRACKING_ALLOW_LEGACY=1

# JALANKAN DUAL GATE (1 command):
./tools/nas/erp.sh php tools/qa/run_gate_dual.php --strict --write-last
```

**PASS = KEDUANYA pass (internal LAN + public domain).**

---

## Artifacts Wajib Muncul

### Per-Layer (dihasilkan run_gate_dual.php)

| Artifact | Layer |
|----------|-------|
| `storage/logs/final_gate_dual_last.json` | Combined ⭐ |
| `storage/logs/cutover_checks_internal_last.json` | Internal |
| `storage/logs/cutover_checks_public_last.json` | Public |
| `storage/logs/smoke_http_internal_last.json` | Internal |
| `storage/logs/smoke_http_public_last.json` | Public |
| `storage/logs/contract_check_internal_last.json` | Internal |
| `storage/logs/contract_check_public_last.json` | Public |
| `storage/logs/rbac_smoke_matrix_internal_last.json` (+.csv) | Internal |
| `storage/logs/rbac_smoke_matrix_public_last.json` (+.csv) | Public |
| `storage/logs/rbac_action_matrix_internal_last.json` (+.csv) | Internal |
| `storage/logs/rbac_action_matrix_public_last.json` (+.csv) | Public |

### Shared (dihasilkan cutover_checks + gate_artifacts_sync)

| Artifact | Tool |
|----------|------|
| `storage/logs/menu_rbac_sync_last.json` | menu_rbac_sync_check |
| `storage/logs/audit_e2e_probe_last.json` | audit_e2e_probe |
| `storage/logs/backup_verify_last.json` | backup_verify_cli |
| `storage/logs/restore_dry_run_last.json` | restore_dry_run |
| `storage/logs/alert_evaluation_last.json` | alias alerts_last.json |
| `storage/logs/module_governance_lint_last.json` | module_governance_lint |
| `storage/backups/<timestamp>/manifest.json` | backup engine |
| `storage/backups/<timestamp>/checksums.sha256` | backup engine |

---

## Status Landing Page per Dept (Konsisten ✅)

| Dept | Landing Page |
|------|-------------|
| CRM | `/sales/sales_dashboard.php` |
| WQS | `/dashboards/warehouse/wqs_dashboard.php` |
| **PQP** | `/purchases/purchases_import_control_tower.php` ← **diperbaiki** |
| SCM | `/dashboards/scm/scm_dashboard.php` |
| FIN | `/dashboards/finance/ar_ap_cash_dashboard.php` |
| ACT | `/dashboards/act/act_dashboard.php` |
| HRL | `/dashboards/hrl/hrl_dashboard.php` |
| ITC | `/dashboards/itc/itc_dashboard.php` |
| MPR | `/mpr/mpr_dashboard.php` |
| BRANCH | `/dashboards/branch/branch_dashboard.php` |
| SYS | `/dashboards/index.php` (Dashboard Center) |

## PASS / FAIL Kriteria

| Check | Expected |
|-------|---------|
| `final_gate_dual_last.json` overall_ok | `true` |
| internal.overall_ok | `true` |
| public.overall_ok | `true` |
| rbac_smoke_matrix_internal mismatch_count | `0` |
| rbac_smoke_matrix_public mismatch_count | `0` |
| rbac_action_matrix_internal mismatch_count | `0` |
| rbac_action_matrix_public mismatch_count | `0` |
| menu_rbac_sync_last ok | `true` |
| audit_e2e_probe_last ok | `true` |
| backup_verify_last ok | `true` |
| restore_dry_run_last ok | `true` |
| alert_evaluation_last critical_count | `0` |
| module_governance_lint ok | `true` |
| `/Volumes/` di tools/logs | `0 temuan` ← CRITICAL |

---

## Verifikasi Cepat Setelah Run

```bash
# Overall
python3 -c "import json; d=json.load(open('storage/logs/final_gate_dual_last.json')); \
print('OVERALL:', d['ok'], '| INTERNAL:', d['internal']['overall_ok'], '| PUBLIC:', d['public']['overall_ok'])"

# RBAC mismatch
python3 -c "import json; d=json.load(open('storage/logs/final_gate_dual_last.json')); \
print('RBAC internal:', d['summary']['rbac_mismatch_internal'], \
      '| public:', d['summary']['rbac_mismatch_public'])"

# /Volumes check (harus kosong)
grep -R "/Volumes/" tools/ storage/logs/ 2>/dev/null && echo "CRITICAL FAIL" || echo "OK: No /Volumes"
```

---

## FIN Special Rule

```
POST /purchases/purchases_payment_ap.php (action=create_pay)
  MgrFIN_BGR → 200 (ALLOWED)
  SmokeSYS   → 200 (ALLOWED, SYS override)
  MgrFIN_BDG → 403 (BLOCKED, non-BGR)
  StaffFIN   → 403 (BLOCKED, staff)
  Dept lain  → 403 (BLOCKED)
```

## ITC Policy

```
ITC = dept biasa (TIDAK punya hak khusus Tools/RBAC)
Tools (/tools/*) = SYS only
RBAC manage = SYS only (SYSTEM.RBAC_MANAGE, SYSTEM.USER_MANAGE)
```

---

## Source of Truth

| Dokumen | Path |
|---------|------|
| Menu + URL | `docs/ERP_MENU_WORKFLOW_REFERENCE.md` |
| Permission | `docs/governance/RBAC_ALL_MODULES_V1.md` |
| Dept access | `docs/governance/RBAC_MATRIX_RMI_v1.md` |
| Module registry | `docs/governance/module_registry.json` |
| Runbook | `docs/RUNBOOK_OPS.md` |
| Onboarding | `docs/ONBOARDING_USER.md` |
| Governance | `docs/GOVERNANCE_NEW_MODULE.md` |
