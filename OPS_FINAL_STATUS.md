# OPS_FINAL_STATUS — ERP_RMI_SOFULL Tools Normalization

**Target:** Tools normal + cutover_checks overall_ok=true  
**Server:** Synology NAS  
**APP_ROOT:** `/volume4/web/ERP_RMI_SOFULL`  
**Generated:** 2026-03-01

---

## 1. Ringkasan Status

| Step | Status | Catatan |
|------|--------|---------|
| STEP 0 | ✅ Siap | `tools/nas/find_php_bin.sh` untuk cari PHP dengan pdo_mysql |
| STEP 1 | ⏳ WAJIB di NAS | `lock_app_root.php` hanya bisa dijalankan dari /volume4/web/ERP_RMI_SOFULL |
| STEP 2 | ✅ Selesai | .env: TOOLS_BASE_URL_INTERNAL, TOOLS_BASE_URL_PUBLIC, APP_PUBLIC_URL |
| STEP 3 | ⏳ WAJIB di NAS | PHP default mungkin tidak punya pdo_mysql; pakai ERP_PHP_BIN |
| STEP 4 | ✅ Selesai | contract_check.php ada, run_cutover memakai path absolut dan envPrefix |
| STEP 5 | ✅ Selesai | tools_dashboard_smoke.php pakai tools_get_base_url() |
| STEP 6 | ⏳ Jalankan di NAS | `run_cutover_on_nas.sh` atau manual |
| STEP 7 | ⏳ Partial | cli_capability.php ada; CSRF/ADMIN_GUARD perlu implementasi per tool |

---

## 2. Perubahan Yang Sudah Dilakukan

### 2.1 Base URL
- **tools/_lib/tools_http.php** — `tools_get_base_url()` prioritas: TOOLS_BASE_URL_INTERNAL > TOOLS_BASE_URL > APP_BASE_URL > APP_URL
- **tools/_lib/tools_http.php** — `tools_get_base_url_public()` untuk pinned links (TOOLS_BASE_URL_PUBLIC > APP_PUBLIC_URL)
- **tools/qa/run_cutover_checks.php** — `$baseUrl` pakai TOOLS_BASE_URL_INTERNAL; envPrefix set TOOLS_BASE_URL_INTERNAL
- **tools/smoke_http.php** — `$base` pakai TOOLS_BASE_URL_INTERNAL dulu
- **tools/qa/contract_check.php** — baca TOOLS_BASE_URL_INTERNAL
- **tools/qa/tools_dashboard_smoke.php** — `tools_get_base_url()` + rmi_env_load

### 2.2 .env
```
TOOLS_BASE_URL_INTERNAL=http://10.10.60.20/ERP_RMI_SOFULL
TOOLS_BASE_URL_PUBLIC=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL
TOOLS_BASE_URL=http://10.10.60.20/ERP_RMI_SOFULL
APP_URL=http://10.10.60.20/ERP_RMI_SOFULL
APP_PUBLIC_URL=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL
```

### 2.3 File Baru
- `tools/dev/php_driver_check.php` — cek PDO MySQL, output ke storage/logs/php_driver_check_last.txt
- `tools/dev/db_diag.php` — CLI DB diagnostic
- `tools/qa/contract_check.php` — CLI contract check health/auth/stock envelope
- `tools/qa/tools_dashboard_smoke.php` — CLI smoke tools dashboard
- `tools/_shared/cli_capability.php` — `tools_can_shell()` untuk SHELL_IN_WEB
- `tools/nas/find_php_bin.sh` — cari PHP dengan pdo_mysql
- `tools/nas/run_cutover_on_nas.sh` — runbook cutover di NAS

---

## 3. Runbook NAS (WAJIB dijalankan di NAS via SSH)

```bash
# 1) SSH ke NAS
ssh user@nas-ip

# 2) Masuk ke APP_ROOT
cd /volume4/web/ERP_RMI_SOFULL
pwd   # harus /volume4/web/ERP_RMI_SOFULL

# 3) Cari PHP dengan pdo_mysql
sh tools/nas/find_php_bin.sh
# Simpan output sebagai PHP_OK_BIN (misal /usr/local/bin/php84)

# 4) Lock app root
$PHP_OK_BIN php tools/dev/lock_app_root.php

# 5) Cek PDO driver
$PHP_OK_BIN php tools/dev/php_driver_check.php
# Evidence: storage/logs/php_driver_check_last.txt

# 6) Smoke tools guest
$PHP_OK_BIN php tools/qa/smoke_tools_guest.php --write-last
# Evidence: storage/logs/smoke_tools_guest_last.json

# 7) Run cutover

export ERP_PHP_BIN="$PHP_OK_BIN"
$PHP_OK_BIN php tools/qa/run_cutover_checks.php --env=staging --write-last --strict

# Atau pakai script:
chmod +x tools/nas/run_cutover_on_nas.sh
./tools/nas/run_cutover_on_nas.sh

# 8) Tools doctor
$PHP_OK_BIN tools/qa/tools_doctor.php --write-last --apply-safe-fixes
# (--apply-safe-fixes = alias untuk --apply, backup files ke storage/backups/)
# Evidence: storage/logs/tools_doctor_last.json

# 9) AUTOMATISASI — jalankan semua tools sekaligus
chmod +x tools/nas/run_all_tools_auto.sh
./tools/nas/run_all_tools_auto.sh

# Cron (6 jam sekali): 0 */6 * * * /volume4/web/ERP_RMI_SOFULL/tools/nas/run_all_tools_auto.sh
```

---

## 4. Daftar Artifact Path

| Artifact | Path |
|----------|------|
| Cutover checks | `storage/logs/cutover_checks.last.json` |
| Smoke tools guest | `storage/logs/smoke_tools_guest_last.json` |
| Smoke tools dashboard | `storage/logs/tools_dashboard_smoke.last.json` |
| Smoke HTTP | `storage/logs/smoke_http_last.json` |
| Contract check | `storage/logs/contract_check_last.json` |
| Preflight | `storage/logs/preflight_check.last.json` |
| PHP driver check | `storage/logs/php_driver_check_last.txt` |
| Tools doctor | `storage/logs/tools_doctor_last.json` |
| Assumptions log | `storage/logs/assumptions_last.json` |
| App root lock | `storage/logs/app_root_lock.json` |

---

## 5. DONE CRITERIA

- [ ] `smoke_tools_guest_last.json` ok=true
- [ ] `cutover_checks.last.json` overall_ok=true
- [ ] `tools_doctor_last.json` base_url_present=true, tidak ada CRITICAL
- [ ] Control Center tidak ada banner CRITICAL merah
- [ ] Pinned links pakai PUBLIC URL (tools_get_base_url_public())

---

## 6. Penyebab Gagal Saat Ini (jika dijalankan dari Mac)

1. **APP_ROOT_MISMATCH** — lock_app_root gagal karena getcwd() = /Volumes/web/... (bukan /volume4/web/...)
2. **could not find driver** — PHP default Mac mungkin tidak punya pdo_mysql (jarang)
3. **SSL certificate** — smoke_http ke https://erp... dari Mac bisa curl_errno=60

**Solusi:** Semua step harus dijalankan **di NAS** via SSH.
