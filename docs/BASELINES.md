# Baselines — ERP RMI SOFULL

Dokumen ini mendefinisikan **semua baseline** di ERP_RMI_SOFULL dengan terminologi konsisten dan urutan deploy.

---

## Glosarium (Single Source of Truth)

| ID | Nama Resmi | Makna | Lokasi |
|----|------------|-------|--------|
| **RBAC_DEFAULT** | RBAC Default | Role & permission default untuk STAFF/MANAGER per dept | `_shared/rbac*.php`, `rbac/index.php` |
| **STOCK_SNAPSHOT** | Stock Snapshot | Saldo stok saat go-live; setelah lock, perubahan hanya via Incoming/Picking/Adjustment | `stock/wqs_stock.php`, `wqs_stock_baseline_lock` |
| **OPS_HARDENING** | Ops Hardening Snapshot | Kondisi audit keamanan sebagai referensi delta | `tools/`, `storage/logs/ops_hardening_full_baseline.json` |
| **MFA_PHASE** | MFA Policy Phase | Fase rollout kebijakan MFA (1=moderat, 2=moderate+, 3=strict) | `master/mfa_policy.php` |
| **PERF_BASELINE** | Perf Baseline | Ukuran latency/disk/memory untuk monitoring | `tools/perf/perf_baseline.php` |

**Aturan:** Dalam UI, komentar, dan dokumentasi, gunakan **Nama Resmi** agar tidak ambigu.

---

## Detail per Baseline

### 1. RBAC Default (RBAC_DEFAULT)

- **Tujuan:** Mengisi role default (SYS.SUPERADMIN, SYS.ADMIN, ORG.DIRECTOR) + role per dept (WQS, PQP, FIN, SCM, ACT, CRM, MPR, HRL, ITC) × level (STAFF, MANAGER).
- **Apply:** RBAC Center → "Apply RBAC Default"
- **Idempotent:** Ya (tidak menimpa rules custom kecuali opsi "Replace rules" dicentang).
- **Kapan:** Setelah deploy baru, atau saat menambah dept/level.
- **Permission Catalog (Single Source of Truth):** `config/rbac_permissions.php` — 319 entries, 0 duplikat. Sync bersifat ADDITIVE (INSERT ON DUPLICATE KEY UPDATE). Lihat: `docs/RBAC_PERMISSION_GUIDE.md` → section "Single Source of Truth".

### 2. Stock Snapshot (STOCK_SNAPSHOT)

- **Tujuan:** Menetapkan saldo awal stok (manual atau CSV) lalu lock agar perubahan hanya lewat transaksi formal.
- **Flow:** Set Stock Snapshot (Manual) / Import CSV → Lock Snapshot → Incoming / Picking / Adjustment.
- **Tabel:** `wqs_stock_baseline_lock`
- **Kapan:** Saat go-live gudang; setelah lock tidak bisa diubah Stock Snapshot.

### 3. Ops Hardening Snapshot (OPS_HARDENING)

- **Tujuan:** Menyimpan kondisi audit keamanan saat ini sebagai referensi; delta berikutnya dibandingkan dengan snapshot ini.
- **File:** `storage/logs/ops_hardening_full_baseline.json`
- **Reset:** Tools → Ops Hardening Audit → "Reset Hardening Snapshot"
- **Kapan:** Setelah hardening selesai, atau saat ingin mengubah referensi.

### 4. MFA Policy Phase (MFA_PHASE)

- **Tujuan:** Rollout MFA bertahap (Phase 1–3).
- **Phase 1:** SYS/MANAGER required; STAFF hanya FIN/ACT/SYS.
- **Phase 2:** + ADMIN/SUPERADMIN required.
- **Phase 3:** Semua role required.
- **Apply:** Master → MFA Policy → "Apply Phase 1/2/3"
- **Kapan:** Sesuai roadmap keamanan.

### 5. Perf Baseline (PERF_BASELINE)

- **Tujuan:** Mengukur latency DB, file I/O, memory, disk untuk monitoring.
- **Output:** `storage/logs/perf_baseline_last.json`
- **Kapan:** Sebelum go-live (referensi) dan periodik untuk drift detection.

---

## Checklist Deploy (Urutan Disarankan)

| # | Langkah | Baseline | Wajib |
|---|---------|----------|-------|
| 1 | Migrasi DB | — | Ya |
| 2 | Apply RBAC Default | RBAC_DEFAULT | Ya |
| 3 | Set Stock Snapshot (jika go-live gudang) | STOCK_SNAPSHOT | Jika WQS aktif |
| 4 | Lock Stock Snapshot | STOCK_SNAPSHOT | Setelah set selesai |
| 5 | Apply MFA Policy Phase 1 | MFA_PHASE | Disarankan |
| 6 | Run Perf Baseline | PERF_BASELINE | Disarankan |
| 7 | Run Ops Hardening → Reset Hardening Snapshot | OPS_HARDENING | Setelah hardening selesai |

---

## Mapping Kode → Glosarium

| File / Tabel | Baseline ID |
|--------------|-------------|
| `rbac_apply_baseline()` | RBAC_DEFAULT |
| `wqs_stock_baseline_lock` | STOCK_SNAPSHOT |
| `ops_hardening_full_baseline.json` | OPS_HARDENING |
| `mfa_policy.php` apply_phase | MFA_PHASE |
| `perf_baseline.php` | PERF_BASELINE |

### Catatan CLI / Internal

- OPS_HARDENING: flag `--baseline-reset`, `--baseline-dry-run` tetap dipakai (backward compat).
- Tabel `wqs_stock_baseline_lock`, kolom `baseline_qty` tidak diubah (schema).
- Audit event `BASELINE_MANUAL`, `LOCK_BASELINE` tidak diubah (audit trail).

---

## Verifikasi

Setelah perubahan:

1. **RBAC Default:** Buka RBAC Center → pastikan role terisi.
2. **Stock Snapshot:** Buka WQS Stock → cek status Lock.
3. **Ops Hardening:** Buka Tools → Ops Hardening → cek badge READY/MISSING.
4. **MFA Phase:** Buka Master → MFA Policy → cek baris policy.
5. **Perf Baseline:** Jalankan `php tools/perf/perf_baseline.php` → cek `perf_baseline_last.json`.
