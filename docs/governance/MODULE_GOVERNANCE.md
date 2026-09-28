# MODULE GOVERNANCE — Aturan Modul Baru

Standar wajib **sebelum** modul baru live. Agar sistem tetap rapi saat scale.

---

## 1. Route & Entry

### 1.1 Entry di Menu

- [ ] Tambah di `_shared/nav_config.php` (key, url, roles)
- [ ] Update `docs/ERP_MENU_WORKFLOW_REFERENCE.md` jika pakai extract
- [ ] Breadcrumb & back link konsisten (lihat `.cursor/rules/ui-link-consistency.mdc`)

### 1.2 URL & Routing

- Entry page URL jelas dan terdokumentasi
- Deny-by-default: jika tidak ada rule → 403 (logged-in) / 302 (guest)
- Semua halaman internal: `require_login()`

---

## 2. RBAC (Page + Action)

### 2.1 Page Rule

- [ ] Rule di `_shared/rbac_policy.php` — route, depts, levels, methods
- [ ] Permission di `config/rbac_permissions.php`
- [ ] Jalankan sync di RBAC Center

### 2.2 Action Rule (POST / Mutasi)

- [ ] Semua mutasi: POST-only + CSRF wajib (`verify_csrf()`, `csrf_token()`)
- [ ] FIN approval: `auth_require_fin_central_approver()` — hanya MgrFIN_BGR + SYS
- [ ] PO_APPROVE, GR_POST, STOCK_ADJUSTMENT_APPROVE, dll. sesuai `docs/governance/RBAC_ALL_MODULES_V1.md`

### 2.3 Permission Codes

- Sumber: `RBAC_ALL_MODULES_V1.md`, `RBAC_MATRIX_RMI_v1.md`
- SYSTEM.RBAC_MANAGE, SYSTEM.USER_MANAGE → SYS only
- AP_PAYMENT_APPROVE, AP_PAYMENT_POST → MgrFIN_BGR + SYS only

---

## 3. Audit Trail

### 3.1 Event Mapping

- [ ] Setiap mutasi penting → `erp_audit()` atau `master_audit()`
- [ ] Event: created, submitted, approved, rejected, posted
- [ ] Meta: `request_id`, `actor_username`, `doc_code`, `action_code`

### 3.2 Coverage

- PR, PO, GR, AP, DO, Stock adjustment, Opname
- Request_id coverage tinggi, actor_username terisi

---

## 4. Smoke & QA

### 4.1 GET Test

- [ ] Tambah ke `tools/qa/rbac_matrix_http_check.php` (atau matrix config)
- [ ] Entry URL di `ERP_MENU_WORKFLOW_REFERENCE.md`

### 4.2 POST Test (jika ada action)

- [ ] Expect 200/302 untuk role yang boleh
- [ ] Expect 403 untuk role yang tidak boleh
- [ ] FIN special: MgrFIN_BGR vs MgrFIN_BKS — pembanding

### 4.3 Menu RBAC Sync

- [ ] `tools/qa/menu_rbac_sync_check.php` — menu tampil ⇔ GET allowed

---

## 5. Migration

### 5.1 Schema / Data

- [ ] Migration file di `docs/governance/data_migration/sql/` atau modul migration
- [ ] Rollback note: cara revert jika salah

### 5.2 No Business Logic Change

- **DILARANG** mengubah flow: PR → PQP → PO → GR → AP, SO → DO → Stock, Opname → Adjustment
- Hanya: RBAC, guard, smoke, audit, monitoring

---

## 6. Monitoring Hook

- [ ] Health endpoint jika modul punya dependency eksternal
- [ ] Error rate / latency jika kritis
- [ ] Minimal: tercakup di smoke_http / contract_check

---

## 7. Gate PASS

```bash
cd /volume4/web/ERP_RMI_SOFULL
./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --write-last --strict
```

**Harus hijau** setelah penambahan modul:

- path_guard ok
- smoke_http fail=0
- contract_check ok
- rbac_smoke_matrix mismatch_count=0
- rbac_action_matrix mismatch_count=0
- backup_restore_gate ok
- audit_e2e_probe ok (jika ada)

---

## 8. Referensi

| Dokumen | Untuk |
|---------|-------|
| `docs/ERP_MENU_WORKFLOW_REFERENCE.md` | Menu → modul → entry URL |
| `docs/governance/RBAC_ALL_MODULES_V1.md` | Permission dictionary |
| `docs/governance/RBAC_MATRIX_RMI_v1.md` | Dept → modul access |
| `docs/ops/CHECKLIST_MODUL_BARU.md` | Checklist singkat |
| `.cursor/rules/ui-link-consistency.mdc` | UI link consistency |

---

*Update: Final Hardening Execution.*
