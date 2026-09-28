# APP_ROOT Policy — Source of Truth

## Source-of-Truth Path

```
/volume4/web/ERP_RMI_SOFULL
```

Semua pekerjaan, command, dan perubahan **HANYA** pada path ini.

## /Volumes/... Adalah Mount macOS

- Path `/Volumes/web/ERP_RMI_SOFULL` = mount share dari NAS di Mac.
- **Tidak boleh** dipakai untuk CLI di NAS.
- Jika tool dijalankan dari path salah → **FAIL** (bukan lanjut).

## Kalau Salah Path

Tool harus **FAIL** dan menulis `storage/logs/assumptions_log_last.json`.

## Contoh Command Benar

```bash
cd /volume4/web/ERP_RMI_SOFULL
/usr/local/bin/php82 tools/nas/assert_app_root.php
```

Expected: `OK: APP_ROOT locked` dan file `storage/logs/app_root_lock_last.json` terbuat dengan `ok: true`.

## Assert Sebelum Run

```bash
cd /volume4/web/ERP_RMI_SOFULL
/usr/local/bin/php82 tools/nas/assert_app_root.php || exit $?
./tools/nas/run_all_tools_auto.sh
```

## File Terkait

| File | Fungsi |
|------|--------|
| `tools/nas/assert_app_root.php` | Assert path + sentinel, tulis lock/assumptions |
| `.APP_ROOT_LOCK.json` | Sentinel marker di root |
| `storage/logs/app_root_lock_last.json` | State lock (ok=true saat PASS) |
| `storage/logs/assumptions_log_last.json` | Log saat FAIL (masked) |
