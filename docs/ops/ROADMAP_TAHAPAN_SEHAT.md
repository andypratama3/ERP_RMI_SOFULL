# Roadmap Tahapan Menuju Sehat — ERP_RMI_SOFULL

Dokumen ini memetakan **apa yang akan dilakukan** untuk mencapai 6 tujuan operasional + 7 tahap dengan gate PASS yang jelas.

---

## Ringkasan Kondisi Saat Ini (Updated)

| Komponen | Status | Catatan |
|----------|--------|---------|
| **Tahap 0 — Base Path** | 95% | erp.sh mask cmd path; path_police scan cmd; TOOLS_BASE_URL dari .env; tools_base_url() load .env jika kosong. |
| **Tahap 1 — RBAC** | 90% | auth_require_fin_central_approver (MgrFIN_BGR+SYS); rbac_matrix tulis rbac_smoke_matrix_last.json + rbac_action_smoke_matrix_last.json. |
| **Tahap 2 — Menu/Dashboard** | 85% | menu_audit_report_last.json dari rbac_matrix (item→page→broken_link). |
| **Tahap 3 — Audit Trail** | 55% | request_id di ApiResponse meta; audit_event; gap: mapping lengkap PR/PO/GR/AP. |
| **Tahap 4 — Backup/Restore** | 90% | backup_last.json, backup_verify_last.json, restore_dry_run_last.json + restore_last.json. |
| **Tahap 5 — Monitoring/Alerting** | 70% | TOOLS_BASE_URL mustahil kosong (tools_base_url load .env); alerts.php ada. |
| **Tahap 6 — Runbook** | 80% | docs/ops/RUNBOOK_INDEX.md — SOP terpusat. |
| **Tahap 7 — Governance Modul Baru** | 80% | docs/ops/CHECKLIST_MODUL_BARU.md — checklist formal. |

---

## Tahap 0 — Kunci Fondasi Operasional (Single Source of Truth)

**Tujuan:** Semua tim dan tools hanya menganggap 1 base project: `/volume4/web/ERP_RMI_SOFULL`.

### Yang Sudah Ada
- `tools/nas/erp.sh` — set APP_ROOT, reject /Volumes, Darwin, source assert_app_root.sh
- `tools/qa/path_police.php` — scan JSON artifacts untuk /Volumes/, Macintosh HD
- `tools/qa/volumes_guard.php`, `volumes_police.php` — guard path
- `tools/_shared/app_root_guard.php` — tools_assert_expected_app_root()

### DONE
1. **erp.sh artifact masking** — cmd di-mask (sed) agar /Volumes/ dan /volume4/ → [APP_ROOT].
2. **path_police** — scan field `cmd` di JSON artifacts.
3. **TOOLS_BASE_URL** — erp.sh load dari .env; tools_base_url() load .env jika env kosong.

### Output Wajib
- `tools/nas/erp.sh` (atau wrapper sejenis) yang:
  - set `APP_ROOT=/volume4/web/ERP_RMI_SOFULL`
  - menolak `/Volumes/` (fail langsung)
- Semua tool runner lewat wrapper

### Gate PASS
- [ ] Semua tool runner lewat `./tools/nas/erp.sh php tools/...`
- [ ] `path_police` + `volumes_police` PASS — tidak ada output/log berisi /Volumes/

---

## Tahap 1 — RBAC Final (UI + ACTION) sesuai Hirarki

**Hirarki:** SYS > FIN (approval pengeluaran hanya MgrFIN_BGR; SYS boleh override) > Manager dept lain > Staff dept lain

### Yang Sudah Ada
- `_shared/rbac_policy.php` — route guard
- `rbac_can()` — permission check
- `config/rbac_permissions.php` — single source of truth
- `tools/qa/rbac_matrix_http_check.php` — GET matrix → `rbac_matrix_http_check_last.json`
- RBAC Center UI

### DONE
1. **FIN special rule** — auth_require_fin_central_approver() di purchases_payment_ap, gl_reversal_approvals.
2. **Action matrix** — rbac_matrix_http_check tulis rbac_smoke_matrix_last.json (GET) + rbac_action_smoke_matrix_last.json (POST).

### Output Wajib
- `storage/logs/rbac_smoke_matrix_last.json` (GET matrix)
- `storage/logs/rbac_action_smoke_matrix_last.json` (POST/action matrix)

### Gate PASS
- [ ] Guest → 302 ke login
- [ ] Logged-in tanpa hak → 403
- [ ] Logged-in dengan hak → 200
- [ ] POST tanpa CSRF → 403
- [ ] POST tanpa permission → 403
- [ ] MgrFIN_BGR + SYS only untuk approve/pay AP

---

## Tahap 2 — Menu & Dashboard Sinkron 100% dengan RBAC

**Tujuan:** User tidak pernah melihat menu yang tidak bisa dibuka.

### Yang Sudah Ada
- `_shared/rmi_layout.php` — sidebar dari permission
- `tools/dev/extract_menu_routes.php` — extract route
- `docs/governance/SIDEBAR_ACCESS_MATRIX.md`

### DONE
1. **Menu Audit Report** — rbac_matrix_http_check --write-last tulis menu_audit_report_last.json (item→page→broken_link).

### Output Wajib
- "Menu Audit Report" (JSON atau HTML): item → page → expected code

### Gate PASS
- [ ] Semua menu item untuk role aktif → bisa dibuka (200/redirect valid)
- [ ] Menu yang tidak boleh → tidak tampil; direct URL tetap 403

---

## Tahap 3 — Audit Trail End-to-End

**Tujuan:** Setiap perubahan penting bisa ditelusuri "siapa ngapain".

### Minimal Audit Event yang WAJIB
- PR: created/submitted/approved/rejected
- PO: created/confirmed
- GR: created/posted + upload media
- AP invoice: created/validated/posted
- AP payment: created/approved/posted (FIN rule)
- DO: created/posted
- Stock adjustment & opname apply
- RBAC change (SYS-only)

### Yang Sudah Ada
- `system_audit_logs` table
- `erp_audit()`, `master_audit()`, `audit_event()`
- `erp_request_id()` — request_id di meta audit
- Mobile API: `mobile_request_id()`, `mobile_audit()`

### Yang Perlu Dikerjakan
1. **request_id** — Muncul di response API (header X-Request-Id), log file, audit_event record.
2. **Event mapping** — Pastikan setiap mutasi di atas punya panggilan audit dengan actor, timestamp, request_id, doc_code, action_code.
3. **Trace PR→PO→GR→AP** — Ambil 1 transaksi real → audit trail lengkap urut.

### Output Wajib
- request_id di response API
- request_id di log file
- request_id di audit_event record

### Gate PASS
- [ ] 1 transaksi real (PR→PO→GR→AP) → audit trail lengkap urut

---

## Tahap 4 — Backup/Restore "Bisa Dipakai"

**Tujuan:** Kalau NAS/DB rusak, bisa balikin sistem tanpa panik.

### Yang Sudah Ada
- `tools/backup_now.php`, `tools/backup_manager.php`
- `tools/backup_verify.php` — checksum verify
- `tools/restore_now.php` — restore DB + files, guard SYS only
- `tools/ops/backup_verify.php`, `restore_now.php`

### Yang Perlu Dikerjakan
1. **Backup otomatis** — DB + file uploads + manifest + checksum (cron/schedule).
2. **Verify backup otomatis** — Setelah backup, verify checksum.
3. **Restore dry-run** — Wajib ada; apply restore butuh guard "i-understand".
4. **Standard artifact** — `storage/logs/backup_last.json`, `backup_verify_last.json`, `restore_dry_run_last.json`.

### Output Wajib
- `storage/logs/backup_last.json`
- `storage/logs/backup_verify_last.json`
- `storage/logs/restore_dry_run_last.json`

### Gate PASS
- [ ] Backup now = OK
- [ ] Verify = OK
- [ ] Restore dry-run = OK

---

## Tahap 5 — Monitoring & Alerting

**Tujuan:** Tahu masalah sebelum user komplain.

### Minimal Metric/Alert
- Health endpoint ok (DB connect, storage writable)
- Cron/job success ratio
- HTTP 5xx error rate
- Latency p95 endpoint penting
- Disk usage (storage/logs, uploads, backups)
- Backup success & verify status

### Yang Sudah Ada
- `tools/health.php`, `api/v1/health.php`
- `tools/ops/alerts.php` — alert policy, acknowledge, resolve
- `tools/ops/_lib/alerting_lib.php`, `alert_policy_lib.php`

### DONE (partial)
1. **TOOLS_BASE_URL mustahil kosong** — tools_base_url() load .env; erp.sh load TOOLS_BASE_URL_INTERNAL, SMOKE_BASE_URL dari .env.

### Output Wajib
- `tools/ops/alerts.php` (atau sejenis) punya "status terakhir"
- Alert routing (email/telegram/WA)

### Gate PASS
- [ ] Simulasi 1 failure (mis: matikan akses folder logs) → alert terbit

---

## Tahap 6 — Runbook + SOP Operasional

**Tujuan:** Orang baru/cabang baru/modul baru → tinggal ikuti SOP.

### Runbook Minimal
- Cara deploy (NAS path, permission folder)
- Cara rollback
- Cara backup/restore
- Cara investigasi error (cek log, request_id, audit trail)
- Cara emergency lock (matikan akses SYS sementara)
- Cara tambah user/office/depo (tanpa bocor scope)
- Cara tambah modul baru (checklist RBAC + menu + smoke)

### Yang Sudah Ada
- `docs/governance/CUTOVER_RUNBOOK.md`
- `tools/nas/PANDUAN_NAS_LENGKAP.md`, `CHECKLIST_TOOLS_NAS.md`
- `docs/governance/data_migration/README_RUNBOOK.md`

### Yang Perlu Dikerjakan
1. **SOP terpusat** — Satu dokumen atau index yang mengarah ke semua runbook.
2. **Validasi runbook** — Orang lain (bukan kamu) bisa ikuti dan menjalankan smoke + cutover gate + backup verify.

### Gate PASS
- [ ] Orang lain bisa ikuti runbook dan menjalankan: smoke + cutover gate + backup verify

---

## Tahap 7 — Growth Governance (Modul Baru)

**Standar Wajib Sebelum Modul Baru Live**
- Ada entry di dokumen menu
- Ada RBAC rule page + action
- Ada smoke test GET + action test
- Ada audit event mapping
- Ada rollback note
- Ada minimal monitoring hook

### Yang Sudah Ada
- `tools/qa/run_cutover_checks.php` — pipeline checks
- `docs/governance/PIPELINE_PLANS_SPEC.md`

### DONE
1. **Checklist modul baru** — docs/ops/CHECKLIST_MODUL_BARU.md (RBAC, menu, smoke, audit, rollback, monitoring).

### Gate PASS
- [ ] `run_cutover_checks --strict` selalu hijau setelah penambahan modul

---

## Urutan Praktis yang Paling Efektif

| # | Tahap | Prioritas | Estimasi | Dependency |
|---|-------|-----------|----------|------------|
| 1 | **Tahap 0** — Lock base path + blok /Volumes | P0 | 1–2 hari | — |
| 2 | **Tahap 1** — RBAC page + action (termasuk FIN special MgrFIN_BGR) | P0 | 3–5 hari | Tahap 0 |
| 3 | **Tahap 2** — Menu/dashboard sinkron dengan RBAC | P1 | 2–3 hari | Tahap 1 |
| 4 | **Tahap 3** — Audit trail end-to-end | P1 | 3–4 hari | — |
| 5 | **Tahap 4** — Backup/restore + verify + dry-run | P1 | 2–3 hari | — |
| 6 | **Tahap 5** — Monitoring + alerting | P1 | 2–3 hari | Tahap 0 (TOOLS_BASE_URL) |
| 7 | **Tahap 6** — Runbook + onboarding | P2 | 2–3 hari | Tahap 4, 5 |
| 8 | **Tahap 7** — Governance modul baru | P2 | 1–2 hari | Tahap 1, 2 |

---

## Langkah Konkret Pertama (Mulai dari Tahap 0)

1. **Verifikasi erp.sh** — Pastikan semua cutover/smoke/rbac matrix dijalankan via `./tools/nas/erp.sh php ...`.
2. **Path police strict** — Jalankan `path_police --write-last --strict`; perbaiki artifact yang masih bocor /Volumes.
3. **TOOLS_BASE_URL di .env** — Set `TOOLS_BASE_URL_INTERNAL`, `SMOKE_BASE_URL` di `.env` agar production tidak pernah dapat TOOLS_BASE_URL_MISSING.
4. **Dokumentasi** — Update `docs/governance/CUTOVER_RUNBOOK.md` dengan instruksi "selalu pakai erp.sh".

---

*Dokumen ini adalah living document. Update setelah setiap tahap selesai.*
