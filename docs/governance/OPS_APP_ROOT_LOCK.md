# OPS APP ROOT LOCK

## Tujuan

Mencegah tools & QA runner jalan dari folder salah. Mismatch → FAIL fast.

## Source of Truth

File `.expected_app_root` di root project. Isi 1 baris: `/volume4/web/ERP_RMI_SOFULL`

## Cara Ganti Path (jika pindah folder)

1. Edit `.expected_app_root`
2. Ubah isi ke path baru (1 baris)
3. Tidak perlu ubah kode lain

## Cara Verifikasi

### C1) Verifikasi di NAS (path benar)

```bash
cd /volume4/web/ERP_RMI_SOFULL
php -r "echo realpath(getcwd()).PHP_EOL;"
php tools/qa/run_cutover_checks.php --write-last --strict
```

Expected: tidak ada error "APP_ROOT mismatch", runner jalan normal.

### C2) Verifikasi fail fast (folder salah)

```bash
cd /volume4/web
php ERP_RMI_SOFULL/tools/qa/run_cutover_checks.php --write-last --strict
```

Expected: FAIL dengan pesan aman (masked), exit code non-zero.

### C2-alt) Dari Mac (path /Volumes)

```bash
cd /Volumes/web/ERP_RMI_SOFULL
php tools/qa/run_cutover_checks.php --write-last --strict
```

Expected: FAIL (APP_ROOT mismatch).
