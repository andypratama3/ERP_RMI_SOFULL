# PATCH_DIFF_APP_ROOT_LOCK

## Ringkasan

Hard lock APP_ROOT NAS + remote runner. Semua tools wajib dieksekusi di `/volume4/web/ERP_RMI_SOFULL`. Dari Mac mount (`/Volumes/...`) → FAIL dengan `RUN_ON_NAS_REQUIRED`.

## File yang Diubah/Dibuat

### A) tools/nas/erp.sh
- Guard di awal: detect `/Volumes/`, Darwin, script root → exit 2
- Pesan: `RUN_ON_NAS_REQUIRED: please SSH to NAS and run from /volume4/web/ERP_RMI_SOFULL`
- Export APP_ROOT, TOOLS_BASE_URL
- Ganti `exec` dengan run + tulis `storage/logs/erp_sh_last.json` (run_at, cwd, cmd, ok)

### B) tools/nas/assert_app_root.sh
- Exit code 1 → 2
- Pesan error: `RUN_ON_NAS_REQUIRED: please SSH to NAS and run from /volume4/web/ERP_RMI_SOFULL`
- Tambah guard: script root mengandung `/Volumes/` → exit 2

### C) tools/nas/remote_run.sh (BARU)
- Wajib env: `NAS_SSH_TARGET`
- Default: `NAS_APP_ROOT=/volume4/web/ERP_RMI_SOFULL`
- `ssh -o BatchMode=yes "${NAS_SSH_TARGET}" "cd ${NAS_APP_ROOT} && ./tools/nas/erp.sh <cmd>"`
- Mask output: `${NAS_APP_ROOT}` → `[APP_ROOT]`
- Artifact: `storage/logs/remote_run_last.json`
- Jika NAS_SSH_TARGET kosong → FAIL, tulis assumptions

### D) tools/nas/README_RUN_ON_NAS.md (BARU)
- Penjelasan /Volumes vs /volume4
- Golden rule: edit dari Mac, eksekusi dari NAS
- Command SSH manual + remote_run

## Gate PASS (Verifikasi)

| Test | Expected |
|------|----------|
| NAS: `./tools/nas/erp.sh php -r "echo getcwd();"` | Output diawali `/volume4/web/ERP_RMI_SOFULL` |
| NAS: `./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --write-last --strict` | Jalan normal |
| Mac: `cd /Volumes/web/ERP_RMI_SOFULL && ./tools/nas/erp.sh php -r "echo X;"` | FAIL: RUN_ON_NAS_REQUIRED |

## Artifacts

- `storage/logs/erp_sh_last.json` — contoh (dari run di NAS)
- `storage/logs/remote_run_last.json` — contoh (dari remote_run atau fail NAS_SSH_TARGET)
