# Checklist Deploy Docs ke NAS

File yang perlu di-copy ke NAS saat update docs/proxy.

## Proxy & Token (wajib jika ada perubahan)

| File | Path | Keterangan |
|------|------|------------|
| _token_helper.php | `docs/_token_helper.php` | Helper token akses docs (iframe) |
| docs_view.php | `docs/docs_view.php` | Proxy governance/ops/archive |
| officepack_view.php | `docs/officepack_view.php` | Proxy officepack |
| help_center.php | `docs/help_center.php` | Help Center + token |

## .htaccess (proteksi akses langsung)

| File | Path |
|------|------|
| docs/.htaccess | `docs/.htaccess` |
| docs/officepack/.htaccess | `docs/officepack/.htaccess` |
| docs/governance/.htaccess | `docs/governance/.htaccess` |
| docs/ops/.htaccess | `docs/ops/.htaccess` |
| docs/archive/.htaccess | `docs/archive/.htaccess` |

## Office Pack (jika ada dokumen baru)

- `docs/officepack/INDEX_v3.1.html` — selalu copy jika di-update
- `docs/officepack/INDEX_v3.1.pdf` — regenerate dari HTML (lihat REGENERATE_INDEX_PDF_DOCX.md)
- `docs/officepack/INDEX_v3.1.docx` — regenerate dari HTML (opsional)
- Folder: `pp/`, `dept_training/`, `forms/`, `policies/`, `checklists/` — copy file baru

## Catatan

- **_token_helper.php, docs_view.php, officepack_view.php** tidak punya daftar dokumen hardcoded. Mereka melayani file berdasarkan path. Dokumen baru otomatis terlayani tanpa ubah kode.
- **INDEX_v3.1.pdf & .docx** — format binary, harus di-regenerate manual dari HTML setelah INDEX_v3.1.html di-update.
