# NAS Base Path Lock

## Base Path (Source of Truth)

```
/volume4/web/ERP_RMI_SOFULL
```

## Alasan: /Volumes vs /volume4

- **/volume4/** = path internal Synology NAS (volume storage)
- **/Volumes/** = path macOS saat mount share via SMB/AFP
- CLI runner di NAS **harus** jalan dari /volume4/web/ERP_RMI_SOFULL
- Jika dijalankan dari Mac (path /Volumes/...) → guard FAIL

## Verifikasi

```bash
cd /volume4/web/ERP_RMI_SOFULL
pwd
# Expected: /volume4/web/ERP_RMI_SOFULL
```

## Guard

- **Shell:** `tools/nas/guard_app_root.sh` — source di runner
- **PHP:** `tools/_shared/app_root_guard.php` — require di CLI runner (opsional)
- **Assert:** `tools/nas/assert_app_root.php` — dipanggil oleh run_all_tools_auto.sh
