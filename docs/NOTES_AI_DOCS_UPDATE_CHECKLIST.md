# Checklist Update Docs — Panduan untuk AI

Saat ada perubahan di **docs/** (tambah dokumen baru, ubah link, dll.), pastikan file berikut ikut di-update.

---

## 1. Dokumen Baru di Office Pack

Jika menambah dokumen baru di `docs/officepack/` (SOP, FORM, PP, Policy, Checklist):

| Yang Di-update | File | Keterangan |
|----------------|------|-------------|
| INDEX HTML | `docs/officepack/INDEX_v3.1.html` | Tambah link ke dokumen baru di `<ul>` |
| Help Center | `docs/help_center.php` | Tambah block `if (is_file(...)) { $docs[] = [...]; }` untuk setiap dokumen |
| Register CSV | `docs/officepack/register/HRL_Docs_Register_RMI_Recommendation_v1.0.csv` | Tambah baris: doc_code, title, unit, category, scope, status, tags, description |

---

## 2. PDF & DOCX (INDEX)

`INDEX_v3.1.pdf` dan `INDEX_v3.1.docx` adalah **binary** — tidak bisa diedit langsung. Setelah `INDEX_v3.1.html` di-update:

- **Regenerate manual** dari HTML (Print to PDF, buka di Word untuk DOCX)
- Panduan: `docs/officepack/REGENERATE_INDEX_PDF_DOCX.md`

---

## 3. Proxy & Token (TIDAK perlu diubah untuk dokumen baru)

File berikut **melayani file secara generik** berdasarkan path. Tidak ada daftar dokumen hardcoded. Dokumen baru otomatis terlayani.

- `docs/_token_helper.php`
- `docs/docs_view.php`
- `docs/officepack_view.php`

**Hanya perlu diubah** jika: ubah logika auth, proxy, atau token.

---

## 4. Deploy ke NAS

Daftar file yang perlu di-copy ke NAS saat update docs: `docs/DEPLOY_DOCS_CHECKLIST.md`

---

## 5. Referensi Menu, Modul & Workflow

Saat **menambah modul baru** atau **mengubah menu/workflow**, update:

| File | Isi |
|------|-----|
| `docs/ERP_MENU_WORKFLOW_REFERENCE.md` | Menu ERP, sub-halaman per modul, O2C, permission, panduan update |

Lihat bagian **"Panduan Update Dokumen Ini"** di `ERP_MENU_WORKFLOW_REFERENCE.md` untuk langkah detail.

---

## Ringkasan Singkat

| Jenis perubahan | Update |
|-----------------|--------|
| Tambah dokumen baru (SOP/Form/PP/Policy) | INDEX_v3.1.html, help_center.php, register CSV |
| Ubah link di INDEX | INDEX_v3.1.html → regenerate PDF/DOCX manual |
| Ubah logika proxy/token | docs_view.php, officepack_view.php, _token_helper.php |
| Tambah modul/menu/workflow | ERP_MENU_WORKFLOW_REFERENCE.md |
