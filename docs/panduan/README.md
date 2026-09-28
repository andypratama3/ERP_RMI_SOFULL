# Panduan per halaman (ERP_RMI_SOFULL)

## Konvensi path

- URL ERP: `/sales/sales_do.php`
- File panduan: `docs/panduan/sales/sales_do.md` (atau `.html`, `.htm`, `.svg`)

## Cara akses

1. **Help Center** — daftar file yang ada di folder ini.
2. **F1** di halaman ERP — link “Panduan file halaman ini” → `panduan_view.php?p=/path/script.php`
3. Langsung: `/docs/panduan_view.php?f=relatif/dari/panduan.md`

## Izin

Sama seperti dokumen internal lain: **`DOCS.VIEW`** + login (lihat `config/page_registry.php`).

## Migrasi dari Office Pack

Konten HTML/PDF lama dari `docs/officepack/` tidak ikut di repo ini; salin manual ke `docs/panduan/` bila masih diperlukan, atau tulis ulang ringkas sebagai `.md`.
