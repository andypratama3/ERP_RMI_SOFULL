# TESTPLAN_APP_ROOT_LOCK — Verifikasi 5 Menit

## Prasyarat

Jalankan di **NAS** (SSH ke 10.10.60.20 atau terminal NAS).

## Checklist

### 1. pwd harus /volume4/web/ERP_RMI_SOFULL

```bash
cd /volume4/web/ERP_RMI_SOFULL
pwd
# Expected: /volume4/web/ERP_RMI_SOFULL
```

### 2. run_all_tools_auto.sh sukses

```bash
cd /volume4/web/ERP_RMI_SOFULL
./tools/nas/run_all_tools_auto.sh
# Expected: overall_ok=true (atau fail karena kondisi sistem, bukan path)
# Tidak ada TOOLS_BASE_URL_MISSING
# Artifact terupdate di storage/logs/
```

### 3. smoke_http strict fail=0

```bash
cd /volume4/web/ERP_RMI_SOFULL
export TOOLS_BASE_URL_INTERNAL="http://10.10.60.20/ERP_RMI_SOFULL"
php tools/qa/smoke_http.php --strict --write-last
# Expected: fail=0
```

### 4. base_url tidak double slash

```bash
# Cek output/artifact tidak mengandung http://// atau ///
grep -r 'http:///' storage/logs/*.json 2>/dev/null && echo "FOUND double slash" || echo "OK: no double slash"
```

### 5. Tidak ada write/patch di luar APP_ROOT

```bash
# Verifikasi semua artifact di bawah APP_ROOT
ls -la storage/logs/app_root_lock.last.json
ls -la storage/logs/assumptions_no_question.log
# Keduanya harus ada dan path relatif ke project
```

### 6. Guard standalone

```bash
. tools/nas/guard_app_root.sh
echo "APP_ROOT=$APP_ROOT"
# Expected: APP_ROOT=/volume4/web/ERP_RMI_SOFULL
```

### 7. backup_now.sh & smoke_nightly.sh

```bash
# Dry run (backup bisa skip jika lock)
./tools/backup_now.sh --skip-db 2>&1 | head -5
# Expected: tidak error "Cannot cd" atau path mismatch
```

## FAIL Condition

- hostname=Mac atau pwd=/Volumes/* → ABORT, tulis assumptions_no_question.log
- /volume4/web/ERP_RMI_SOFULL tidak ada → ABORT
