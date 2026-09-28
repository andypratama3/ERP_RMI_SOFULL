# New Module — Aturan Wajib Modul Baru

**Lokasi lengkap:** [MODULE_GOVERNANCE.md](MODULE_GOVERNANCE.md)

---

## Checklist Wajib

1. **Update menu doc:** `docs/ERP_MENU_WORKFLOW_REFERENCE.md`
2. **RBAC mapping:** `docs/governance/RBAC_MATRIX_RMI_v1.md`
3. **Permissions:** `docs/governance/RBAC_ALL_MODULES_V1.md`, `config/rbac_permissions.php`
4. **Smoke matrix:** Entry URL di `tools/qa/rbac_matrix_http_check.php`
5. **Audit events:** Minimal create/submit/approve/post → `erp_audit()`

## Gate

```bash
./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --strict --write-last
```

**Harus hijau** setelah penambahan modul.
