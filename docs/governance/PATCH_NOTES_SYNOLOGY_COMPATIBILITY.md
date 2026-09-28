# Patch Notes: Synology NAS Compatibility

_Tanggal: 2026-03-11_

## Konteks

Project berjalan di Synology NAS (`/volume4/web/ERP_RMI_SOFULL`).
Web server berjalan sebagai user `http` (uid=1023).
PHP CLI berjalan sebagai user `RizqullahMediska`.
Mac mount path: `/Volumes/web/ERP_RMI_SOFULL` (read-heavy, beberapa file tidak writable dari Mac).

---

## Aturan Konsistensi (Wajib Diikuti)

### 1. RecursiveDirectoryIterator — Selalu CATCH_GET_CHILD

Synology menyimpan metadata di direktori `@eaDir` yang sering tidak bisa dibaca oleh web server.
**Semua** penggunaan `RecursiveIteratorIterator` harus menggunakan flag `CATCH_GET_CHILD`:

```php
// BENAR
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
    RecursiveIteratorIterator::SELF_FIRST,
    RecursiveIteratorIterator::CATCH_GET_CHILD  // <-- wajib di Synology
);

// SALAH (akan crash di Synology)
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
);
```

### 2. ZIP — Exclude Synology Metadata

Semua perintah `zip` shell harus mengecualikan file metadata Synology:

```bash
zip -rq output.zip . \
    -x "*@eaDir*" "*.Symlink" "*.SYNOPHOTO*" "*.SYNOINDEX*"
```

### 3. ZIP — Temp File di /tmp

`ZipArchive` harus menulis temp file ke `/tmp` (writable oleh semua user), bukan ke `storage/`:

```php
$storageTmp = sys_get_temp_dir();  // /tmp
$zipPathTmp = $storageTmp . '/nama_file_' . $stamp . '.zip.tmp';
if (!is_writable($storageTmp)) {
    $storageTmp = $root . '/storage/logs';
    $zipPathTmp = $storageTmp . '/nama_file_' . $stamp . '.zip.tmp';
}
```

### 4. Output Direktori — Cek Writability

Jika output dir (`exports/deploy/`) tidak writable oleh web server, fallback ke `storage/exports/`:

```php
if (is_writable($outDirPrimary)) {
    $outDir = $outDirPrimary;
} else {
    $outDir = $root . '/storage/exports/deploy/' . $bucket;
    @mkdir($outDir, 0775, true);
}
```

### 5. tools_path_policy.php — Exempt /volume4/

Scan `/Volumes/` token tidak berlaku untuk path NAS. Fungsi `tools_scan_forbidden_tokens_in_storage_logs()` sudah di-patch:

```php
if (strpos(str_replace('\\', '/', (string)$root), '/volume4/') === 0) {
    return [];
}
```

### 6. Backup — Non-Fatal Exit Codes

`backup_now.sh` memperlakukan exit code zip 0,1,2,18 sebagai warning (non-fatal):
- Trap ERR dinonaktifkan selama proses zip
- `LATEST_BACKUP.txt` write failure dilewati dengan `|| true`

### 7. Storage Permissions

Direktori storage harus dimiliki oleh user web server (`http`):
```bash
sudo chown http:http storage/logs storage/backups storage/uploads
chmod 775 storage/logs storage/backups storage/uploads
```

---

## File Yang Sudah Di-Patch

| File | Patch |
|------|-------|
| `tools/backup_now.sh` | CATCH_GET_CHILD equiv + @eaDir exclude + exit 18 non-fatal |
| `tools/dev/ban_non_ascii_paths.php` | SkipEaDirFilter class |
| `tools/_shared/tools_path_policy.php` | Return [] jika /volume4/ |
| `tools/release/create_clean_deploy_zip.php` | CATCH_GET_CHILD + /tmp temp + writable fallback |
| `tools/release/make_deploy_zip.php` | CATCH_GET_CHILD |
| `tools/tools_state_lib.php` | Tambah fungsi business_signoff_read_state, tools_read_state_json, tools_contract_state_read |

---

## Checklist untuk File Baru

Saat membuat tool baru yang scan filesystem, pastikan:
- [ ] `RecursiveDirectoryIterator` pakai `CATCH_GET_CHILD`
- [ ] Zip command exclude `*@eaDir*`
- [ ] ZipArchive temp file ke `sys_get_temp_dir()`
- [ ] Output dir cek `is_writable()` sebelum dipakai
