# APP_ROOT Lock Policy — Source of Truth

## Target (HARDCODE)

```
/volume4/web/ERP_RMI_SOFULL
```

## Aturan

1. **Source of Truth:** NAS path di atas. Semua tools automation harus jalan dari sana.
2. **Sentinel:** File `APP_ROOT.SOURCE_OF_TRUTH` di root. Jika tidak ada → guard FAIL.
3. **Reject /Volumes/***: Path macOS (mount share) ditolak. Script harus dijalankan di NAS.
4. **Masking:** Output/log tidak boleh bocorkan path sensitif. Gunakan `[APP_ROOT]` untuk root.

## Cara Pakai

### Guard (source sebelum run)

```bash
. tools/nas/ensure_app_root.sh
# Jika berhasil: pwd = /volume4/web/ERP_RMI_SOFULL
```

### Wrapper runner

```bash
./tools/nas/run_in_app_root.sh php -v
./tools/nas/run_in_app_root.sh php tools/qa/run_cutover_checks.php --write-last --strict
```

### Verifikasi cepat (PHP)

```bash
php tools/dev/verify_app_root.php
# Exit 0 = OK, 2 = mismatch
# Output JSON: { "ok": true/false, "expected": "[APP_ROOT]", "actual": "[APP_ROOT]"|"[MISMATCH]", "host": "..." }
```

## Fail Condition

- Terdeteksi path `/Volumes/*` (macOS) → ABORT
- Target `/volume4/web/ERP_RMI_SOFULL` tidak ada → ABORT
- Sentinel `APP_ROOT.SOURCE_OF_TRUTH` tidak ada → ABORT
- Folder `tools/` atau `storage/` tidak ada → ABORT

## Assumptions Log (on FAIL)

`storage/logs/assumptions_app_root_lock.last.json`

## File Terkait

| File | Fungsi |
|------|--------|
| `APP_ROOT.SOURCE_OF_TRUTH` | Sentinel |
| `tools/nas/ensure_app_root.sh` | Guard script |
| `tools/nas/run_in_app_root.sh` | Wrapper runner |
| `tools/dev/verify_app_root.php` | Verifikasi CLI |
| `tools/nas/run_all_tools_auto.sh` | Auto source guard |

## Verifikasi di NAS

Jalankan di NAS (SSH ke 10.10.60.20 atau terminal NAS):

```bash
cd /volume4/web/ERP_RMI_SOFULL
sh tools/nas/ensure_app_root.sh          # Expected: exit 0, "OK: APP_ROOT locked to [APP_ROOT]"
php tools/dev/verify_app_root.php       # Expected: ok=true, exit 0
./tools/nas/run_in_app_root.sh php -r "echo getcwd(), PHP_EOL;"  # Expected: /volume4/web/ERP_RMI_SOFULL
./tools/nas/run_in_app_root.sh php tools/qa/run_cutover_checks.php --write-last --strict  # Expected: jalan normal
```

Di Mac (workspace /Volumes/...): guard akan FAIL dan menulis `assumptions_app_root_lock.last.json`.
