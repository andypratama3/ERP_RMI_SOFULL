# GOVERNANCE MODULE — ERP_RMI_SOFULL

> **Lokasi lengkap:** [`docs/governance/MODULE_GOVERNANCE.md`](governance/MODULE_GOVERNANCE.md)  
> **SOP:** Setiap modul/route baru WAJIB mengikuti checklist ini sebelum merge/deploy.

---

## Checklist Modul Baru

### 1. Entry di ERP_MENU_WORKFLOW_REFERENCE.md

```markdown
## <Nama Modul>
- URL: /dept/module_page.php
- Dept: [dept yang boleh akses]
- Role: manager, staff (atau manager saja)
- Redirect: [URL redirect jika dept berbeda]
```

### 2. Permission Codes di RBAC_ALL_MODULES_V1.md

```
DEPT.MODULE_VIEW
DEPT.MODULE_CREATE
DEPT.MODULE_APPROVE    # jika ada approval
DEPT.MODULE_POST       # jika ada posting
```

### 3. RBAC Mapping di RBAC_MATRIX_RMI_v1.md

Tambah baris di tabel dept → module access.

### 4. Guard di File PHP

```php
require_login();
require_any_permission(['DEPT.MODULE_VIEW', 'SYSTEM.OVERRIDE']);
// Semua aksi mutasi: POST-only + CSRF + permission check SEBELUM business logic
```

### 5. Smoke Matrix

Tambah entry ke `tools/qa/rbac_matrix_http_check.php` atau pastikan
`ERP_MENU_WORKFLOW_REFERENCE.md` sudah ter-parse oleh `rbac_smoke_matrix.php`.

### 6. Audit Event

```php
erp_audit($pdo, 'MODULE_CREATE', $objectType, $objectId, $docCode, $actor, $requestId);
```

### 7. Cutover Gate

```bash
./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --strict --write-last
# Harus: overall_ok=true, mismatch_count=0
```

---

## Anti-Chaos Rules

| Larangan | Alasan |
|----------|--------|
| Jangan hardcode dept check di banyak tempat | Gunakan sentralisasi permission |
| Jangan bypass require_login() | Security |
| Jangan ubah flow bisnis PR→PO→GR→AP | Constraint keras |
| Jangan buat role baru | Hanya: SYS, MANAGER, STAFF |
| Jangan buat office code baru tanpa konsultasi | Lihat office-codes rule |

---

## Referensi

- [`docs/governance/MODULE_GOVERNANCE.md`](governance/MODULE_GOVERNANCE.md) — Detail lengkap
- [`docs/governance/NEW_MODULE.md`](governance/NEW_MODULE.md) — SOP step-by-step
- [`docs/governance/RBAC_CHANGE_RULES.md`](governance/RBAC_CHANGE_RULES.md) — Aturan RBAC
- [`tools/qa/module_governance_lint.php`](../tools/qa/module_governance_lint.php) — Lint otomatis
