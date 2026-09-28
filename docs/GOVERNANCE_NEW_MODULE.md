# GOVERNANCE — Modul Baru ERP_RMI_SOFULL

> **See also:** [`docs/governance/NEW_MODULE.md`](governance/NEW_MODULE.md) — Detail lengkap  
> **Base (WAJIB):** `/volume4/web/ERP_RMI_SOFULL`

---

## Aturan Wajib Modul Baru

Setiap modul/route baru HARUS lulus semua poin ini sebelum merge/deploy:

### 1. Daftarkan Entry Page di ERP_MENU_WORKFLOW_REFERENCE.md

```markdown
## <Nama Modul>
- URL: /dept/module_page.php
- Dept: [ITC|MPR|CRM|SCM|ACT|FIN|HRL|WQS|PQP|BRANCH|SYS]
- Role: manager, staff (atau manager saja jika approval-only)
- Redirect: /path/redirect.php (jika ada redirect by dept)
```

### 2. Tambahkan Permission Code di RBAC_ALL_MODULES_V1.md

```
DEPT.MODULE_VIEW      # Lihat halaman
DEPT.MODULE_CREATE    # Buat dokumen baru
DEPT.MODULE_SUBMIT    # Submit untuk approval
DEPT.MODULE_APPROVE   # Approve (manager only)
DEPT.MODULE_POST      # Post/finalize
```

> **FIN special:** Jika modul terkait pembayaran, tambahkan `FIN.AP_PAYMENT_APPROVE`  
> (hanya `MgrFIN_BGR` + SYS yang boleh; enforce di server-side, bukan hanya UI)

### 3. Update RBAC Mapping di RBAC_MATRIX_RMI_v1.md

Tambahkan baris dept → modul di tabel access matrix.

### 4. Guard di File PHP Modul

```php
<?php
require_login();
require_any_permission(['DEPT.MODULE_VIEW']);

// Semua aksi mutasi:
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_any_permission(['DEPT.MODULE_CREATE']);
    // ... business logic SETELAH permission check
}
```

### 5. Audit Event untuk Setiap Aksi Mutasi

```php
erp_audit($pdo, 'MODULE_CREATE', $objectType, $objectId, $docCode,
    $_SESSION['username'] ?? 'SYSTEM', $requestId);
```

Field wajib: `actor_username`, `action_code`, `object_type`, `object_id`/`doc_code`, `timestamp`, `request_id`.

### 6. Update Smoke Matrix

Pastikan entry URL sudah ter-include di matrix check:
```bash
# Verifikasi setelah menambah entry di ERP_MENU_WORKFLOW_REFERENCE.md:
./tools/nas/erp.sh php tools/qa/rbac_smoke_matrix.php --strict --write-last
# PASS: mismatch_count=0
```

### 7. Lulus Cutover Gate

```bash
cd /volume4/web/ERP_RMI_SOFULL || exit 1
./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --strict --write-last
# PASS: overall_ok=true, storage/logs/cutover_checks.last.json
```

---

## Anti-Chaos Checklist

| # | Larangan | Alasan |
|---|----------|--------|
| 1 | Jangan hardcode dept check di banyak file | Sentralisasi ke `rbac_policy.php` |
| 2 | Jangan bypass `require_login()` atau `verify_csrf()` | Security |
| 3 | Jangan ubah flow PR→PO→GR→AP atau SO→DO→Stock | Constraint keras |
| 4 | Jangan buat role baru (hanya SYS, MANAGER, STAFF) | Rule RBAC |
| 5 | Jangan buat office_code baru tanpa update helper | `wqs_stock_default_office()` |
| 6 | Jangan pakai path `/Volumes/` di tool/config/log | CRITICAL FAIL di gate |
| 7 | Jangan tambah menu tanpa permission check | Phantom menu → 403 |

---

## Rollback Modul Baru

Jika modul baru menyebabkan issue:
1. Hapus entry dari `ERP_MENU_WORKFLOW_REFERENCE.md` (menu tidak tampil)
2. Hapus/disable permission dari `RBAC_ALL_MODULES_V1.md`
3. Jalankan gate: `run_cutover_checks.php --strict`

---

## Referensi

| Dokumen | Path |
|---------|------|
| Menu & URL | `docs/ERP_MENU_WORKFLOW_REFERENCE.md` |
| Permission dict | `docs/governance/RBAC_ALL_MODULES_V1.md` |
| Dept access matrix | `docs/governance/RBAC_MATRIX_RMI_v1.md` |
| Governance lint tool | `tools/qa/module_governance_lint.php` |
| Cutover gate | `tools/qa/run_cutover_checks.php` |
| RBAC change rules | `docs/governance/RBAC_CHANGE_RULES.md` |
