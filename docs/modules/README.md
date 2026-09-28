# Folder `docs/modules/`

## Tujuan

- **Panduan utama = per modul** (alur departemen, halaman penting).
- **Bukan** satu file per halaman ERP — kecuali layar kritis (diarahkan dari tabel “Deep-dive”).

## Isi

| File | Modul |
|------|--------|
| `registry.json` | Metadata untuk UI **Help Center → Panduan per modul** (`modules_hub.php`). |
| `*.md` | Konten panduan; dibuka via `docs/docs_view.php?f=modules/NAMA.md` (login). |
| **`MODULE_DOC_CHECKLIST.md`** | **Checklist 1 halaman** — per modul: sudah ada apa / kurang apa (sebelum rapikan portal dokumen). |

## Panduan dalam ERP vs Markdown

| Jenis | Kapan dipakai |
|-------|----------------|
| **`panduan_in_app`** di `registry.json` | Path aman ke `*.php` di modul (satu segmen folder), mis. `/purchases/panduan.php` — **tampilan kaya** (HTML/CSS), sama shell ERP. **Disarankan** untuk user akhir. |
| **`file` .md** | Ringkasan + tabel path untuk maintainer / cetak; tombol sekunder di `modules_hub.php`. |

Field `panduan_in_app` harus match pola: `/folder/file.php` (satu level folder, hanya huruf/angka/`_`/`-`).

## Menambah modul

1. (Opsional) Buat halaman `modul/panduan.php` dalam aplikasi — pola seperti `purchases/panduan.php`.
2. Tambahkan `nama_modul.md` di folder ini (ringkasan + tabel path), **atau** hanya `panduan_in_app` jika belum ada ringkasan.
3. Tambahkan entri di `registry.json` (`id`, `title`, `icon`, `depts`, `summary`, `file`, opsional `panduan_in_app`).
4. Pastikan nama file `.md` hanya `[A-Za-z0-9_].md` (whitelist keamanan di `docs_view.php`).

## Prinsip penulisan

- Satu paragraf **ringkasan alur**.
- Tabel **Halaman utama**: path relatif project, fungsi, role/dept tipikal.
- Baris **Monitoring / Audit** bila relevan (Control Tower, Audit Log).
- **Deep-dive**: hanya untuk RBAC, reset password, KPI SYS, impor kritis, dll.
