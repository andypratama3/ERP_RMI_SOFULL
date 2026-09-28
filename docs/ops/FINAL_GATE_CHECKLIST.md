# CHECKLIST GATE FINAL — ERP_RMI_SOFULL (1 PAGE, COPY-PASTE)

**BASE (WAJIB):** `/volume4/web/ERP_RMI_SOFULL`  
**CRITICAL FAIL:** terdeteksi path `/Volumes/...` di tools/, docs/, storage/logs/, atau output tools.

**SOURCE OF TRUTH (NO ASSUMPTION):**
- `ERP_MENU_WORKFLOW_REFERENCE.md` (menu → URL → role/dept → redirect; RBAC canonical)
- `RBAC_ALL_MODULES_V1.md` (permission dictionary)
- `RBAC_MATRIX_RMI.md` (dept → modul access)
- `tools/qa/smoke_http.php` + `storage/logs/smoke_http_last.json`
- `tools/qa/run_cutover_checks.php` + `storage/logs/cutover_checks.last.json`

**HIRARKI FINAL (WAJIB):**  
SYS > FIN approvals (ONLY username `MgrFIN_BGR`; SYS boleh override) > Manager dept lain > Staff dept lain.  
ITC tidak otomatis privileged; Tools/RBAC = SYS only.

---

## 0) PRE-CHECK (PATH LOCK + CONFIG)

```bash
cd /volume4/web/ERP_RMI_SOFULL || exit 1
pwd | grep -q "^/volume4/web/ERP_RMI_SOFULL$" || { echo "FAIL: wrong base path"; exit 2; }

# CRITICAL: /Volumes guard (scan cepat)
grep -R "/Volumes/" -n tools/ docs/ storage/logs/ 2>/dev/null \
  && { echo "CRITICAL FAIL: /Volumes path detected"; exit 3; } \
  || echo "OK: No /Volumes path found"

echo "REMINDER: pastikan TOOLS_BASE_URL ter-set (kosong -> smoke/tools bisa fail)."
```

---

## 1) RUN: FINAL GATE (STRICT)

```bash
./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --strict --write-last
```

---

## 2) ARTIFACTS WAJIB MUNCUL (MINIMUM)

| Artifact | Sumber |
|----------|--------|
| `storage/logs/cutover_checks.last.json` | run_cutover_checks |
| `storage/logs/preflight_check.last.json` | preflight |
| `storage/logs/smoke_http_last.json` | smoke_http |
| `storage/logs/contract_check_last.json` | contract_check |
| `storage/logs/base_path_guard_last.json` | base_path_guard |
| `storage/logs/path_guard_last.json` | path_guard |
| `storage/logs/rbac_smoke_matrix_last.json` | rbac_smoke_matrix / rbac_matrix_http_check |
| `storage/logs/rbac_action_matrix_last.json` | rbac_action_matrix |
| `storage/logs/rbac_action_smoke_last.json` | rbac_action_smoke (copy dari matrix) |
| `storage/logs/menu_rbac_sync_last.json` | menu_rbac_sync_check |
| `storage/logs/audit_e2e_probe_last.json` | audit_e2e_probe |
| `storage/logs/backup_restore_gate_last.json` | backup_restore_gate |
| `storage/logs/restore_dry_run_last.json` | restore_dry_run |
| `storage/logs/alerts_last.json` | evaluate_alerts |
| `storage/logs/module_governance_lint_last.json` | module_governance_lint |

**Catatan:** `rbac_action_smoke_last.json` = copy dari `rbac_action_matrix_last.json`.  
`menu_rbac_sync_last.json` = menu/dashboard sync check.  
`audit_e2e_probe_last.json` = audit trail check.

---

## 3) PASS / FAIL RULES (KETAT)

### A) PATH LOCK
- **PASS:** `path_guard_last.json` ok=true AND tidak ada `/Volumes/` terdeteksi
- **FAIL:** ada `/Volumes/` di tools/docs/logs => CRITICAL FAIL

### B) ROLE & PERMISSION KONSISTEN
- **PASS:** `rbac_smoke_matrix_last.json` mismatch_count=0 untuk SEMUA entry URL di ERP_MENU_WORKFLOW_REFERENCE.md
- **PASS:** `rbac_action_matrix_last.json` mismatch_count=0 untuk semua ACTION endpoints (approve/post/pay/apply/export)
- **FAIL:** ada 1 mismatch status code (200 vs 403/302) atau Location redirect salah

### C) FIN SPECIAL (KEBAL, BUKAN UI DOANG)
- **PASS:** endpoint ACTION approve/pay pengeluaran:
  - username `MgrFIN_BGR` => 200
  - SYS => 200 (override tercatat)
  - FIN manager lain => 403 (walaupun boleh view)
- **FAIL:** selain MgrFIN_BGR/SYS bisa approve/pay => FAIL

### D) MENU/DASHBOARD SINKRON
- **PASS:** `menu_rbac_sync_last.json` mismatch=0 (menu tampil = bisa diakses; hidden = 403)
- **FAIL:** menu tampilkan link yang 403, atau modul bisa diakses tapi tidak di menu docs

### E) AUDIT TRAIL END-TO-END
- **PASS:** `audit_e2e_probe_last.json` missing_required_fields=0 (actor_username, action_code, object_type/id/doc_code, timestamp, request_id)
- **FAIL:** ada aksi mutasi tanpa audit_event lengkap

### F) BACKUP/RESTORE + VERIFY + DRY-RUN
- **PASS:** `backup_restore_gate_last.json` ok=true + manifest/checksum; `restore_dry_run_last.json` ok=true
- **FAIL:** backup tidak bisa diverifikasi / restore dry-run gagal

### G) MONITORING + ALERTING
- **PASS:** `alerts_last.json` ada (health ok, dependency ok, alert rules loaded)
- **FAIL:** health/monitoring red atau tidak ada artefak

### H) RUNBOOK + ONBOARDING + GOVERNANCE SCALE
- **PASS:** docs ada: `docs/runbook/README.md`, `docs/onboarding/ONBOARDING.md`, `docs/governance/NEW_MODULE.md`
- **PASS:** `module_governance_lint_last.json` ok=true (tambah modul tidak bikin RBAC/menu/smoke kacau)
- **FAIL:** tidak ada runbook/onboarding, atau governance check gagal

### I) CUTOVER SUMMARY
- **PASS:** `cutover_checks.last.json` overall_ok=true AND fail_count=0
- **FAIL:** overall_ok=false atau fail_count>0

---

## RINGKASAN CEPAT (COPY-PASTE)

```bash
cd /volume4/web/ERP_RMI_SOFULL || exit 1
pwd | grep -q "^/volume4/web/ERP_RMI_SOFULL$" || { echo "FAIL: wrong base path"; exit 2; }
grep -R "/Volumes/" tools/ docs/ storage/logs/ 2>/dev/null && { echo "CRITICAL FAIL: /Volumes path"; exit 3; } || echo "OK: No /Volumes path"

./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --strict --write-last

# Verifikasi artifact
test -f storage/logs/cutover_checks.last.json && echo "cutover OK" || echo "cutover MISSING"
test -f storage/logs/path_guard_last.json && echo "path_guard OK" || echo "path_guard MISSING"
test -f storage/logs/rbac_smoke_matrix_last.json && echo "rbac_smoke OK" || echo "rbac_smoke MISSING"
test -f storage/logs/rbac_action_matrix_last.json && echo "rbac_action OK" || echo "rbac_action MISSING"
test -f storage/logs/backup_restore_gate_last.json && echo "backup_gate OK" || echo "backup_gate MISSING"

echo "=== FINAL GATE DONE ==="
```
