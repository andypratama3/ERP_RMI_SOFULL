# Unicode Policy — ERP_RMI_SOFULL

## Aturan

- **ASCII-only** untuk file path & URL route.
- **Cyrillic/Confusable** dilarang di path, link, endpoint, include/require.
- Karakter confusable (contoh 'а' Cyrillic vs 'a' latin) berpotensi homograph attack — tidak boleh di konteks path/URL.

## Cara Menjalankan Unicode Guard

```bash
php tools/qa/unicode_guard.php --scan --strict --write-last
```

- **--scan**: Scan path + content
- **--strict**: Fail jika ada Cyrillic di path atau content (termasuk general)
- **--write-last**: Simpan hasil ke `storage/logs/unicode_guard_last.json`

Exit code: 0 = OK, 2 = FAIL (ada temuan Cyrillic).

## Gate di Cutover Checks

`unicode_guard` dijalankan sebagai step pertama di `run_cutover_checks.php`. Jika fail → overall_ok=false (CRITICAL).

## Artifact

- `storage/logs/unicode_guard_last.json` — hasil scan terakhir

## Mapping Confusable (untuk fix)

Lowercase: а→a, е→e, о→o, р→p, с→c, х→x, у→y, і→i  
Uppercase: А→A, В→B, Е→E, К→K, М→M, Н→H, О→O, Р→P, С→C, Т→T, Х→X, У→Y
