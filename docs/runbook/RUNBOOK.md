# RUNBOOK — Operasi Harian & Incident

**Base:** `/volume4/web/ERP_RMI_SOFULL`  
**Wajib:** Jalankan dari NAS. Dari Mac mount `/Volumes/` → FAIL.

---

## 1. Operasi Harian

### 1.1 Backup Otomatis

Backup harian via cron (contoh jam 23:00):

```cron
0 23 * * * cd /volume4/web/ERP_RMI_SOFULL && ./tools/backup_now.sh --label auto_2300 >> storage/logs/backup_cron.log 2>&1
```

### 1.2 Cutover Gate (Pre-Release)

Sebelum deploy/rilis, jalankan:

```bash
cd /volume4/web/ERP_RMI_SOFULL || exit 1
./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --write-last --strict
```

**PASS:** `overall_ok=true`, artifact `storage/logs/cutover_checks.last.json`

### 1.3 Smoke HTTP (Quick Health)

```bash
./tools/nas/erp.sh php tools/smoke_http.php --strict --write-last
```

**PASS:** `fail=0`, artifact `storage/logs/smoke_http_last.json`

### 1.4 Evaluate Alerts (Monitoring)

```bash
php tools/ops/evaluate_alerts.php
```

**Artifact:** `storage/logs/alerts_last.json`, `storage/logs/ops_alerts_last.json`  
**Cron contoh (setiap 6 jam):**

```cron
0 */6 * * * cd /volume4/web/ERP_RMI_SOFULL && php tools/ops/evaluate_alerts.php >> storage/logs/evaluate_alerts.log 2>&1
```

### 1.5 Ops Snapshot (Readiness)

```bash
php tools/ops/generate_ops_snapshot.php
```

**Cron contoh (harian):**

```cron
5 0 * * * cd /volume4/web/ERP_RMI_SOFULL && php tools/ops/generate_ops_snapshot.php >> storage/logs/ops_snapshot.log 2>&1
```

---

## 2. Incident Response

### 2.1 Aplikasi Tidak Responsif

1. Cek health: `curl -sS "http://10.10.60.20/ERP_RMI_SOFULL/api/v1/health.php" | jq .`
2. Cek error log: `tail -100 storage/logs/error.log`
3. Cek PHP-FPM / web server: `systemctl status nginx` atau `systemctl status php-fpm`
4. Restart jika perlu: `sudo systemctl restart nginx php-fpm`

### 2.2 Database Error

1. Cek koneksi: `php tools/preflight_check.php`
2. Cek `.env`: `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`
3. Cek MySQL/MariaDB: `systemctl status mariadb` atau `systemctl status mysql`

### 2.3 Smoke / Contract Fail

1. Baca artifact: `storage/logs/smoke_http_last.json`, `storage/logs/contract_check_last.json`
2. Cek URL yang fail — mungkin redirect, 403, atau 500
3. Cek RBAC: user punya permission? FIN approval hanya MgrFIN_BGR + SYS
4. Jalankan ulang: `./tools/nas/erp.sh php tools/smoke_http.php --strict --write-last`

### 2.4 Path / Volumes Violation (CRITICAL)

Jika `path_guard` atau `path_police` FAIL:

1. **Jangan deploy.** Artifact mengandung `/Volumes/` atau path Mac.
2. Cek artifact: `storage/logs/path_guard_last.json`
3. Grep repo: `grep -r "/Volumes/" --include="*.php" tools/ storage/`
4. Perbaiki: ganti path ke `[APP_ROOT]` atau relative, jangan hardcode `/Volumes/`
5. Base project wajib: `/volume4/web/ERP_RMI_SOFULL` (NAS)

### 2.5 Backup / Restore Gagal

1. Cek `storage/logs/backup_restore_gate_last.json` — step mana yang fail
2. Backup: `tools/backup_now.sh` — cek disk space, permission `storage/backups/`
3. Verify: checksum — pastikan `checksums.sha256` dan file ada
4. Restore dry-run: `tools/restore_now.sh --from <package> --dry-run --restore-db --restore-files`

### 2.6 RBAC / 403 pada User

1. Cek dept + role: `master/master_system_login.php`
2. Cek permission: RBAC Center `rbac/index.php`
3. FIN approval: hanya `MgrFIN_BGR` + SYS yang boleh approve/pay
4. Rule: `_shared/rbac_policy.php`, `config/rbac_permissions.php`

---

## 3. Cron Examples (Consolidated)

```cron
# Backup harian jam 23:00
0 23 * * * cd /volume4/web/ERP_RMI_SOFULL && ./tools/backup_now.sh --label auto_2300 >> storage/logs/backup_cron.log 2>&1

# Evaluate alerts setiap 6 jam
0 */6 * * * cd /volume4/web/ERP_RMI_SOFULL && php tools/ops/evaluate_alerts.php >> storage/logs/evaluate_alerts.log 2>&1

# Ops snapshot harian jam 00:05
5 0 * * * cd /volume4/web/ERP_RMI_SOFULL && php tools/ops/generate_ops_snapshot.php >> storage/logs/ops_snapshot.log 2>&1

# Cutover gate (opsional, misalnya sebelum rilis mingguan)
0 8 * * 1 cd /volume4/web/ERP_RMI_SOFULL && ./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --write-last --strict >> storage/logs/cutover_cron.log 2>&1
```

---

## 4. Artifact Reference

| Artifact | Sumber | Untuk |
|----------|--------|-------|
| `path_guard_last.json` | path_guard.php | Path policy, no /Volumes/ |
| `smoke_http_last.json` | smoke_http.php | HTTP smoke |
| `contract_check_last.json` | contract_check.php | API contract |
| `rbac_smoke_matrix_last.json` | rbac_matrix_http_check | RBAC GET |
| `rbac_action_matrix_last.json` | rbac_action_matrix | RBAC POST |
| `backup_restore_gate_last.json` | backup_restore_gate.php | Backup/restore verify |
| `alerts_last.json` | evaluate_alerts.php | Monitoring alerts |
| `cutover_checks.last.json` | run_cutover_checks.php | Cutover gate |

---

*Update: Final Hardening Execution.*
