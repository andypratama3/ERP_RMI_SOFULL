# Runbook Index — ERP_RMI_SOFULL

SOP terpusat untuk operasional. Semua link mengarah ke dokumen yang dipakai beneran.

---

## 1. Deploy & Path

| Dokumen | Lokasi | Untuk |
|---------|--------|-------|
| Panduan NAS Lengkap | [tools/nas/PANDUAN_NAS_LENGKAP.md](../../tools/nas/PANDUAN_NAS_LENGKAP.md) | Setup NAS, path, permission |
| Path Policy | [docs/governance/APP_ROOT_POLICY.md](../governance/APP_ROOT_POLICY.md) | Base path /volume4/web/ERP_RMI_SOFULL |
| erp.sh Wrapper | `./tools/nas/erp.sh php tools/...` | Semua CLI tools wajib lewat ini |

---

## 2. Backup & Restore

| Dokumen | Lokasi | Untuk |
|---------|--------|-------|
| Backup Now | `tools/backup_now.php` (Web) | Backup manual sekali klik |
| Backup Verify | `tools/backup_verify.php` | Verifikasi checksum backup |
| Restore | `tools/restore_now.php` | Restore DB + files (dry-run dulu) |
| Artifact | `storage/logs/backup_last.json`, `backup_verify_last.json`, `restore_dry_run_last.json` | Status terakhir |

---

## 3. Investigasi Error

| Langkah | Keterangan |
|---------|------------|
| Cek log | `storage/logs/` — error, audit, backup |
| request_id | Setiap audit event punya `request_id` — trace di `system_audit_logs` |
| Audit trail | `master/audit_logs.php` — filter by user, module, IP |
| Smoke test | `tools/qa/smoke_http_web.php` — jalankan smoke HTTP |

---

## 4. Emergency Lock

| Aksi | Cara |
|------|------|
| Matikan akses SYS sementara | Nonaktifkan user di `master/master_system_login.php` (status INACTIVE) |
| IP restriction admin | `.env` → `ADMIN_BUILTIN_ALLOWED_IPS` — batasi IP admin/superadmin |

---

## 5. Tambah User / Office / Depo

| Dokumen | Lokasi |
|---------|--------|
| Master System Login | `master/master_system_login.php` — tambah user, set dept, office |
| RBAC Center | `rbac/index.php` — assign permission per dept/role |
| Office codes | `.cursor/rules/office-codes.mdc` — BGR, BDG, SLO, dll. |

---

## 6. Tambah Modul Baru (Governance)

Checklist wajib sebelum modul live:

- [ ] Entry di dokumen menu (`docs/ERP_MENU_WORKFLOW_REFERENCE.md` atau `nav_config.php`)
- [ ] RBAC rule di `_shared/rbac_policy.php` (page + action)
- [ ] Permission di `config/rbac_permissions.php`
- [ ] Smoke test GET di `tools/qa/rbac_matrix_http_check.php`
- [ ] Audit event mapping (PR/PO/GR/AP/DO/stock)
- [ ] Rollback note (cara revert jika salah)
- [ ] Minimal monitoring hook (health, error rate)

Gate: `run_cutover_checks --strict` selalu hijau setelah penambahan modul.

---

## 7. Cutover & Smoke

| Perintah | Untuk |
|----------|-------|
| `./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --write-last --strict` | Cutover gate (path police, RBAC, smoke, dll.) |
| `./tools/nas/erp.sh php tools/smoke_http.php --write-last` | Smoke HTTP |
| Web UI | `tools/qa/cutover_checks_web.php`, `tools/qa/smoke_http_web.php` |

---

*Update terakhir: sesuai implementasi roadmap tahapan sehat.*
