# Checklist Modul Baru — Governance (Tahap 7)

Standar wajib **sebelum** modul baru live. Agar sistem tetap rapi saat scale.

---

## 1. Entry Dokumen Menu

- [ ] Tambah di `_shared/nav_config.php` (key, url, roles)
- [ ] Atau update `docs/ERP_MENU_WORKFLOW_REFERENCE.md` jika pakai extract

---

## 2. RBAC Rule

- [ ] Page rule di `_shared/rbac_policy.php` — route, depts, levels, methods
- [ ] Action rule jika ada POST approve/pay — `auth_require_fin_central_approver()` untuk cash-out
- [ ] Permission di `config/rbac_permissions.php` — jalankan sync di RBAC Center

---

## 3. Smoke Test

- [ ] GET test di `tools/qa/rbac_matrix_http_check.php` — tambah ke `$matrix`
- [ ] POST test jika ada action (approve, pay, post) — expect 403 untuk role yang tidak boleh

---

## 4. Audit Event Mapping

- [ ] Setiap mutasi penting → `erp_audit()` atau `master_audit()`
- [ ] Event: created, submitted, approved, rejected, posted
- [ ] Meta: `request_id`, `actor_username`, `doc_code`, `action_code`

---

## 5. Rollback Note

- [ ] Dokumen cara revert jika salah (migration rollback, data fix)
- [ ] Simpan di `docs/` atau inline di migration

---

## 6. Monitoring Hook

- [ ] Health endpoint jika modul punya dependency eksternal
- [ ] Error rate / latency jika kritis

---

## Gate PASS

```bash
./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --strict
```

Harus hijau setelah penambahan modul.
