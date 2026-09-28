# Cyrillic Policy — No Cyrillic di Repo

## Larangan

- **Karakter Cyrillic** (U+0400–U+04FF) dilarang di:
  - Nama file
  - Nama folder
  - Route / URL / link
  - String include/require path

- **Confusable risk:** Karakter Cyrillic yang mirip Latin (а е о р с у х) berpotensi homograph — tidak boleh di konteks path/route.

## Cara Cek

```bash
php tools/qa/no_cyrillic_guard.php
```

Output: `storage/logs/no_cyrillic_guard_last.json`

- `ok: true` → PASS
- `total_hits > 0` → NO-GO

## Gate

Kalau hit > 0 → **NO-GO**. Perbaiki dulu sebelum deploy/cutover.

## Apply Fix (Guarded)

```bash
php tools/dev/cyrillic_fix.php --apply --i-understand
```

- Hanya rename file/folder yang mengandung Cyrillic di namanya
- Update referensi string di repo
- Collision → FAIL + assumptions log

## Mapping Confusable

| Cyrillic | Latin |
|----------|-------|
| а е о р с у х | a e o p c y x |
| А Е О Р С У Х | A E O P C Y X |

Karakter Cyrillic lain → `_uXXXX` (codepoint hex)

## CYRILLIC_RENAME_MAP.md

Dibuat hanya saat `cyrillic_fix.php --apply` dijalankan dan ada rename.
